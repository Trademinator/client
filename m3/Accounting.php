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
        $id = $this->ledger->registerMarket($account, $symbol, $base, $quote, options: ['allow_currency_roles' => true]);
        return $this->rows('SELECT * FROM markets WHERE id=?', [$id])[0];
    }
    public function currencyMarket(string $account, string $currency, array $settings): ?array
    {
        $matches = $this->rows('SELECT * FROM markets WHERE account_id=? AND base_currency=? ORDER BY symbol', [$account,$currency]);
        $symbol = $settings['currency_markets'][$currency] ?? null;
        if ($symbol !== null) {
            if (explode('/', Normalize::symbol($symbol))[0] !== $currency) {
                throw new RuntimeException('currency_markets must select the same base currency.');
            }
            return $this->market($account, $symbol);
        }
        if ($currency === $settings['valuation_quote'] && $matches === []) {
            return null;
        }
        foreach ($matches as $market) {
            if ($market['quote_currency'] === $settings['valuation_quote']) { return $market; }
        }
        // Ownership determines the denomination of a new lot's basis, not which
        // wallet can spend it. Existing acquisitions retain their own owner.
        return $matches[0] ?? $this->market($account, $currency.'/'.$settings['valuation_quote']);
    }
    public function prepareMarkets(string $account, array $batch): void
    {
        $symbols = array_unique(array_merge(array_column($batch['orders'] ?? [], 'symbol'), array_column($batch['trades'] ?? [], 'symbol')));
        sort($symbols, SORT_STRING);
        foreach ($symbols as $symbol) { $this->market($account, $symbol); }
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
        $this->prepareMarkets($account, $batch);
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
        foreach ($batch['transfer_rates'] ?? [] as $exchangeId => $rates) {
            $op = $this->rows("SELECT * FROM operations WHERE account_id=? AND exchange_operation_id=? AND kind='deposit'", [$account,(string)$exchangeId])[0] ?? null;
            if ($op === null) { continue; }
            $meta = Numbers::decode($op['metadata_json']);
            if (isset($meta['m3']['transfer'])) { continue; }
            $changed = false;
            foreach ($rates as $currency => $rate) {
                if ($rate !== null && !isset($meta['m3']['fx_rates'][$currency])) {
                    $meta['m3']['fx_rates'][$currency] = $rate;
                    $changed = true;
                }
            }
            if ($changed) {
                $this->execute('UPDATE operations SET metadata_json=? WHERE id=?', [Numbers::json($meta),$op['id']]);
                $this->touch($account, $op['market_id']);
            }
        }
        $this->bindCashOperations($account, $settings);
        $this->bindQuoteMarkets($account, $settings);
    }
    /** Older cash rows can predate the snapshot that first introduces an inventory role. */
    private function bindCashOperations(string $account, ?array $settings = null): void
    {
        foreach ($this->rows('SELECT * FROM operations WHERE account_id=? AND market_id IS NULL', [$account]) as $op) {
            $matches = $this->rows('SELECT * FROM markets WHERE account_id=? AND base_currency=? ORDER BY symbol', [$account,$op['currency']]);
            if ($matches === []) { continue; }
            $owner = $settings === null ? $matches[0] : $this->currencyMarket($account, $op['currency'], $settings);
            $meta = Numbers::decode($op['metadata_json']);
            $meta['m3']['inventory_attribution'] = ['reason' => 'currency_inventory_role_added',
                'previous_market_id' => null, 'previous_price_basis' => $op['price_basis'],
                'previous_basis' => $meta['m3']['basis'] ?? null];
            // A cash valuation is not an acquisition price in the new owner's quote currency.
            $meta['m3']['basis'] = null;
            $this->execute('UPDATE operations SET market_id=?,price_basis=NULL,metadata_json=? WHERE id=?',
                [$owner['id'],Numbers::json($meta),$op['id']]);
            $this->touch($account, $owner['id']);
        }
    }
    private function bindQuoteMarkets(string $account, ?array $settings = null): void
    {
        foreach ($this->rows("SELECT orders.*,markets.quote_currency FROM orders JOIN markets ON markets.id=orders.market_id WHERE orders.account_id=? AND side='SELL'", [$account]) as $order) {
            $meta = Numbers::decode($order['metadata_json']);
            $matches = $this->rows('SELECT * FROM markets WHERE account_id=? AND base_currency=? ORDER BY symbol', [$account,$order['quote_currency']]);
            if ($matches === []) { continue; }
            $owner = $meta['m3']['quote_inventory_market_id'] ?? null;
            if ($owner !== null) {
                if (!in_array($owner, array_column($matches, 'id'), true)) {
                    throw new RuntimeException('Quote inventory market is missing or has a different currency.');
                }
                continue;
            }
            $owner = ($settings === null ? $matches[0] : $this->currencyMarket($account, $order['quote_currency'], $settings))['id'];
            $meta['m3']['quote_inventory_market_id'] = $owner;
            $this->execute('UPDATE orders SET metadata_json=? WHERE id=?', [Numbers::json($meta),$order['id']]);
            $this->touch($account, $owner);
            $this->touch($account, $order['market_id']);
        }
    }
    /** Recompute currency outflows from real evidence; never fabricate wallet entries. */
    public function rebuild(string $account): void
    {
        $this->db->transaction(function () use ($account): void {
            $this->bindCashOperations($account);
            $this->bindQuoteMarkets($account);
            $markets = $this->rows('SELECT * FROM markets WHERE account_id=? ORDER BY symbol', [$account]);
            $marketMap = array_column($markets, null, 'id');
            $byCurrency = [];
            foreach ($markets as $market) { $byCurrency[$market['base_currency']][] = $market['id']; }
            $old = $this->rows('SELECT * FROM buys_vs_sells WHERE account_id=? ORDER BY allocation_key', [$account]);
            $previousDebits = [];
            $previousWithdrawals = [];
            $this->execute('DELETE FROM buys_vs_sells WHERE account_id=?', [$account]);
            foreach (['orders','operations'] as $table) {
                foreach ($this->rows('SELECT * FROM '.$table.' WHERE account_id=?', [$account]) as $row) {
                    $meta = Numbers::decode($row['metadata_json']);
                    if (isset($meta['m3']['inventory_debits'])) {
                        $previousDebits[$table.':'.$row['id']] = $meta['m3']['inventory_debits'];
                        unset($meta['m3']['inventory_debits']);
                        $this->execute('UPDATE '.$table.' SET metadata_json=? WHERE id=?', [Numbers::json($meta),$row['id']]);
                    }
                    if ($table === 'operations' && $row['kind'] === 'withdrawal') {
                        $previousWithdrawals[$row['id']] = Numbers::decode($row['lot_consumptions_json']);
                    }
                }
            }
            $this->execute("UPDATE operations SET lot_consumptions_json='{}' WHERE account_id=? AND kind='withdrawal'", [$account]);
            $pool = [];
            foreach ($markets as $market) {
                foreach ($this->ledger->marketLots($market['id'], false) as $lot) {
                    if (isset($pool[$lot['source_key']])) { throw new RuntimeException('Acquisition lot has ambiguous inventory ownership.'); }
                    $lot['market_id'] = $market['id'];
                    $lot['currency'] = $market['base_currency'];
                    $origin = $lot['origin'] ?? [];
                    if (in_array($origin['kind'] ?? '', ['fill','quote_fill','order'], true)) {
                        $tradeId = $origin['trade_id'] ?? 'legacy:'.$origin['exchange_order_id'];
                        $lot['execution'] = 'order:'.$origin['order_id'].':'.$tradeId;
                    }
                    $pool[$lot['source_key']] = $lot;
                }
            }
            $events = [];
            foreach ($this->rows('SELECT * FROM orders WHERE account_id=?', [$account]) as $order) {
                $market = $marketMap[$order['market_id']];
                $meta = Numbers::decode($order['metadata_json']);
                $fills = $meta['m3']['fills'] ?? [['id' => 'legacy:'.$order['exchange_order_id'],
                    'timestamp' => Numbers::ms($order['occurred_at']), 'amount' => $order['filled_quantity'],
                    'cost' => $order['cost_amount'], 'fees' => Lots::fees($order)]];
                foreach ($fills as $fill) {
                    $event = ['at' => Numbers::iso($fill['timestamp']), 'sequence' => [$fill['id'],$market['symbol']],
                        'priority' => 1, 'table' => 'orders', 'row' => $order, 'symbol' => $market['symbol'],
                        'execution' => 'order:'.$order['id'].':'.$fill['id']];
                    $baseFee = '0'; $quoteFee = '0';
                    foreach ($fill['fees'] as $fee) {
                        if ($fee['currency'] === $market['base_currency']) { $baseFee = Numbers::add($baseFee, $fee['cost']); }
                        elseif ($fee['currency'] === $market['quote_currency']) { $quoteFee = Numbers::add($quoteFee, $fee['cost']); }
                        else {
                            $events[] = $event + ['key' => 'fee:'.$order['id'].':'.$fill['id'].':'.$fee['currency'],
                                'kind' => 'fee', 'currency' => $fee['currency'], 'quantity' => $fee['cost']];
                        }
                    }
                    if ($order['side'] === 'SELL') {
                        $events[] = $event + ['key' => 'sell:'.$order['id'].':'.$fill['id'], 'kind' => 'sell',
                            'market' => $market['id'], 'currency' => $market['base_currency'],
                            'quantity' => Numbers::add($fill['amount'], $baseFee)];
                    } else {
                        $events[] = $event + ['key' => 'quote:'.$order['id'].':'.$fill['id'], 'kind' => 'quote_spend',
                            'currency' => $market['quote_currency'], 'quantity' => Numbers::add($fill['cost'], $quoteFee)];
                    }
                }
            }
            foreach ($this->rows('SELECT * FROM operations WHERE account_id=?', [$account]) as $op) {
                $event = ['at' => $op['occurred_at'], 'sequence' => [$op['exchange_operation_id']],
                    'priority' => 2, 'table' => 'operations', 'row' => $op];
                if ($op['kind'] === 'withdrawal') {
                    $events[] = $event + ['key' => 'withdrawal:'.$op['id'], 'kind' => 'withdrawal',
                        'currency' => $op['currency'], 'quantity' => Numbers::add($op['amount'], Lots::fee($op, $op['currency']))];
                }
                foreach (Lots::fees($op) as $fee) {
                    if ($fee['currency'] !== $op['currency']) {
                        $events[] = $event + ['key' => 'fee:'.$op['id'].':'.$fee['currency'], 'kind' => 'fee',
                            'currency' => $fee['currency'], 'quantity' => $fee['cost']];
                    }
                }
            }
            usort($events, static fn ($a, $b) => [$a['at'],$a['priority'],$a['sequence'],$a['key']] <=> [$b['at'],$b['priority'],$b['sequence'],$b['key']]);
            uasort($pool, static fn ($a, $b) => [$b['acquired_at'],$b['source_key']] <=> [$a['acquired_at'],$a['source_key']]);
            $allocations = []; $withdrawals = []; $debits = [];
            $groups = [];
            foreach ($events as $event) {
                $key = $event['execution'] ?? 'operation:'.$event['row']['id'];
                $groups[$event['at']][$key][] = $event;
            }
            $completed = [];
            $available = static fn (array $lot, string $at, array $done): bool =>
                ($lot['available_at'] ?? $lot['acquired_at']) <= $at
                && (!isset($lot['execution']) || isset($done[$lot['execution']]));
            foreach ($groups as $at => $pending) {
                // Trade IDs do not establish chronology across markets. At one
                // timestamp, release an execution's credits only after all its
                // debits are funded. Deferred executions may then become funded;
                // a cycle without preexisting inventory cannot bootstrap itself.
                while ($pending !== []) {
                    $progress = false;
                    $blocked = [];
                    foreach ($pending as $execution => $executionEvents) {
                        $required = [];
                        foreach ($executionEvents as $event) {
                            if (isset($byCurrency[$event['currency']])) {
                                $required[$event['currency']] = Numbers::add($required[$event['currency']] ?? '0', $event['quantity']);
                            }
                        }
                        $required = array_filter($required, static fn ($n) => bccomp($n, '0', 24) > 0);
                        if ($required !== []) {
                            foreach ($pool as $lot) {
                                $currency = $lot['currency'];
                                if (!isset($required[$currency]) || !$available($lot, $at, $completed)) { continue; }
                                $required[$currency] = Numbers::sub($required[$currency], Numbers::min($required[$currency], $lot['quantity']));
                                if (bccomp($required[$currency], '0', 24) === 0) { unset($required[$currency]); }
                                if ($required === []) { break; }
                            }
                        }
                        if ($required !== []) { $blocked[$execution] = $required; continue; }
                        foreach ($executionEvents as $event) {
                            // Quote-only cash has no acquisition market. Its money still
                            // reconciles through expectedBalances, including all fees.
                            if (!isset($byCurrency[$event['currency']]) || bccomp($event['quantity'], '0', 24) === 0) { continue; }
                            $left = $event['quantity']; $taken = [];
                            foreach ($pool as $key => &$lot) {
                                if ($lot['currency'] !== $event['currency'] || !$available($lot, $event['at'], $completed)) { continue; }
                                $take = Numbers::min($left, $lot['quantity']);
                                if (bccomp($take, '0', 24) === 0) { continue; }
                                $lot['quantity'] = Numbers::sub($lot['quantity'], $take);
                                $left = Numbers::sub($left, $take);
                                $taken[$key] = $take;
                                if ($event['kind'] === 'sell') {
                                    $sourceId = $lot['source_id'] ?? $lot['id'];
                                    $keyId = hash('sha256', $event['key'].'|'.$key);
                                    $allocations[] = ['id' => $keyId, 'account_id' => $account, 'market_id' => $event['market'],
                                        'inventory_market_id' => $lot['market_id'], 'allocation_key' => 'm3:'.$keyId,
                                        'buy_order_id' => $lot['source_type'] === 'order' ? $sourceId : null,
                                        'buy_operation_id' => $lot['source_type'] === 'operation' ? $sourceId : null,
                                        'baseline_lot_id' => $lot['source_type'] === 'baseline' ? $sourceId : null,
                                        'sell_order_id' => $event['row']['id'], 'quantity' => $take, 'created_at' => $event['at'], 'buy_lot_key' => $key];
                                } elseif ($event['kind'] !== 'withdrawal') {
                                    $fkey = $event['table'].':'.$event['row']['id'];
                                    $debits[$fkey] ??= ['table' => $event['table'], 'row' => $event['row'], 'debits' => []];
                                    $previous = $debits[$fkey]['debits'][$lot['market_id']][$key] ?? '0';
                                    $debits[$fkey]['debits'][$lot['market_id']][$key] = Numbers::add($previous, $take);
                                }
                                if (bccomp($left, '0', 24) === 0) { break; }
                            }
                            unset($lot);
                            if (bccomp($left, '0', 24) > 0) { throw new InventoryEvidenceError($event, $event['quantity'], $left, $pool, $completed); }
                            if ($event['kind'] === 'withdrawal') { $withdrawals[$event['row']['id']] = $taken; }
                        }
                        $completed[$execution] = true;
                        unset($pending[$execution]);
                        $progress = true;
                    }
                    if (!$progress) {
                        $execution = array_key_first($blocked);
                        $currency = array_key_first($blocked[$execution]);
                        $matching = array_values(array_filter($pending[$execution], static fn ($event) => $event['currency'] === $currency));
                        $required = '0';
                        foreach ($matching as $event) { $required = Numbers::add($required, $event['quantity']); }
                        throw new InventoryEvidenceError($matching[0], $required, $blocked[$execution][$currency], $pool, $completed, count($groups[$at]) > 1);
                    }
                }
            }
            foreach ($allocations as $a) {
                $columns = array_keys($a);
                $this->execute('INSERT INTO buys_vs_sells('.implode(',', $columns).') VALUES('.implode(',', array_fill(0, count($a), '?')).')', array_values($a));
            }
            foreach ($withdrawals as $id => $taken) {
                $this->execute('UPDATE operations SET lot_consumptions_json=? WHERE id=?', [Numbers::json((object)$taken),$id]);
            }
            $currentDebits = [];
            foreach ($debits as $key => $entry) {
                $meta = Numbers::decode($entry['row']['metadata_json']);
                $meta['m3']['inventory_debits'] = $entry['debits'];
                $currentDebits[$key] = $entry['debits'];
                $this->execute('UPDATE '.$entry['table'].' SET metadata_json=? WHERE id=?', [Numbers::json($meta),$entry['row']['id']]);
            }
            $canonical = static function (array $rows): array {
                foreach ($rows as &$r) { unset($r['id'],$r['created_at']); ksort($r); } unset($r);
                usort($rows, static fn ($a, $b) => $a['allocation_key'] <=> $b['allocation_key']);
                return $rows;
            };
            $nonempty = static fn(array $rows): array => array_filter($rows, static fn(array $row): bool => $row !== []);
            // Every owner's checkpoint reflects all currency legs. Repeating
            // the same evidence must leave revisions and checkpoints intact.
            if ($canonical($old) !== $canonical($allocations) || $previousDebits != $currentDebits
                || $nonempty($previousWithdrawals) != $nonempty($withdrawals)) {
                foreach ($markets as $market) { $this->touch($account, $market['id']); }
            }
        });
    }
}
