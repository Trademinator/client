<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;

final class Reconciler
{
    private Accounting $accounting;
    private Ledger $ledger;
    public function __construct(private Database $db)
    {
        $this->accounting = new Accounting($db);
        $this->ledger = new Ledger($db);
    }
    public function invalidate(string $account): void
    {
        $this->accounting->execute('UPDATE exchange_accounts SET reconciled_revision=NULL,reconciled_at=NULL WHERE id=?', [$account]);
    }
    public function context(string $account, array $settings): array
    {
        $row = $this->accounting->rows('SELECT * FROM exchange_accounts WHERE id=?', [$account])[0] ?? throw new RuntimeException('Unknown exchange account.');
        $baseline = Numbers::decode($row['hard_t0_json']);
        $since = max($settings['history_since_ms'], isset($baseline['cutoff_ms']) ? (int)$baseline['cutoff_ms'] + 1 : 0);
        $orders = [];
        foreach ($this->accounting->rows("SELECT * FROM orders WHERE account_id=? AND status IN ('open','partially_filled')", [$account]) as $order) {
            $meta = Numbers::decode($order['metadata_json']);
            if (($meta['m3']['submission'] ?? 'confirmed') === 'uncertain') {
                continue;
            }
            $market = $this->accounting->rows('SELECT symbol FROM markets WHERE id=?', [$order['market_id']])[0];
            $orders[$order['exchange_order_id']] = $market['symbol'];
        }
        return ['since_ms' => $since,'known_orders' => $orders,'symbols' => array_column($this->accounting->rows('SELECT symbol FROM markets WHERE account_id=?', [$account]), 'symbol')];
    }
    public function apply(string $account, array $batch, array $settings): array
    {
        $this->invalidate($account);
        if (($batch['complete'] ?? false) !== true) {
            throw new RuntimeException('Incomplete exchange snapshot.');
        }
        $result = $this->db->transaction(function () use ($account, $batch, $settings): array {
            $this->accounting->ingest($account, $batch, $settings);
            foreach ($this->accounting->rows('SELECT * FROM markets WHERE account_id=?', [$account]) as $market) {
                $definition = $batch['markets'][$market['symbol']] ?? null;
                if (is_array($definition)) {
                    $quantity = Numbers::decimal($definition['limits']['amount']['min'] ?? '0');
                    $notional = Numbers::decimal($definition['limits']['cost']['min'] ?? '0');
                    if ($quantity !== $market['min_quantity'] || $notional !== $market['min_notional']) {
                        $this->accounting->execute('UPDATE markets SET min_quantity=?,min_notional=? WHERE id=?', [$quantity,$notional,$market['id']]);
                        $this->accounting->touch($account, $market['id']);
                    }
                }
            }
            $this->accounting->rebuild($account);
            // Resolve only a unique explicit clientOrderId match. Silence is never evidence of rejection.
            foreach ($this->accounting->rows('SELECT * FROM orders WHERE account_id=?', [$account]) as $intent) {
                $meta = Numbers::decode($intent['metadata_json']);
                if (($meta['m3']['submission'] ?? '') !== 'uncertain') {
                    continue;
                }
                $matches = array_filter($batch['orders'], static fn ($o) => $o['client_order_id'] !== null && $o['client_order_id'] === ($meta['m3']['client_order_id'] ?? null));
                if (count($matches) === 1) {
                    $confirmed = array_values($matches)[0];
                    $meta['m3']['submission'] = 'resolved';
                    $meta['m3']['resolved_exchange_order_id'] = $confirmed['id'];
                    $this->accounting->execute("UPDATE orders SET status='cancelled',metadata_json=? WHERE id=?", [Numbers::json($meta),$intent['id']]);
                    $this->accounting->touch($account, $intent['market_id']);
                }
            }
            $totals = array_map(static fn ($b) => $b['total'], $batch['balances']);
            $expected = $this->ledger->expectedBalances($account);
            $actual = Ledger::normalizeBalances($totals);
            $discrepancies = [];
            foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $currency) {
                if (bccomp($expected[$currency] ?? '0', $actual[$currency] ?? '0', 24) !== 0) {
                    $discrepancies[$currency] = ['expected' => $expected[$currency] ?? '0','actual' => $actual[$currency] ?? '0'];
                }
            }
            if ($discrepancies !== []) {
                return ['reconciled' => false,'discrepancies' => $discrepancies];
            }
            $this->ledger->reconcileAccount($account, $totals);
            foreach ($this->accounting->rows('SELECT id FROM markets WHERE account_id=?', [$account]) as $market) {
                $state = $this->ledger->marketState($market['id']);
                if ($state['open_orders'] === 0 && !$state['dust'] && $state['acquisition_cost_known'] && bccomp($state['unmatched_sell_quantity'], '0', 24) === 0) {
                    $this->ledger->createCheckpoint($market['id']);
                }
            }
            return ['reconciled' => true,'discrepancies' => []];
        });
        return $result + ['at_ms' => $batch['at_ms'],'balances' => $batch['balances']];
    }
    /** Future execution must persist this BEFORE submitting; M3 itself never submits. */
    public function uncertain(array $intent): string
    {
        return $this->db->transaction(function () use ($intent): string {
            $clientId = Normalize::id($intent['client_order_id']);
            $account = $intent['account_id'];
            $market = $intent['market_id'];
            if ($this->ledger->openOrdersCount($account, $market) > 0) {
                throw new RuntimeException('An open or uncertain order already blocks this market.');
            }
            $id = $this->ledger->recordOrder(['account_id' => $account,'market_id' => $market,'exchange_order_id' => 'intent:'.$clientId,'side' => $intent['side'],'quantity' => $intent['quantity'],'price' => $intent['price'],'status' => 'open',
                'metadata' => ['m3' => ['origin' => 'client','submission' => 'uncertain','client_order_id' => $clientId,'fills' => [],'fees' => []]]]);
            $this->invalidate($account);
            return $id;
        });
    }
    public function adjustment(string $account, string $currency, string $actual, ?string $price, string $reason, string $token): array
    {
        return $this->db->transaction(function () use ($account, $currency, $actual, $price, $reason, $token): array {
            if (trim($reason) === '') {
                throw new RuntimeException('Explicit adjustment requires an audit reason.');
            }
            $expected = $this->ledger->expectedBalances($account)[$currency] ?? '0';
            $delta = bcsub($actual, $expected, 24);
            if (bccomp($delta, '0', 24) === 0) {
                throw new RuntimeException('No discrepancy remains.');
            }
            $kind = str_starts_with($delta, '-') ? 'withdrawal' : 'deposit';
            $amount = ltrim($delta, '-');
            $markets = $this->accounting->rows('SELECT * FROM markets WHERE account_id=? AND base_currency=?', [$account,$currency]);
            if (count($markets) > 1) {
                throw new RuntimeException('Resolve ambiguous inventory attribution before adjustment.');
            }
            $market = $markets[0] ?? null;
            $op = $this->ledger->recordOperation(['account_id' => $account,'market_id' => $market['id'] ?? null,'exchange_operation_id' => 'user-adjustment:'.Ledger::uuid(),
                'kind' => $kind,'currency' => $currency,'amount' => $amount,'price_basis' => $price === null ? null : Ledger::decimal($price, true),
                'metadata' => ['m3' => ['origin' => 'user_adjustment','fees' => [],'approval' => ['token' => $token,'reason' => $reason,'at' => gmdate('c'),'expected' => $expected,'actual' => $actual]]]]);
            $this->accounting->rebuild($account);
            $this->invalidate($account);
            return ['operation_id' => $op,'currency' => $currency,'kind' => $kind,'amount' => $amount,'reconciliation_required' => true];
        });
    }
    public function basis(string $operation, string $price, string $reason): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A basis correction needs an audit reason.');
        }
        $price = Ledger::decimal($price, true);
        return $this->db->transaction(function () use ($operation, $price, $reason): array {
            $row = $this->accounting->rows("SELECT * FROM operations WHERE id=? AND kind='deposit'", [$operation])[0] ?? throw new RuntimeException('Unknown deposit.');
            $meta = Numbers::decode($row['metadata_json']);
            if (isset($meta['m3']['transfer'])) {
                throw new RuntimeException('Linked transfer basis comes from original BUY lots.');
            }
            $meta['m3']['basis_audit'][] = ['at' => gmdate('c'),'previous_basis' => $row['price_basis'],'reason' => $reason,'price' => $price];
            $meta['m3']['basis'] = ['source' => 'user','estimated' => true,'price' => $price,'reason' => $reason];
            $this->accounting->execute('UPDATE operations SET price_basis=?,metadata_json=? WHERE id=?', [$price,Numbers::json($meta),$operation]);
            $this->accounting->touch($row['account_id'], $row['market_id']);
            $this->accounting->rebuild($row['account_id']);
            return ['operation_id' => $operation,'price_basis' => $price,'reconciliation_required' => true];
        });
    }
}
