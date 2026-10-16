<?php

declare(strict_types=1);

namespace Trademinator\Client\M1;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/** Durable accounting primitives for fixtures. M1 never places an exchange order. */
final class Ledger
{
    public const SCALE = 24;
    private const TABLES = ['markets', 'orders', 'operations', 'buys_vs_sells', 'checkpoints', 'signal_decisions'];

    public function __construct(private readonly Database $database) {}
    public function database(): Database { return $this->database; }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function decimal(mixed $value, bool $positive = false): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d{1,40}(?:\.\d{1,24})?$/D', (string) $value)) {
            throw new InvalidArgumentException('Amounts must be nonnegative decimal strings with at most 24 fractional places; floats are forbidden.');
        }
        $value = self::trimDecimal((string) $value);
        if ($positive && bccomp($value, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }
        return $value;
    }

    private static function trimDecimal(string $value): string
    {
        if (str_contains($value, '.')) { $value = rtrim(rtrim($value, '0'), '.'); }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-0');
        if ($value === '' || str_starts_with($value, '.')) { $value = '0' . $value; }
        return ($negative && $value !== '0' ? '-' : '') . $value;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function decode(?string $json): array
    {
        return $json === null ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function currency(string $currency): string
    {
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/D', $currency)) { throw new InvalidArgumentException('Invalid currency identifier.'); }
        return $currency;
    }

    private static function timestamp(?string $value = null): string
    {
        $date = new DateTimeImmutable($value ?? 'now');
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetch() ?: null;
    }

    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    private function execute(string $sql, array $params = []): void
    {
        $this->database->pdo()->prepare($sql)->execute($params);
    }

    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->execute('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')', array_values($row));
    }

    private function account(string $id): array
    {
        return $this->one('SELECT * FROM exchange_accounts WHERE id=?', [$id]) ?? throw new RuntimeException('Unknown exchange account.');
    }

    private function market(string $id, ?string $accountId = null): array
    {
        $market = $this->one('SELECT * FROM markets WHERE id=?', [$id]) ?? throw new RuntimeException('Unknown market.');
        if ($accountId !== null && $market['account_id'] !== $accountId) { throw new RuntimeException('Market belongs to another exchange account.'); }
        return $market;
    }

    private function touch(string $accountId, ?string $marketId = null): int
    {
        $this->execute('UPDATE exchange_accounts SET revision=revision+1,updated_at=? WHERE id=?', [self::timestamp(), $accountId]);
        $revision = $this->database->revision($accountId);
        if ($marketId !== null) { $this->execute('UPDATE markets SET activity_revision=? WHERE id=? AND account_id=?', [$revision, $marketId, $accountId]); }
        return $revision;
    }

    public function createAccount(string $exchange, string $label, ?string $id = null, array $openingBalances = []): string
    {
        if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $exchange) || trim($label) === '') { throw new InvalidArgumentException('Exchange and account label are required.'); }
        $id ??= self::uuid();
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id)) { throw new InvalidArgumentException('Account ID must be a UUID.'); }
        $balances = self::normalizeBalances($openingBalances);
        $now = self::timestamp();
        $this->database->transaction(function () use ($id, $exchange, $label, $balances, $now): void {
            $this->insert('exchange_accounts', ['id' => $id, 'exchange' => $exchange, 'label' => $label,
                'opening_balances_json' => self::json((object) $balances), 'wallet_balances_json' => self::json((object) $balances),
                'created_at' => $now, 'updated_at' => $now]);
        });
        return $id;
    }

    public function registerMarket(string $accountId, string $symbol, string $base, string $quote, ?string $id = null, array $options = []): string
    {
        self::currency($base); self::currency($quote);
        if ($base === $quote || $symbol !== $base . '/' . $quote) { throw new InvalidArgumentException('Market symbol must match BASE/QUOTE.'); }
        return $this->database->transaction(function () use ($accountId, $symbol, $base, $quote, $id, $options): string {
            $account = $this->account($accountId);
            if ($existing = $this->one('SELECT * FROM markets WHERE account_id=? AND symbol=?', [$accountId, $symbol])) { return $existing['id']; }
            if (($options['allow_currency_roles'] ?? false) !== true && $this->one('SELECT id FROM markets WHERE account_id=? AND (base_currency=? OR quote_currency=?)', [$accountId, $quote, $base])) {
                throw new RuntimeException('M1 does not yet support spending one market inventory as another market quote currency.');
            }
            $marketId = $id ?? self::uuid();
            $lots = $options['opening_lots'] ?? [];
            if (!is_array($lots)) { throw new InvalidArgumentException('opening_lots must be an array.'); }
            // An initial wallet holding has unknown cost unless the operator supplied its basis.
            $opening = self::decode($account['opening_balances_json']);
            $firstInventoryMarket = !$this->one('SELECT id FROM markets WHERE account_id=? AND base_currency=?', [$accountId, $base]);
            if ($lots === [] && $firstInventoryMarket) {
                $seed = null;
                $hardPoint = self::decode($account['hard_t0_json']);
                if (($hardPoint['kind'] ?? null) === 'HardH0') {
                    // This is cash carried by H0, not an additional original deposit.
                    // Full archive replay replaces it with the original wallet seed.
                    $availableAt = \Trademinator\Client\M3\Numbers::iso((int) $hardPoint['cutoff_ms']);
                    $seed = ['id' => self::uuid(),'quantity' => $opening[$base] ?? '0','unit_cost' => null,
                        'origin' => ['kind' => 'hard_point_cash','account_id' => $accountId,
                            'hardpoint_id' => $hardPoint['hardpoint_id'],'cutoff_ms' => (int) $hardPoint['cutoff_ms']],
                        'acquired_at' => $availableAt,'available_at' => $availableAt];
                    // Retain the descriptor even when H0 cash was zero: full
                    // history may still have spent an original opening balance.
                    $hardPoint['cash_inventory_seeds'][$marketId] = $seed;
                    $this->execute('UPDATE exchange_accounts SET hard_t0_json=? WHERE id=?', [self::json($hardPoint),$accountId]);
                }
                if (isset($opening[$base])) {
                    $lots[] = $seed ?? ['id' => self::uuid(),'quantity' => $opening[$base],'unit_cost' => null,
                        'origin' => ['kind' => 'initial_wallet'],'acquired_at' => $account['created_at']];
                }
            }
            foreach ($lots as &$lot) {
                $lot['id'] ??= self::uuid();
                $lot['quantity'] = self::decimal($lot['quantity'], true);
                $lot['unit_cost'] = isset($lot['unit_cost']) ? self::decimal($lot['unit_cost']) : null;
                $lot['cost_amount'] = $lot['unit_cost'] === null ? null : self::trimDecimal(bcmul($lot['quantity'], $lot['unit_cost'], self::SCALE));
                $lot['acquisition_quantity'] = $lot['quantity'];
                $lot['acquisition_cost'] = $lot['cost_amount'];
                $lot['origin'] ??= ['kind' => 'initial_wallet'];
                $lot['acquired_at'] = self::timestamp($lot['acquired_at'] ?? $account['created_at']);
            }
            unset($lot);
            $this->insert('markets', ['id' => $marketId, 'account_id' => $accountId, 'symbol' => $symbol,
                'base_currency' => $base, 'quote_currency' => $quote,
                'min_quantity' => self::decimal($options['min_quantity'] ?? '0'),
                'min_notional' => self::decimal($options['min_notional'] ?? '0'),
                'reference_price' => self::decimal($options['reference_price'] ?? '0'),
                'baseline_lots_json' => self::json($lots), 'created_at' => self::timestamp()]);
            $this->touch($accountId, $marketId);
            return $marketId;
        });
    }

    public function recordOrder(array $input): string
    {
        return $this->database->transaction(function () use ($input): string {
            $accountId = (string) ($input['account_id'] ?? '');
            $market = $this->market((string) ($input['market_id'] ?? ''), $accountId);
            $exchangeId = (string) ($input['exchange_order_id'] ?? '');
            if ($exchangeId === '' || strlen($exchangeId) > 255) { throw new InvalidArgumentException('Exchange order ID required.'); }
            $historyOnly = ($input['status'] ?? '') === 'history_only';
            $filled = self::decimal($input['filled_quantity'] ?? '0');
            if ($historyOnly) {
                if (($input['quantity'] ?? null) !== null || ($input['remaining_quantity'] ?? null) !== null
                    || ($input['order_type'] ?? '') !== 'UNKNOWN' || bccomp($filled, '0', self::SCALE) <= 0
                    || ($input['metadata']['m3']['history_evidence']['source'] ?? '') !== 'bitso:fetch_order_trades') {
                    throw new RuntimeException('Historical executions require verified fill evidence and unknown original order details.');
                }
                $quantity = $remaining = null;
            } else {
                $quantity = self::decimal($input['quantity'] ?? '', true);
                $remaining = self::decimal($input['remaining_quantity'] ?? bcsub($quantity, $filled, self::SCALE));
                if (bccomp(bcadd($filled, $remaining, self::SCALE), $quantity, self::SCALE) !== 0) { throw new RuntimeException('Filled plus remaining must equal order quantity.'); }
            }
            $side = strtoupper((string) ($input['side'] ?? ''));
            $status = (string) ($input['status'] ?? (bccomp($filled, '0', self::SCALE) === 0 ? 'open' : (bccomp($remaining, '0', self::SCALE) === 0 ? 'filled' : 'partially_filled')));
            if (!in_array($side, ['BUY','SELL'], true) || !in_array($status, ['open','partially_filled','filled','cancelled','rejected','expired','history_only'], true)) { throw new InvalidArgumentException('Invalid order side or status.'); }
            if (($status === 'filled' && bccomp($remaining, '0', self::SCALE) !== 0)
                || ($status === 'partially_filled' && (bccomp($filled, '0', self::SCALE) === 0 || bccomp($remaining, '0', self::SCALE) === 0))
                || ($status === 'open' && bccomp($filled, '0', self::SCALE) !== 0)) { throw new RuntimeException('Order status disagrees with its cumulative fill quantities.'); }
            $price = self::decimal($input['price'] ?? '', true);
            $average = self::decimal($input['average_price'] ?? $price, true);
            $fee = self::decimal($input['fee_amount'] ?? '0');
            $feeCurrency = isset($input['fee_currency']) ? self::currency((string) $input['fee_currency']) : null;
            if (bccomp($fee, '0', self::SCALE) > 0 && $feeCurrency === null) { throw new InvalidArgumentException('A fee currency is required for nonzero fees.'); }
            if (empty($input['metadata']['m3']) && bccomp($fee, '0', self::SCALE) > 0 && !in_array($feeCurrency, [$market['base_currency'], $market['quote_currency']], true)) { throw new RuntimeException('M1 supports order fees only in the market base or quote currency; third-currency inventory attribution is not implemented.'); }
            if ($side === 'BUY' && $feeCurrency === $market['base_currency'] && bccomp($fee, $filled, self::SCALE) > 0) { throw new RuntimeException('Base fee exceeds purchased quantity.'); }
            $cost = self::decimal($input['cost_amount'] ?? bcmul($filled, $average, self::SCALE));
            if ((bccomp($filled, '0', self::SCALE) === 0) !== (bccomp($cost, '0', self::SCALE) === 0)) { throw new RuntimeException('Order cumulative cost must be zero exactly when its filled quantity is zero.'); }
            $row = ['account_id' => $accountId, 'market_id' => $market['id'], 'exchange_order_id' => $exchangeId,
                'side' => $side, 'order_type' => strtoupper((string) ($input['order_type'] ?? 'LIMIT')), 'status' => $status,
                'quantity' => $quantity, 'filled_quantity' => $filled, 'remaining_quantity' => $remaining,
                'price' => $price, 'average_price' => $average,
                'cost_amount' => $cost,
                'fee_amount' => $fee, 'fee_currency' => $feeCurrency,
                'occurred_at' => self::timestamp($input['occurred_at'] ?? null),
                'updated_at' => self::timestamp($input['updated_at'] ?? null), 'metadata_json' => self::json($input['metadata'] ?? new \stdClass())];
            $existing = $this->one('SELECT * FROM orders WHERE account_id=? AND exchange_order_id=?', [$accountId, $exchangeId]);
            if ($existing) {
                $identityFields = $existing['status'] === 'history_only' && !$historyOnly ? ['market_id','side'] : ['market_id','side','quantity','order_type'];
                foreach ($identityFields as $field) {
                    if ($existing[$field] !== $row[$field]) { throw new RuntimeException('An exchange order identity cannot change.'); }
                }
                if (bccomp($filled, $existing['filled_quantity'], self::SCALE) < 0) { throw new RuntimeException('Cumulative order fills cannot decrease.'); }
                $same = true;
                foreach (['status','quantity','order_type','filled_quantity','remaining_quantity','price','average_price','cost_amount','fee_amount','fee_currency','metadata_json'] as $field) { $same = $same && $existing[$field] === $row[$field]; }
                if ($same) { return $existing['id']; }
                if (in_array($existing['status'], ['filled','cancelled','rejected','expired'], true) && in_array($status, ['open','partially_filled'], true)) { throw new RuntimeException('Closed order cannot reopen.'); }
                $row['occurred_at'] = $existing['occurred_at'];
                if ($row['updated_at'] < $existing['updated_at']) { throw new RuntimeException('Out-of-order order update rejected.'); }
                $observations = self::decode($existing['observations_json']);
                $observations[] = $this->orderObservation($row);
                $row['observations_json'] = self::json($observations);
                $sets = implode(',', array_map(static fn ($key) => $key . '=?', array_keys($row)));
                $this->execute('UPDATE orders SET ' . $sets . ' WHERE id=?', [...array_values($row), $existing['id']]);
                $id = $existing['id'];
                // Corrections must never reduce already committed lot allocations.
                $this->marketState($market['id']);
            } else {
                $id = self::uuid();
                $row['observations_json'] = self::json([$this->orderObservation($row)]);
                $this->insert('orders', ['id' => $id] + $row);
            }
            $this->touch($accountId, $market['id']);
            return $id;
        });
    }

    /** Immutable accepted cumulative observations; these are not fabricated exchange fill IDs. */
    private function orderObservation(array $row): array
    {
        $observation = array_intersect_key($row, array_flip(['exchange_order_id','side','order_type','status','quantity','filled_quantity','remaining_quantity','price','average_price','cost_amount','fee_amount','fee_currency','occurred_at','updated_at']));
        $observation['accepted_at'] = self::timestamp();
        $observation['source_metadata'] = self::decode($row['metadata_json']);
        return $observation;
    }

    public function recordOperation(array $input): string
    {
        return $this->database->transaction(function () use ($input): string {
            $accountId = (string) ($input['account_id'] ?? '');
            $this->account($accountId);
            $marketId = $input['market_id'] ?? null;
            $market = $marketId !== null ? $this->market((string) $marketId, $accountId) : null;
            $kind = (string) ($input['kind'] ?? '');
            $currency = self::currency((string) ($input['currency'] ?? ''));
            $amount = self::decimal($input['amount'] ?? '', true);
            $exchangeId = (string) ($input['exchange_operation_id'] ?? '');
            if (!in_array($kind, ['deposit','withdrawal'], true) || $exchangeId === '' || strlen($exchangeId) > 255) { throw new InvalidArgumentException('Operation must be an identified deposit or withdrawal.'); }
            if ($market && $currency !== $market['base_currency']) { throw new InvalidArgumentException('Market operations must reference its base currency; quote transfers are account operations.'); }
            if (!$market && $this->one('SELECT id FROM markets WHERE account_id=? AND base_currency=?', [$accountId, $currency])) { throw new InvalidArgumentException('Base-currency transfers need an explicit market for lot accounting.'); }
            $basis = isset($input['price_basis']) ? self::decimal($input['price_basis']) : null;
            $fee = self::decimal($input['fee_amount'] ?? '0');
            $feeCurrency = isset($input['fee_currency']) ? self::currency((string) $input['fee_currency']) : null;
            if (bccomp($fee, '0', self::SCALE) > 0 && $feeCurrency === null) { throw new InvalidArgumentException('Nonzero operation fee needs a currency.'); }
            $allowedFeeCurrencies = $market === null ? [$currency] : [$currency, $market['quote_currency']];
            if (empty($input['metadata']['m3']) && bccomp($fee, '0', self::SCALE) > 0 && !in_array($feeCurrency, $allowedFeeCurrencies, true)) { throw new RuntimeException('M1 cannot attribute a transfer fee in an unrelated inventory currency.'); }
            if ($kind === 'deposit' && $feeCurrency === $currency && bccomp($fee, $amount, self::SCALE) >= 0) { throw new RuntimeException('Deposit fee must be smaller than its amount.'); }
            $row = ['account_id' => $accountId, 'market_id' => $marketId, 'exchange_operation_id' => $exchangeId,
                'kind' => $kind, 'currency' => $currency, 'amount' => $amount, 'price_basis' => $basis,
                'fee_amount' => $fee, 'fee_currency' => $feeCurrency,
                'occurred_at' => self::timestamp($input['occurred_at'] ?? null), 'metadata_json' => self::json($input['metadata'] ?? new \stdClass())];
            $existing = $this->one('SELECT * FROM operations WHERE account_id=? AND exchange_operation_id=?', [$accountId, $exchangeId]);
            if ($existing) {
                foreach (['market_id','kind','currency','amount','price_basis','fee_amount','fee_currency','metadata_json'] as $field) {
                    if ($existing[$field] !== $row[$field]) { throw new RuntimeException('Conflicting replay of an immutable financial operation.'); }
                }
                return $existing['id'];
            }
            $consumptions = [];
            if ($market && $kind === 'withdrawal' && empty($input['metadata']['m3'])) {
                $left = bcadd($amount, $feeCurrency === $currency ? $fee : '0', self::SCALE);
                foreach (array_reverse($this->marketLots($marketId)) as $lot) {
                    if ($lot['acquired_at'] > $row['occurred_at']) { continue; }
                    $take = bccomp($lot['quantity'], $left, self::SCALE) <= 0 ? $lot['quantity'] : $left;
                    if (bccomp($take, '0', self::SCALE) > 0) { $consumptions[$lot['source_key']] = self::trimDecimal($take); $left = bcsub($left, $take, self::SCALE); }
                    if (bccomp($left, '0', self::SCALE) === 0) { break; }
                }
                if (bccomp($left, '0', self::SCALE) > 0) { throw new RuntimeException('Withdrawal exceeds attributable market inventory.'); }
            }
            $id = self::uuid();
            $this->insert('operations', ['id' => $id] + $row + ['lot_consumptions_json' => self::json((object) $consumptions)]);
            $this->touch($accountId, $marketId);
            return $id;
        });
    }

    public function allocate(array $input): string
    {
        return $this->database->transaction(function () use ($input): string {
            $accountId = (string) ($input['account_id'] ?? '');
            $market = $this->market((string) ($input['market_id'] ?? ''), $accountId);
            $inventory = $this->market((string) ($input['inventory_market_id'] ?? $market['id']), $accountId);
            if ($inventory['base_currency'] !== $market['base_currency']) { throw new RuntimeException('Allocation inventory currency differs from the SELL base currency.'); }
            $key = (string) ($input['allocation_key'] ?? '');
            if ($key === '' || strlen($key) > 255) { throw new InvalidArgumentException('An idempotency key is required for each allocation.'); }
            $row = ['account_id' => $accountId, 'market_id' => $market['id'], 'inventory_market_id' => $inventory['id'], 'allocation_key' => $key,
                'buy_order_id' => $input['buy_order_id'] ?? null, 'buy_operation_id' => $input['buy_operation_id'] ?? null,
                'baseline_lot_id' => $input['baseline_lot_id'] ?? null, 'sell_order_id' => (string) ($input['sell_order_id'] ?? ''),
                'quantity' => self::decimal($input['quantity'] ?? '', true), 'buy_lot_key' => $input['buy_lot_key'] ?? null];
            if (count(array_filter([$row['buy_order_id'], $row['buy_operation_id'], $row['baseline_lot_id']], static fn ($v) => $v !== null)) !== 1) { throw new InvalidArgumentException('Exactly one BUY-equivalent source is required.'); }
            if ($existing = $this->one('SELECT * FROM buys_vs_sells WHERE account_id=? AND allocation_key=?', [$accountId, $key])) {
                foreach ($row as $field => $value) { if ($existing[$field] !== $value) { throw new RuntimeException('Conflicting replay of an allocation.'); } }
                return $existing['id'];
            }
            $sell = $this->one('SELECT * FROM orders WHERE id=? AND account_id=? AND market_id=? AND side=\'SELL\'', [$row['sell_order_id'], $accountId, $market['id']]) ?? throw new RuntimeException('Allocation requires a SELL in the same market/account.');
            $sourceKey = $row['buy_order_id'] !== null ? 'order:' . $row['buy_order_id'] : ($row['buy_operation_id'] !== null ? 'operation:' . $row['buy_operation_id'] : 'baseline:' . $row['baseline_lot_id']);
            if ($row['buy_lot_key'] !== null && $row['buy_lot_key'] !== $sourceKey && !str_starts_with($row['buy_lot_key'], $sourceKey.':')) { throw new RuntimeException('Lot fragment does not belong to the selected acquisition.'); }
            $sourceKey = $row['buy_lot_key'] ?? $sourceKey;
            $source = null;
            foreach ($this->marketLots($inventory['id']) as $lot) { if ($lot['source_key'] === $sourceKey) { $source = $lot; break; } }
            if ($source === null || bccomp($row['quantity'], $source['quantity'], self::SCALE) > 0) { throw new RuntimeException('Allocation exceeds remaining BUY-equivalent inventory.'); }
            if (($source['available_at'] ?? $source['acquired_at']) > $sell['occurred_at']) { throw new RuntimeException('A SELL cannot consume a later acquisition.'); }
            $allocated = '0';
            foreach ($this->rows('SELECT quantity FROM buys_vs_sells WHERE sell_order_id=?', [$sell['id']]) as $allocation) { $allocated = bcadd($allocated, $allocation['quantity'], self::SCALE); }
            $sellOutflow = bcadd($sell['filled_quantity'], \Trademinator\Client\M3\Lots::fee($sell, $market['base_currency']), self::SCALE);
            if (bccomp(bcadd($allocated, $row['quantity'], self::SCALE), $sellOutflow, self::SCALE) > 0) { throw new RuntimeException('Allocation exceeds actual SELL fills including base fees.'); }
            $id = self::uuid();
            $this->insert('buys_vs_sells', ['id' => $id] + $row + ['created_at' => self::timestamp($input['created_at'] ?? null)]);
            $this->touch($accountId, $market['id']);
            if ($inventory['id'] !== $market['id']) { $this->touch($accountId, $inventory['id']); }
            return $id;
        });
    }

    public static function normalizeBalances(array $balances): array
    {
        $result = [];
        foreach ($balances as $currency => $amount) {
            self::currency((string) $currency);
            $amount = self::decimal($amount);
            if (bccomp($amount, '0', self::SCALE) !== 0) { $result[$currency] = $amount; }
        }
        ksort($result);
        return $result;
    }

    public function expectedBalances(string $accountId): array
    {
        $account = $this->account($accountId);
        $balances = self::decode($account['opening_balances_json']);
        $add = static function (string $currency, string $amount) use (&$balances): void {
            $balances[$currency] = self::trimDecimal(bcadd($balances[$currency] ?? '0', $amount, self::SCALE));
        };
        foreach ($this->rows('SELECT orders.*,markets.base_currency,markets.quote_currency FROM orders JOIN markets ON markets.id=orders.market_id WHERE orders.account_id=?', [$accountId]) as $order) {
            $buy = $order['side'] === 'BUY';
            $add($order['base_currency'], ($buy ? '' : '-') . $order['filled_quantity']);
            $add($order['quote_currency'], ($buy ? '-' : '') . $order['cost_amount']);
            foreach (\Trademinator\Client\M3\Lots::fees($order) as $fee) { $add($fee['currency'], '-' . $fee['cost']); }
        }
        foreach ($this->rows('SELECT * FROM operations WHERE account_id=?', [$accountId]) as $operation) {
            $add($operation['currency'], ($operation['kind'] === 'deposit' ? '' : '-') . $operation['amount']);
            foreach (\Trademinator\Client\M3\Lots::fees($operation) as $fee) { $add($fee['currency'], '-' . $fee['cost']); }
        }
        $balances = array_filter($balances, static fn ($amount) => bccomp($amount, '0', self::SCALE) !== 0);
        ksort($balances);
        return $balances;
    }

    public function reconcileAccount(string $accountId, array $balances): array
    {
        return $this->database->transaction(function () use ($accountId, $balances): array {
            $account = $this->account($accountId);
            $actual = self::normalizeBalances($balances);
            $expected = $this->expectedBalances($accountId);
            if ($actual !== $expected) { throw new RuntimeException('Wallet reconciliation failed: supplied balances do not equal opening balances plus recorded financial activity.'); }
            if (!$this->inventoryBalanced($accountId, $expected)) { throw new RuntimeException('Wallet reconciliation failed: market inventory does not match attributable base balances.'); }
            $this->execute('UPDATE exchange_accounts SET wallet_balances_json=?,reconciled_revision=?,reconciled_at=? WHERE id=?', [self::json((object) $actual), $account['revision'], self::timestamp(), $accountId]);
            return $actual;
        });
    }

    /** Remaining inventory with its acquisition basis and exact source references. */
    public function marketLots(string $marketId, bool $includeConsumptions = true): array
    {
        $market = $this->market($marketId);
        $lots = [];
        foreach (self::decode($market['baseline_lots_json']) as $lot) {
            $lot['cost_amount'] ??= $lot['unit_cost'] === null ? null : self::trimDecimal(bcmul($lot['quantity'], $lot['unit_cost'], self::SCALE));
            $lot['acquisition_quantity'] ??= $lot['quantity'];
            $lot['acquisition_cost'] ??= $lot['cost_amount'];
            $lot['source_key'] = 'baseline:' . $lot['id'];
            $lot['source_type'] = 'baseline';
            $lots[$lot['source_key']] = $lot;
        }
        foreach ($this->rows('SELECT * FROM orders WHERE market_id=? AND side=\'BUY\' ORDER BY occurred_at,id', [$marketId]) as $order) {
            $fragments = \Trademinator\Client\M3\Lots::acquisition($order, $market);
            if ($fragments !== null) { foreach ($fragments as $fragment) { $lots[$fragment['source_key']] = $fragment; } continue; }
            $quantity = bcsub($order['filled_quantity'], $order['fee_currency'] === $market['base_currency'] ? $order['fee_amount'] : '0', self::SCALE);
            if (bccomp($quantity, '0', self::SCALE) <= 0) { continue; }
            $cost = bcadd($order['cost_amount'], $order['fee_currency'] === $market['quote_currency'] ? $order['fee_amount'] : '0', self::SCALE);
            $lots['order:' . $order['id']] = ['id' => $order['id'], 'source_key' => 'order:' . $order['id'], 'source_type' => 'order',
                'quantity' => self::trimDecimal($quantity), 'unit_cost' => self::trimDecimal(bcdiv($cost, $quantity, self::SCALE)),
                'cost_amount' => self::trimDecimal($cost), 'acquisition_quantity' => self::trimDecimal($quantity), 'acquisition_cost' => self::trimDecimal($cost),
                'origin' => ['kind' => 'order', 'exchange_order_id' => $order['exchange_order_id'], 'order_id' => $order['id']], 'acquired_at' => $order['occurred_at']];
        }
        foreach ($this->rows('SELECT * FROM operations WHERE market_id=? AND kind=\'deposit\' ORDER BY occurred_at,id', [$marketId]) as $operation) {
            $fragments = \Trademinator\Client\M3\Lots::acquisition($operation, $market);
            if ($fragments !== null) { foreach ($fragments as $fragment) { $lots[$fragment['source_key']] = $fragment; } continue; }
            $quantity = bcsub($operation['amount'], $operation['fee_currency'] === $market['base_currency'] ? $operation['fee_amount'] : '0', self::SCALE);
            $lots['operation:' . $operation['id']] = ['id' => $operation['id'], 'source_key' => 'operation:' . $operation['id'], 'source_type' => 'operation',
                'quantity' => self::trimDecimal($quantity), 'unit_cost' => $operation['price_basis'],
                'cost_amount' => $operation['price_basis'] === null ? null : self::trimDecimal(bcmul($quantity, $operation['price_basis'], self::SCALE)),
                'acquisition_quantity' => self::trimDecimal($quantity),
                'acquisition_cost' => $operation['price_basis'] === null ? null : self::trimDecimal(bcmul($quantity, $operation['price_basis'], self::SCALE)),
                'origin' => ['kind' => 'deposit', 'exchange_operation_id' => $operation['exchange_operation_id'], 'operation_id' => $operation['id']], 'acquired_at' => $operation['occurred_at']];
        }
        foreach ($this->rows('SELECT * FROM orders WHERE account_id=? AND side=\'SELL\' ORDER BY occurred_at,id', [$market['account_id']]) as $order) {
            $metadata = self::decode($order['metadata_json']);
            if (($metadata['m3']['quote_inventory_market_id'] ?? null) !== $marketId) { continue; }
            $sourceMarket = $this->market($order['market_id'], $market['account_id']);
            foreach (\Trademinator\Client\M3\Lots::quoteAcquisitions($order, $sourceMarket, $market) as $fragment) { $lots[$fragment['source_key']] = $fragment; }
        }
        if ($includeConsumptions) {
            $consume = static function (string $key, string $quantity) use (&$lots): void {
                if (!isset($lots[$key])) { throw new RuntimeException('An allocation references missing acquisition inventory.'); }
                $left = bcsub($lots[$key]['quantity'], $quantity, self::SCALE);
                if (bccomp($left, '0', self::SCALE) < 0) { throw new RuntimeException('Acquisition inventory is overallocated.'); }
                $lots[$key]['quantity'] = self::trimDecimal($left);
                $lots[$key]['cost_amount'] = $lots[$key]['acquisition_cost'] === null ? null : self::trimDecimal(bcdiv(bcmul($lots[$key]['acquisition_cost'], $left, self::SCALE * 2), $lots[$key]['acquisition_quantity'], self::SCALE));
            };
            foreach ($this->rows('SELECT * FROM buys_vs_sells WHERE inventory_market_id=?', [$marketId]) as $allocation) {
                $key = $allocation['buy_order_id'] !== null ? 'order:' . $allocation['buy_order_id'] : ($allocation['buy_operation_id'] !== null ? 'operation:' . $allocation['buy_operation_id'] : 'baseline:' . $allocation['baseline_lot_id']);
                $consume($allocation['buy_lot_key'] ?? $key, $allocation['quantity']);
            }
            foreach ($this->rows('SELECT market_id,lot_consumptions_json FROM operations WHERE account_id=? AND kind=\'withdrawal\'', [$market['account_id']]) as $withdrawal) {
                foreach (self::decode($withdrawal['lot_consumptions_json']) as $key => $quantity) {
                    if (isset($lots[$key])) { $consume($key, $quantity); }
                }
            }
            // Third-currency fees are expenses and consume that currency's inventory separately.
            foreach (['orders','operations'] as $table) {
                foreach ($this->rows('SELECT metadata_json FROM '.$table.' WHERE account_id=?', [$market['account_id']]) as $row) {
                    foreach ((self::decode($row['metadata_json'])['m3']['inventory_debits'][$marketId] ?? []) as $key => $quantity) { $consume($key, $quantity); }
                }
            }
        }
        $lots = array_values(array_filter($lots, static fn ($lot) => bccomp($lot['quantity'], '0', self::SCALE) > 0));
        usort($lots, static fn ($a, $b) => [$a['acquired_at'], $a['source_key']] <=> [$b['acquired_at'], $b['source_key']]);
        return $lots;
    }

    public function openOrdersCount(string $accountId, ?string $marketId = null): int
    {
        return (int) $this->one('SELECT COUNT(*) AS n FROM orders WHERE account_id=? AND status IN (\'open\',\'partially_filled\')' . ($marketId === null ? '' : ' AND market_id=?'), $marketId === null ? [$accountId] : [$accountId, $marketId])['n'];
    }

    public function completedTrades(string $accountId): int
    {
        $account = $this->account($accountId);
        return (int) $account['baseline_completed_trades'] + (int) $this->one('SELECT COUNT(*) AS n FROM orders WHERE account_id=? AND status IN (\'filled\',\'cancelled\',\'expired\') AND filled_quantity<>\'0\'', [$accountId])['n'];
    }

    public function marketState(string $marketId): array
    {
        $market = $this->market($marketId);
        $lots = $this->marketLots($marketId);
        $holdings = '0'; $known = true;
        foreach ($lots as $lot) { $holdings = bcadd($holdings, $lot['quantity'], self::SCALE); $known = $known && $lot['unit_cost'] !== null; }
        $unmatched = '0';
        foreach ($this->rows('SELECT * FROM orders WHERE market_id=? AND side=\'SELL\'', [$marketId]) as $sell) {
            $remaining = bcadd($sell['filled_quantity'], \Trademinator\Client\M3\Lots::fee($sell, $market['base_currency']), self::SCALE);
            foreach ($this->rows('SELECT quantity FROM buys_vs_sells WHERE sell_order_id=?', [$sell['id']]) as $allocation) { $remaining = bcsub($remaining, $allocation['quantity'], self::SCALE); }
            if (bccomp($remaining, '0', self::SCALE) < 0) { throw new RuntimeException('SELL is overallocated.'); }
            $unmatched = bcadd($unmatched, $remaining, self::SCALE);
        }
        $reference = $market['reference_price'];
        $latest = $this->one('SELECT average_price FROM orders WHERE market_id=? AND filled_quantity<>\'0\' ORDER BY occurred_at DESC,id DESC LIMIT 1', [$marketId]);
        if ($latest) { $reference = $latest['average_price']; }
        $nonzero = bccomp($holdings, '0', self::SCALE) > 0;
        $unknownNotional = $nonzero && bccomp($market['min_notional'], '0', self::SCALE) > 0 && bccomp($reference, '0', self::SCALE) === 0;
        $dust = $nonzero && (bccomp($holdings, $market['min_quantity'], self::SCALE) < 0 || $unknownNotional || bccomp(bcmul($holdings, $reference, self::SCALE), $market['min_notional'], self::SCALE) < 0);
        $checkpoint = $this->one('SELECT id,activity_revision FROM checkpoints WHERE market_id=? ORDER BY activity_revision DESC LIMIT 1', [$marketId]);
        return ['id' => $marketId, 'symbol' => $market['symbol'], 'activity_revision' => (int) $market['activity_revision'],
            'checkpoint_revision' => $checkpoint ? (int) $checkpoint['activity_revision'] : null, 'checkpoint_id' => $checkpoint['id'] ?? null,
            'checkpoint_current' => $checkpoint !== null && (int) $checkpoint['activity_revision'] === (int) $market['activity_revision'],
            'dust' => $dust, 'dust_valuation_unknown' => $unknownNotional, 'unmatched_sell_quantity' => self::trimDecimal($unmatched),
            'holdings' => self::trimDecimal($holdings), 'acquisition_cost_known' => $known, 'lots' => $lots,
            'open_orders' => $this->openOrdersCount($market['account_id'], $marketId)];
    }

    private function inventoryBalanced(string $accountId, array $expected, ?array $states = null): bool
    {
        $states ??= array_map(fn ($row) => $this->marketState($row['id']), $this->rows('SELECT id FROM markets WHERE account_id=? ORDER BY id', [$accountId]));
        $attributed = [];
        foreach ($states as $state) {
            $base = $this->market($state['id'])['base_currency'];
            $attributed[$base] = bcadd($attributed[$base] ?? '0', bcsub($state['holdings'], $state['unmatched_sell_quantity'], self::SCALE), self::SCALE);
        }
        foreach ($attributed as $base => $amount) {
            if (bccomp($amount, $expected[$base] ?? '0', self::SCALE) !== 0) { return false; }
        }
        return true;
    }

    public function accountState(string $accountId): array
    {
        $account = $this->account($accountId);
        $markets = array_map(fn ($row) => $this->marketState($row['id']), $this->rows('SELECT id FROM markets WHERE account_id=? ORDER BY symbol,id', [$accountId]));
        $wallet = self::decode($account['wallet_balances_json']);
        $expected = $this->expectedBalances($accountId);
        $inventoryBalanced = $this->inventoryBalanced($accountId, $expected, $markets);
        return ['account_id' => $accountId, 'inventory_balanced' => $inventoryBalanced, 'revision' => (int) $account['revision'], 'wallet_balances' => $wallet,
            'expected_balances' => $expected, 'reconciled' => $inventoryBalanced && $account['reconciled_revision'] !== null && (int) $account['reconciled_revision'] === (int) $account['revision'] && $wallet === $expected,
            'open_orders' => $this->openOrdersCount($accountId), 'completed_trades' => $this->completedTrades($accountId), 'markets' => $markets,
            'all_markets_checkpointed' => $markets !== [] && count(array_filter($markets, static fn ($m) => $m['checkpoint_current'])) === count($markets)];
    }

    public function createCheckpoint(string $marketId): ?string
    {
        return $this->database->transaction(function () use ($marketId): ?string {
            $market = $this->market($marketId);
            $account = $this->account($market['account_id']);
            $state = $this->marketState($marketId);
            if ($state['checkpoint_current']) { return null; }
            if ($account['reconciled_revision'] === null || (int) $account['reconciled_revision'] !== (int) $account['revision'] || self::decode($account['wallet_balances_json']) !== $this->expectedBalances($account['id'])) { throw new RuntimeException('Checkpoint requires a reconciled account wallet.'); }
            if (!$this->inventoryBalanced($account['id'], $this->expectedBalances($account['id']))) { throw new RuntimeException('Checkpoint requires consistent market inventory attribution.'); }
            if ($state['open_orders'] !== 0 || $state['dust'] || bccomp($state['unmatched_sell_quantity'], '0', self::SCALE) !== 0) { throw new RuntimeException('Checkpoint requires no open market orders, no dust, and fully matched SELL fills.'); }
            $id = self::uuid();
            $refs = ['order_ids' => array_column($this->rows('SELECT id FROM orders WHERE market_id=? ORDER BY id', [$marketId]), 'id'),
                'operation_ids' => array_column($this->rows('SELECT id FROM operations WHERE market_id=? ORDER BY id', [$marketId]), 'id'),
                'allocation_ids' => array_column($this->rows('SELECT id FROM buys_vs_sells WHERE market_id=? OR inventory_market_id=? ORDER BY id', [$marketId,$marketId]), 'id'),
                'baseline_lots' => self::decode($market['baseline_lots_json']), 'hardpoint_id' => self::decode($account['hard_t0_json'])['hardpoint_id'] ?? null];
            // A market's inventory can originate in another pair's quote proceeds or
            // be consumed by another pair. Keep those source rows reconstructible.
            $sourceKeys = array_fill_keys(array_column($this->marketLots($marketId, false), 'source_key'), true);
            foreach (['orders' => 'order_ids','operations' => 'operation_ids'] as $table => $ref) {
                foreach ($this->rows('SELECT * FROM '.$table.' WHERE account_id=? ORDER BY id', [$account['id']]) as $row) {
                    $metadata = self::decode($row['metadata_json'])['m3'] ?? [];
                    if (($metadata['quote_inventory_market_id'] ?? null) === $marketId || !empty($metadata['inventory_debits'][$marketId])
                        || ($table === 'operations' && array_intersect_key(self::decode($row['lot_consumptions_json']), $sourceKeys) !== [])) {
                        $refs[$ref][] = $row['id'];
                    }
                }
            }
            foreach ($this->rows('SELECT * FROM buys_vs_sells WHERE market_id=? OR inventory_market_id=?', [$marketId,$marketId]) as $allocation) {
                $refs['order_ids'][] = $allocation['sell_order_id'];
                if ($allocation['buy_order_id'] !== null) { $refs['order_ids'][] = $allocation['buy_order_id']; }
                if ($allocation['buy_operation_id'] !== null) { $refs['operation_ids'][] = $allocation['buy_operation_id']; }
            }
            foreach (['order_ids','operation_ids'] as $key) { $refs[$key] = array_values(array_unique($refs[$key])); sort($refs[$key]); }
            $this->insert('checkpoints', ['id' => $id, 'account_id' => $account['id'], 'market_id' => $marketId,
                'activity_revision' => $market['activity_revision'], 'account_revision' => $account['revision'], 'state_json' => self::json($state),
                'wallet_balances_json' => $account['wallet_balances_json'], 'reconstruction_refs_json' => self::json($refs), 'created_at' => self::timestamp()]);
            return $id;
        });
    }

    public function recordSignalDecision(array $input): string
    {
        return $this->database->transaction(function () use ($input): string {
            $market = $this->market((string) $input['market_id'], (string) $input['account_id']);
            $key = (string) $input['decision_key'];
            if ($key === '') { throw new InvalidArgumentException('Decision key is required.'); }
            $row = ['account_id' => $market['account_id'], 'market_id' => $market['id'], 'decision_key' => $key,
                'signal' => strtoupper((string) $input['signal']), 'reason' => (string) ($input['reason'] ?? ''),
                'payload_json' => self::json($input['payload'] ?? new \stdClass()), 'occurred_at' => self::timestamp($input['occurred_at'] ?? null)];
            if ($existing = $this->one('SELECT * FROM signal_decisions WHERE account_id=? AND decision_key=?', [$market['account_id'], $key])) {
                foreach (['market_id','signal','reason','payload_json'] as $field) { if ($existing[$field] !== $row[$field]) { throw new RuntimeException('Conflicting signal decision replay.'); } }
                return $existing['id'];
            }
            $id = self::uuid();
            $this->insert('signal_decisions', ['id' => $id] + $row);
            return $id;
        });
    }

    public function accountSnapshot(string $accountId): array
    {
        return $this->database->transaction(function () use ($accountId): array {
            $snapshot = ['account' => $this->account($accountId)];
            foreach (self::TABLES as $table) { $snapshot[$table] = $this->rows('SELECT * FROM ' . $table . ' WHERE account_id=? ORDER BY id', [$accountId]); }
            return $snapshot;
        });
    }

    public function restoreAccountSnapshot(array $snapshot): void
    {
        $this->database->transaction(function () use ($snapshot): void {
            $account = $snapshot['account'] ?? throw new InvalidArgumentException('Snapshot account missing.');
            $id = (string) ($account['id'] ?? '');
            if ($id === '') { throw new InvalidArgumentException('Snapshot account ID missing.'); }
            foreach (self::TABLES as $table) {
                if (!isset($snapshot[$table]) || !is_array($snapshot[$table])) { throw new InvalidArgumentException('Incomplete account snapshot: ' . $table); }
                foreach ($snapshot[$table] as $row) { if (($row['account_id'] ?? null) !== $id) { throw new RuntimeException('Snapshot contains another account.'); } }
            }
            $this->execute('DELETE FROM exchange_accounts WHERE id=?', [$id]);
            $this->insert('exchange_accounts', $account);
            foreach (self::TABLES as $table) { foreach ($snapshot[$table] as $row) {
                if ($table === 'buys_vs_sells') { $row['inventory_market_id'] ??= $row['market_id']; }
                $this->insert($table, $row);
            } }
            if (!$this->database->integrityCheck()['ok']) { throw new RuntimeException('Restored snapshot failed database integrity checks.'); }
            // Validate arithmetic/lot links; unknown acquisition cost is deliberately retained.
            $this->accountState($id);
        });
    }

    public function installHardT0(string $accountId, array $baseline, bool $archiveRebase = false): void
    {
        $this->database->transaction(function () use ($accountId, $baseline, $archiveRebase): void {
            $account = $this->account($accountId);
            if ($this->openOrdersCount($accountId) !== 0) { throw new RuntimeException('Hard T0 requires no open orders.'); }
            if (!isset($baseline['hardpoint_id']) || !is_string($baseline['hardpoint_id'])) { throw new InvalidArgumentException('Hardpoint identity required.'); }
            $wasReconciled = $account['reconciled_revision'] !== null && (int) $account['reconciled_revision'] === (int) $account['revision'];
            // Full source rows live in the immutable hardpoint archive. Avoid recursively embedding archives in the live database.
            unset($baseline['snapshot']);
            if ($archiveRebase) {
                $state = $this->accountState($accountId);
                if (!$state['reconciled'] || !$state['all_markets_checkpointed']) { throw new RuntimeException('Hard T1 requires reconciliation and a current checkpoint in every market.'); }
                foreach ($state['markets'] as $market) {
                    if ($market['dust'] || bccomp($market['unmatched_sell_quantity'], '0', self::SCALE) !== 0) { throw new RuntimeException('Hard T1 cannot archive unresolved market accounting.'); }
                    $carry = [];
                    foreach ($market['lots'] as $lot) {
                        $lot['id'] = self::uuid();
                        unset($lot['source_key'], $lot['source_type']);
                        $carry[] = $lot;
                    }
                    $this->execute('UPDATE markets SET baseline_lots_json=? WHERE id=?', [self::json($carry), $market['id']]);
                }
                $completed = $this->completedTrades($accountId);
                foreach (['buys_vs_sells','signal_decisions','checkpoints','operations','orders'] as $table) { $this->execute('DELETE FROM ' . $table . ' WHERE account_id=?', [$accountId]); }
                $this->execute('UPDATE exchange_accounts SET opening_balances_json=wallet_balances_json,baseline_completed_trades=? WHERE id=?', [$completed, $accountId]);
            }
            $revision = $this->touch($accountId);
            $this->execute('UPDATE exchange_accounts SET hard_t0_json=?,reconciled_revision=? WHERE id=?', [self::json($baseline), $wasReconciled ? $revision : null, $accountId]);
            if ($archiveRebase) {
                $this->execute('UPDATE markets SET activity_revision=? WHERE account_id=?', [$revision, $accountId]);
                foreach ($this->rows('SELECT id FROM markets WHERE account_id=? ORDER BY id', [$accountId]) as $market) { $this->createCheckpoint($market['id']); }
            }
        });
    }

    public function rebaseAccount(string $accountId, array $baseline): void { $this->installHardT0($accountId, $baseline, true); }
}
