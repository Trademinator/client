<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;

/** Unified financial history. Replays build lot allocations chronologically, never from wallet deltas. */
final class Accounting
{
    private Ledger $ledger;
    public function __construct(private Database $db)
    {
        $this->ledger = new Ledger($db);
    }
    public function rows(string $sql, array $args = []): array
    {
        $q = $this->db->pdo()->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    }
    public function execute(string $sql, array $args = []): void
    {
        $this->db->pdo()->prepare($sql)->execute($args);
    }
    public function market(string $account, string $symbol): array
    {
        [$base,$quote] = explode('/', Normalize::symbol($symbol));
        $id = $this->ledger->registerMarket($account, $symbol, $base, $quote);
        return $this->rows('SELECT * FROM markets WHERE id=?', [$id])[0];
    }
    public function currencyMarket(string $account, string $currency, array $settings): ?array
    {
        if ($currency === $settings['valuation_quote']) {
            return null;
        }
        $matches = $this->rows('SELECT * FROM markets WHERE account_id=? AND base_currency=? ORDER BY id', [$account,$currency]);
        $symbol = $settings['currency_markets'][$currency] ?? null;
        if ($symbol !== null) {
            $market = $this->market($account, $symbol);
            if ($market['base_currency'] !== $currency) {
                throw new RuntimeException('currency_markets must select the same base currency.');
            }
            return $market;
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Ambiguous inventory attribution: configure currency_markets.');
        }
        return $matches[0] ?? $this->market($account, $currency.'/'.$settings['valuation_quote']);
    }
    public function touch(string $account, ?string $market = null): void
    {
        $this->execute('UPDATE exchange_accounts SET revision=revision+1,reconciled_revision=NULL,updated_at=? WHERE id=?', [gmdate('c'),$account]);
        if ($market !== null) {
            $this->execute('UPDATE markets SET activity_revision=? WHERE id=?', [$this->db->revision($account),$market]);
        }
    }
    /** Call inside one account reconciliation transaction after all pages have been fetched. */
    public function ingest(string $account, array $batch, array $settings): void
    {
        $groups = [];
        foreach ($batch['trades'] as $trade) {
            $groups[$trade['order']][$trade['id']] = $trade;
        }
        $orders = [];
        foreach ($batch['orders'] as $order) {
            $orders[$order['id']] = $order;
        }
        foreach (array_unique(array_merge(array_keys($orders), array_keys($groups))) as $id) {
            $existing = $this->rows('SELECT * FROM orders WHERE account_id=? AND exchange_order_id=?', [$account,(string)$id])[0] ?? null;
            $meta = $existing === null ? [] : Numbers::decode($existing['metadata_json']);
            $fills = $meta['m3']['fills'] ?? [];
            if ($existing !== null && !isset($meta['m3']) && bccomp($existing['filled_quantity'], '0', 24) > 0 && empty($groups[$id])) {
                throw new RuntimeException('Legacy order needs full exchange fill history.');
            }
            foreach ($groups[$id] ?? [] as $tradeId => $trade) {
                if (isset($fills[$tradeId]) && $fills[$tradeId] !== $trade) {
                    throw new RuntimeException('Conflicting exchange trade identity.');
                }
                $fills[$tradeId] = $trade;
            }
            uasort($fills, static fn ($a, $b) => [$a['timestamp'],$a['id']] <=> [$b['timestamp'],$b['id']]);
            $order = $orders[$id] ?? null;
            if ($order === null) {
                throw new RuntimeException('Trade requires authoritative order details.');
            }
            // Never replace already observed lifecycle details with a history-only summary.
            if ($order['status'] === 'history_only' && $existing !== null && $existing['status'] !== 'history_only') {
                if (in_array($existing['status'], ['open','partially_filled'], true)) {
                    throw new RuntimeException('Historical trade evidence cannot resolve a locally open or uncertain order.');
                }
                $order = array_replace($order, ['status' => $existing['status'], 'amount' => $existing['quantity'],
                    'type' => $existing['order_type'], 'price' => $existing['price'],
                    'client_order_id' => $meta['m3']['client_order_id'] ?? null]);
                unset($order['history_evidence']);
            }
            $market = $this->market($account, $order['symbol']);
            $filled = '0';
            $cost = '0';
            $fees = [];
            foreach ($fills as $fill) {
                if ($fill['symbol'] !== $order['symbol'] || $fill['side'] !== $order['side']) {
                    throw new RuntimeException('Trade/order identity mismatch.');
                }
                $filled = Numbers::add($filled, $fill['amount']);
                $cost = Numbers::add($cost, $fill['cost']);
                foreach ($fill['fees'] as $fee) {
                    $fees[$fee['currency']] = Numbers::add($fees[$fee['currency']] ?? '0', $fee['cost']);
                }
            }
            if (bccomp($filled, $order['filled'], 24) !== 0) {
                throw new RuntimeException('Order fills are incomplete; reconciliation required.');
            }
            if ($order['cost'] !== null && bccomp($cost, $order['cost'], 24) !== 0) {
                throw new RuntimeException('Order cumulative cost differs from its fills.');
            }
            ksort($fees);
            $feeRows = array_map(static fn ($c, $n) => ['currency' => $c,'cost' => $n], array_keys($fees), array_values($fees));
            $price = $order['price'];
            if ($price === null || bccomp($price, '0', 24) === 0) {
                $price = bccomp($filled, '0', 24) > 0 ? Numbers::decimal(bcdiv($cost, $filled, 24)) : null;
            }
            if ($price === null) {
                throw new RuntimeException('Unpriced open market order: wait for exchange confirmation.');
            }
            $meta['m3'] = array_replace($meta['m3'] ?? [], ['origin' => $meta['m3']['origin'] ?? 'manual', 'client_order_id' => $order['client_order_id'],
                'submission' => 'confirmed','fills' => $fills,'fees' => $feeRows]);
            if ($order['status'] === 'history_only') {
                $meta['m3']['history_evidence'] = $order['history_evidence'];
            } else {
                unset($meta['m3']['history_evidence']);
            }
            $this->ledger->recordOrder(['account_id' => $account,'market_id' => $market['id'],'exchange_order_id' => (string)$id,
                'side' => $order['side'],'order_type' => $order['type'],'status' => $order['status'],'quantity' => $order['amount'],
                'filled_quantity' => $filled,'price' => $price,'average_price' => bccomp($filled, '0', 24) > 0 ? Numbers::decimal(bcdiv($cost, $filled, 24)) : $price,
                'cost_amount' => $cost,'occurred_at' => Numbers::iso($order['timestamp']),'updated_at' => Numbers::iso($batch['at_ms']),
                'metadata' => $meta]);
        }
        foreach ($batch['operations'] as $op) {
            if (($op['fee_evidence']['source'] ?? null) === 'operator_bitso_lightning_invoice'
                && ($op['fee_evidence']['operator_confirmation']['account_id'] ?? null) !== $account) {
                throw new RuntimeException('Bitso Lightning operator confirmation belongs to another account.');
            }
            $existing = $this->rows('SELECT * FROM operations WHERE account_id=? AND exchange_operation_id=?', [$account,$op['id']])[0] ?? null;
            if ($existing !== null) {
                $meta = Numbers::decode($existing['metadata_json']);
                $previous = $meta['m3']['evidence'] ?? null;
                if ($previous !== null && $previous !== $op) {
                    // Exchanges can enrich hash/address after completion, but never silently rewrite money.
                    foreach (['id','kind','currency','amount','fees','timestamp'] as $key) {
                        if ($previous[$key] !== $op[$key]) {
                            throw new RuntimeException('Conflicting transfer financial evidence.');
                        }
                    }
                    if (isset($op['fee_evidence']) || isset($previous['fee_evidence'])) {
                        $meta['m3']['fee_evidence'] ??= $previous['fee_evidence'] ?? $op['fee_evidence'];
                    }
                    $meta['m3']['evidence'] = $op;
                    $this->execute('UPDATE operations SET metadata_json=? WHERE id=?', [Numbers::json($meta),$existing['id']]);
                    $this->touch($account, $existing['market_id']);
                }
                continue;
            }
            $market = $this->currencyMarket($account, $op['currency'], $settings);
            $basis = $batch['prices'][$op['id']] ?? null;
            $this->ledger->recordOperation(['account_id' => $account,'market_id' => $market['id'] ?? null,'exchange_operation_id' => $op['id'],
                'kind' => $op['kind'],'currency' => $op['currency'],'amount' => $op['amount'],'price_basis' => $basis['price'] ?? null,
                'occurred_at' => Numbers::iso($op['timestamp']),
                'metadata' => ['m3' => ['origin' => 'exchange','evidence' => $op,'fees' => $op['fees'],'basis' => $basis,'fx_rates' => $batch['transfer_rates'][$op['id']] ?? []]
                    + (isset($op['fee_evidence']) ? ['fee_evidence' => $op['fee_evidence']] : [])]]);
        }
    }
    /** Recompute attribution from all financial evidence; no fabricated balancing entries. */
    public function rebuild(string $account): void
    {
        $this->db->transaction(function () use ($account): void {
            $markets = $this->rows('SELECT * FROM markets WHERE account_id=? ORDER BY id', [$account]);
            $marketMap = array_column($markets, null, 'id');
            $events = [];
            $pool = [];
            $feesUpdates = [];
            $old = $this->rows('SELECT * FROM buys_vs_sells WHERE account_id=? ORDER BY allocation_key', [$account]);
            $this->execute('DELETE FROM buys_vs_sells WHERE account_id=?', [$account]);
            foreach (['orders','operations'] as $table) {
                foreach ($this->rows('SELECT * FROM '.$table.' WHERE account_id=?', [$account]) as $row) {
                    $meta = Numbers::decode($row['metadata_json']);
                    if (isset($meta['m3']['inventory_debits'])) {
                        unset($meta['m3']['inventory_debits']);
                        $this->execute('UPDATE '.$table.' SET metadata_json=? WHERE id=?', [Numbers::json($meta),$row['id']]);
                    }
                }
            }
            $this->execute("UPDATE operations SET lot_consumptions_json='{}' WHERE account_id=? AND kind='withdrawal'", [$account]);
            foreach ($markets as $market) {
                foreach ($this->ledger->marketLots($market['id']) as $lot) {
                    $lot['market_id'] = $market['id'];
                    $pool[$lot['source_key']] = $lot;
                }
            }
            foreach ($this->rows('SELECT * FROM orders WHERE account_id=?', [$account]) as $order) {
                $market = $marketMap[$order['market_id']];
                $meta = Numbers::decode($order['metadata_json']);
                if ($order['side'] === 'SELL') {
                    if (isset($meta['m3']['fills'])) {
                        foreach ($meta['m3']['fills'] as $fill) {
                            $quantity = $fill['amount'];
                            foreach ($fill['fees'] as $fee) {
                                if ($fee['currency'] === $market['base_currency']) {
                                    $quantity = Numbers::add($quantity, $fee['cost']);
                                }
                            }
                            $events[] = ['at' => Numbers::iso($fill['timestamp']),'key' => 'sell:'.$order['id'].':'.$fill['id'],'kind' => 'sell','row' => $order,'market' => $market['id'],'quantity' => $quantity];
                        }
                    } else {
                        $events[] = ['at' => $order['occurred_at'],'key' => 'sell:'.$order['id'],'kind' => 'sell','row' => $order,'market' => $market['id'],'quantity' => Numbers::add($order['filled_quantity'], Lots::fee($order, $market['base_currency']))];
                    }
                }
                $feeEvents = $meta['m3']['fills'] ?? [['id' => 'legacy','timestamp' => Numbers::ms($order['occurred_at']),'fees' => Lots::fees($order)]];
                foreach ($feeEvents as $fill) {
                    foreach ($fill['fees'] as $fee) {
                        if (!in_array($fee['currency'], [$market['base_currency'],$market['quote_currency']], true)) {
                            $events[] = ['at' => Numbers::iso($fill['timestamp']),'key' => 'fee:'.$order['id'].':'.$fill['id'].':'.$fee['currency'],'kind' => 'fee','table' => 'orders','row' => $order,'currency' => $fee['currency'],'quantity' => $fee['cost']];
                        }
                    }
                }
            }
            foreach ($this->rows('SELECT * FROM operations WHERE account_id=?', [$account]) as $op) {
                if ($op['kind'] === 'withdrawal' && $op['market_id'] !== null) {
                    $events[] = ['at' => $op['occurred_at'],'key' => 'withdrawal:'.$op['id'],'kind' => 'withdrawal','row' => $op,'market' => $op['market_id'],'quantity' => Numbers::add($op['amount'], Lots::fee($op, $op['currency']))];
                }
                foreach (Lots::fees($op) as $fee) {
                    if ($fee['currency'] !== $op['currency']) {
                        $events[] = ['at' => $op['occurred_at'],'key' => 'fee:'.$op['id'].':'.$fee['currency'],'kind' => 'fee','table' => 'operations','row' => $op,'currency' => $fee['currency'],'quantity' => $fee['cost']];
                    }
                }
            }
            usort($events, static fn ($a, $b) => [$a['at'],$a['key']] <=> [$b['at'],$b['key']]);
            uasort($pool, static fn ($a, $b) => [$b['acquired_at'],$b['source_key']] <=> [$a['acquired_at'],$a['source_key']]);
            $allocations = [];
            $withdrawals = [];
            foreach ($events as $event) {
                $left = $event['quantity'];
                $taken = [];
                $targets = isset($event['market']) ? [$event['market']] : array_column(array_filter($markets, static fn ($m) => $m['base_currency'] === $event['currency']), 'id');
                if ($targets === [] && $event['kind'] === 'fee') {
                    continue;
                } // Fiat fee has no inventory lot.
                foreach ($pool as $key => &$lot) {
                    if (!in_array($lot['market_id'], $targets, true) || ($lot['available_at'] ?? $lot['acquired_at']) > $event['at']) {
                        continue;
                    }
                    $take = Numbers::min($left, $lot['quantity']);
                    if (bccomp($take, '0', 24) === 0) {
                        continue;
                    }
                    $lot['quantity'] = Numbers::sub($lot['quantity'], $take);
                    $left = Numbers::sub($left, $take);
                    $taken[$key] = $take;
                    if ($event['kind'] === 'sell') {
                        $sourceId = $lot['source_id'] ?? $lot['id'];
                        $keyId = hash('sha256', $event['key'].'|'.$key);
                        $allocations[] = ['id' => $keyId,'account_id' => $account,'market_id' => $event['market'],'allocation_key' => 'm3:'.$keyId,
                            'buy_order_id' => $lot['source_type'] === 'order' ? $sourceId : null,'buy_operation_id' => $lot['source_type'] === 'operation' ? $sourceId : null,
                            'baseline_lot_id' => $lot['source_type'] === 'baseline' ? $sourceId : null,'sell_order_id' => $event['row']['id'],'quantity' => $take,'created_at' => $event['at'],'buy_lot_key' => $key];
                    } elseif ($event['kind'] === 'fee') {
                        $fkey = $event['table'].':'.$event['row']['id'];
                        $feesUpdates[$fkey] ??= ['table' => $event['table'],'row' => $event['row'],'debits' => []];
                        $previous = $feesUpdates[$fkey]['debits'][$lot['market_id']][$key] ?? '0';
                        $feesUpdates[$fkey]['debits'][$lot['market_id']][$key] = Numbers::add($previous, $take);
                    }
                    if (bccomp($left, '0', 24) === 0) {
                        break;
                    }
                }
                unset($lot);
                if (bccomp($left, '0', 24) > 0) {
                    throw new RuntimeException('Unattributed outflow: history or acquisition inventory is incomplete.');
                }
                if ($event['kind'] === 'withdrawal') {
                    $withdrawals[$event['row']['id']] = $taken;
                }
            }
            foreach ($allocations as $a) {
                $columns = array_keys($a);
                $this->execute('INSERT INTO buys_vs_sells('.implode(',', $columns).') VALUES('.implode(',', array_fill(0, count($a), '?')).')', array_values($a));
            }
            foreach ($withdrawals as $id => $taken) {
                $this->execute('UPDATE operations SET lot_consumptions_json=? WHERE id=?', [Numbers::json((object)$taken),$id]);
            }
            foreach ($feesUpdates as $f) {
                $meta = Numbers::decode($f['row']['metadata_json']);
                $meta['m3']['inventory_debits'] = $f['debits'];
                $this->execute('UPDATE '.$f['table'].' SET metadata_json=? WHERE id=?', [Numbers::json($meta),$f['row']['id']]);
            }
            // Allocation updates invalidate checkpoints; duplicate histories remain idempotent.
            $canonical = static function (array $rows): array {
                foreach ($rows as &$r) {
                    unset($r['id'],$r['created_at']);
                    ksort($r);
                }unset($r);
                usort($rows, static fn ($a, $b) => $a['allocation_key'] <=> $b['allocation_key']);
                return $rows;
            };
            if ($canonical($old) !== $canonical($allocations)) {
                foreach ($markets as $market) {
                    $this->touch($account, $market['id']);
                }
            }
        });
    }
}
