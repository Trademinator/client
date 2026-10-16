<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Async\async;
use function React\Async\await;

/** Read-only capability surface. No order submission, cancellation, transfer or withdrawal methods. */
final class CcxtAccount
{
    private object $rest;
    private ?object $pro = null;
    private array $options;
    public function __construct(private string $exchange, private array $settings, private array $account, ?object $rest = null, ?object $pro = null)
    {
        if (!preg_match('/^[a-z][a-z0-9]{1,40}$/D', $exchange)) {
            throw new RuntimeException('Invalid exchange ID.');
        }
        $this->options = ['enableRateLimit' => true,'timeout' => $settings['timeout_ms'],'newUpdates' => true,'number' => 'strval','options' => ['defaultType' => 'spot']];
        if ($rest === null) {
            $class = '\\ccxt\\async\\'.$exchange;
            if (!class_exists($class)) {
                throw new RuntimeException('Exchange is unavailable in installed CCXT.');
            }
            try {
                $this->options += Settings::credentials($account);
            } catch (\RuntimeException $error) {
                throw new \ccxt\AuthenticationError('Exchange credentials are unavailable.');
            }
            $rest = new $class($this->options);
            $class = '\\ccxt\\pro\\'.$exchange;
            if (class_exists($class)) {
                $pro = new $class($this->options);
            }
        }
        $this->rest = $rest;
        $this->pro = $pro;
    }
    public function supports(string $method): bool
    {
        return in_array($this->rest->has[$method] ?? false, [true,'emulated'], true);
    }
    public function wsCapabilities(): array
    {
        $out = [];
        foreach (['watchBalance' => 'balance','watchOrders' => 'orders','watchMyTrades' => 'trades','watchTicker' => 'ticker'] as $key => $value) {
            if (($this->pro->has[$key] ?? false) === true) {
                $out[] = $value;
            }
        }return $out;
    }
    public function watch(string $channel, ?string $symbol = null): PromiseInterface
    {
        return match($channel) {
            'balance' => $this->pro->watch_balance(),'orders' => $this->pro->watch_orders($symbol),
            'trades' => $this->pro->watch_my_trades($symbol),'ticker' => $this->pro->watch_ticker($symbol),default => throw new RuntimeException('Unsupported watch channel.')
        };
    }
    public function publicClient(): object
    {
        return $this->rest;
    }
    public function snapshot(array $context): PromiseInterface
    {
        return async(function () use ($context): array {
            $started = (int)floor(microtime(true) * 1000);
            $loaded = await($this->rest->load_markets());
            $balanceBefore = Normalize::balances(await($this->rest->fetch_balance()));
            $symbols = $context['symbols'];
            // Some exchanges require a symbol for private trade history. Scan all spot markets
            // to include manual activity outside the subscription list; CCXT limits every call.
            foreach ($loaded as $market) {
                if (($market['spot'] ?? false) && !empty($market['symbol'])) {
                    $symbols[] = $market['symbol'];
                }
            }
            $symbols = array_values(array_unique(array_filter($symbols, static fn ($symbol) => isset($loaded[$symbol]))));
            $trades = [];
            $orders = [];
            $open = [];
            $operations = [];
            $since = $context['since_ms'];
            foreach ($symbols as $symbol) {
                foreach ($this->history('fetch_my_trades', $symbol, $since) as $row) {
                    $trade = Normalize::trade($row);
                    $trades[$trade['symbol'].'|'.$trade['id']] = $trade;
                }
                foreach ($this->history('fetch_open_orders', $symbol, null) as $row) {
                    $o = Normalize::order($row);
                    $orders[$o['id']] = $o;
                    $open[$o['id']] = true;
                }
            }
            $wanted = $context['known_orders'];
            foreach ($trades as $trade) {
                $wanted[$trade['order']] = $trade['symbol'];
            }
            foreach ($orders as $order) {
                $wanted[$order['id']] = $order['symbol'];
            }
            foreach ($wanted as $id => $symbol) {
                // Absence from open-orders never means filled or cancelled.
                if (!isset($orders[$id])) {
                    $orders[$id] = Normalize::order(await($this->rest->fetch_order((string)$id, $symbol)));
                }
                $o = $orders[$id];
                if (bccomp($o['filled'], '0', 24) > 0 && $this->supports('fetchOrderTrades')) {
                    foreach ($this->history('fetch_order_trades', $symbol, null, (string)$id) as $row) {
                        $trade = Normalize::trade($row);
                        $trades[$trade['symbol'].'|'.$trade['id']] = $trade;
                    }
                }
            }
            $ledgerFallback = !$this->supports('fetchWithdrawals') || !$this->supports('fetchDeposits');
            if ($ledgerFallback && !$this->supports('fetchLedger')) {
                throw new RuntimeException('CCXT lacks complete transfer history for this exchange.');
            }
            if ($ledgerFallback) {
                foreach ($this->history('fetch_ledger', null, $since) as $row) {
                    if (($row['type'] ?? '') !== 'transaction') {
                        continue;
                    }
                    $direction = $row['direction'] ?? null;
                    if (!in_array($direction, ['in','out'], true)) {
                        throw new RuntimeException('Ledger transfer direction is unknown.');
                    }
                    $row['id'] = $row['referenceId'] ?? $row['id'];
                    $row['amount'] = ltrim((string)$row['amount'], '-');
                    $op = Normalize::operation($row, $direction === 'in' ? 'deposit' : 'withdrawal');
                    if ($op !== null) {
                        $operations[$op['id']] = $op;
                    }
                }
            } else {
                foreach (['fetch_deposits' => 'deposit','fetch_withdrawals' => 'withdrawal'] as $method => $kind) {
                    foreach ($this->history($method, null, $since) as $row) {
                        $op = Normalize::operation($row, $kind);
                        if ($op !== null) {
                            $operations[$op['id']] = $op;
                        }
                    }
                }
            }
            $balance = Normalize::balances(await($this->rest->fetch_balance()));
            if ($balanceBefore !== $balance) {
                throw new RuntimeException('Wallet changed during reconciliation; repeat the account snapshot.');
            }
            $quotes = [];
            foreach ($context['symbols'] as $symbol) {
                if (isset($loaded[$symbol]) && $this->supports('fetchTicker')) {
                    try {
                        $quotes[$symbol] = Normalize::quote(await($this->rest->fetch_ticker($symbol)));
                    } catch (\Throwable) {/* Quote failure does not invent a price or authorize future execution. */
                    }
                }
            }
            $at = (int)floor(microtime(true) * 1000);
            return ['at_ms' => $at,'started_at_ms' => $started,'since_ms' => $since,'balances' => $balance,'orders' => array_values($orders),'trades' => array_values($trades),
                'operations' => array_values($operations),'markets' => $loaded,'quotes' => $quotes,'complete' => true,'prices' => []];
        })();
    }
    /** Bounded overlapping pagination; full or non-progressing pages never become a complete history. */
    private function history(string $method, ?string $symbol, ?int $since, ?string $orderId = null): array
    {
        $config = $this->account['history_params'][$method] ?? [];
        $mode = $config['mode'] ?? 'time';
        $params = $config['params'] ?? [];
        $limit = $this->settings['history_limit'];
        $result = [];
        $seen = [];
        $cursor = $since;
        for ($page = 0;$page < $this->settings['history_max_pages'];$page++) {
            $requestSince = $mode === 'time' ? $cursor : null;
            $rows = $orderId === null ? await($this->rest->$method($symbol, $requestSince, $limit, $params))
                : await($this->rest->$method($orderId, $symbol, $requestSince, $limit, $params));
            if (!is_array($rows)) {
                throw new RuntimeException('Invalid history page.');
            }
            $new = 0;
            $min = PHP_INT_MAX;
            $max = 0;
            $edge = null;
            foreach ($rows as $row) {
                $id = Normalize::id($row['id'] ?? null);
                $timestamp = Normalize::timestamp($row);
                $min = min($min, $timestamp);
                $max = max($max, $timestamp);
                if ($edge === null || ($mode === 'backward_id' ? [$timestamp,$id] < [$edge['timestamp'],$edge['id']] : [$timestamp,$id] > [$edge['timestamp'],$edge['id']])) {
                    $edge = ['id' => $id,'timestamp' => $timestamp];
                }
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $new++;
                    if ($since === null || $timestamp >= $since) {
                        $result[] = $row;
                    }
                }
            }
            if ($mode === 'all' || count($rows) < $limit || ($mode === 'backward_id' && $since !== null && $min < $since)) {
                return $result;
            }
            if ($new === 0 || $edge === null) {
                throw new RuntimeException('History pagination made no progress.');
            }
            if (in_array($mode, ['backward_id','forward_id'], true)) {
                $params[$config['cursor_param'] ?? 'marker'] = $edge['id'];
            } elseif ($mode === 'time') {
                if ($cursor !== null && $max <= $cursor) {
                    throw new RuntimeException('History timestamp boundary is full; configure cursor pagination.');
                }
                $cursor = $max; // overlap boundary; identities deduplicate, including equal timestamps
            } else {
                throw new RuntimeException('Unsupported history pagination mode.');
            }
        }
        throw new RuntimeException('History page budget exhausted; history is incomplete.');
    }
    public function resetWebSocket(): void
    {
        $this->pro?->close();
        $this->pro = null;
        $class = '\\ccxt\\pro\\'.$this->exchange;
        if (class_exists($class)) {
            $this->pro = new $class($this->options);
        }
    }
    public function resetRest(): void
    {
        $this->rest->marketsLoading = null;
        $this->rest->reloadingMarkets = false;
    }
    public function close(): void
    {
        $this->pro?->close();
        $this->rest->close();
    }
}
