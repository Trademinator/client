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
    private array $requestContext = [];
    private array $progress = [];
    private ?\WeakMap $failureContexts = null;
    public function __construct(private string $exchange, private array $settings, private array $account, ?object $rest = null, ?object $pro = null)
    {
        if (!preg_match('/^[a-z][a-z0-9]{1,40}$/D', $exchange)) {
            throw new RuntimeException('Invalid exchange ID.');
        }
        $this->options = ['enableRateLimit' => true,'timeout' => $settings['timeout_ms'],'newUpdates' => true,'number' => 'strval','options' => ['defaultType' => 'spot']];
        if ($rest === null) {
            $class = $exchange === 'bitso' ? BitsoReadClient::class : '\\ccxt\\async\\'.$exchange;
            if (!class_exists($class)) {
                throw new RuntimeException('Exchange is unavailable in installed CCXT.');
            }
            $this->options += Settings::credentials($account);
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
            $this->requestContext = [];
            $this->progress = ['requests_started' => 0, 'requests_finished' => 0];
            $loaded = $this->read('load_markets');
            $balanceBefore = Normalize::balances($this->read('fetch_balance', [], ['phase' => 'opening_balance']));
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
            $accountWide = $this->rest instanceof BitsoReadClient;
            $historySymbols = $accountWide ? [null] : $symbols;
            foreach ($historySymbols as $symbol) {
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
            $historicalSymbols = [];
            $historyOnlyCount = 0;
            $wanted = $context['known_orders'];
            $observedByOrder = [];
            foreach ($trades as $trade) {
                $wanted[$trade['order']] = $trade['symbol'];
                $observedByOrder[$trade['order']][] = $trade;
            }
            foreach ($orders as $order) {
                $wanted[$order['id']] = $order['symbol'];
            }
            foreach ($wanted as $id => $symbol) {
                // Absence from open-orders never means filled or cancelled.
                if (!isset($orders[$id])) {
                    try {
                        $orders[$id] = Normalize::order($this->read('fetch_order', [(string)$id, $symbol], ['symbol' => $symbol]));
                    } catch (\Throwable $error) {
                        // Bitso removes terminal order details after about one hour. Verify
                        // ownership and amounts using full order trades; never invent FILLED.
                        $missing = $this->exchange === 'bitso' &&
                            (is_a($error, 'ccxt\\OrderNotFound') || (SyncDiagnostics::describe($error, 'bitso')['exchange_code'] ?? null) === '0312');
                        $observed = $observedByOrder[$id] ?? [];
                        if (!$missing || is_a($error, 'ccxt\\AuthenticationError') || is_a($error, 'ccxt\\PermissionDenied')
                            || is_a($error, 'ccxt\\AccountSuspended') || isset($context['known_orders'][$id]) || $observed === [] || !$this->supports('fetchOrderTrades')) {
                            throw $error;
                        }
                        // The documented order-trades endpoint returns the full set; no
                        // limit/since/cursor or configurable origin_id can truncate/redirect it.
                        $rows = $this->read('fetch_order_trades', [(string)$id, $symbol, null, null, []],
                            ['symbol' => $symbol, 'phase' => 'historical_order_verification']);
                        if (!is_array($rows)) { throw new RuntimeException('Invalid history page.'); }
                        $history = HistoricalOrder::summarize((string)$id, $symbol, $observed, array_map(Normalize::trade(...), $rows), $started);
                        $orders[$id] = $history['order'];
                        foreach ($history['trades'] as $trade) {
                            $trades[$trade['symbol'].'|'.$trade['id']] = $trade;
                        }
                        $historicalSymbols[$accountWide ? '' : $symbol][] = (string)$id;
                        ++$historyOnlyCount;
                    }
                }
                $o = $orders[$id];
                if ($o['id'] !== (string)$id || $o['symbol'] !== $symbol) {
                    throw new RuntimeException('Trade/order identity mismatch.');
                }
                if ($o['status'] === 'history_only') { continue; }
                if (bccomp($o['filled'], '0', 24) > 0 && $this->supports('fetchOrderTrades')) {
                    foreach ($this->history('fetch_order_trades', $symbol, null, (string)$id) as $row) {
                        $trade = Normalize::trade($row);
                        if (isset($trades[$trade['symbol'].'|'.$trade['id']]) && $trades[$trade['symbol'].'|'.$trade['id']] !== $trade) {
                            throw new RuntimeException('Conflicting exchange trade identity.');
                        }
                        $trades[$trade['symbol'].'|'.$trade['id']] = $trade;
                    }
                }
            }
            foreach ($historicalSymbols as $symbol => $ids) {
                // Confirm the complete open-order list again after the history fetch.
                foreach ($this->history('fetch_open_orders', $symbol === '' ? null : $symbol, null) as $row) {
                    if (in_array((string)($row['id'] ?? ''), $ids, true)) {
                        throw new RuntimeException('Historical order appeared in the open-order confirmation; retry synchronization.');
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
                // Transfer status may settle long after its creation timestamp.
                // Re-read from the configured/Hard H0 boundary, not the trade cursor.
                $transferSince = $this->rest instanceof BitsoReadClient ? ($context['transfer_since_ms'] ?? $since) : $since;
                foreach (['fetch_deposits' => 'deposit','fetch_withdrawals' => 'withdrawal'] as $method => $kind) {
                    foreach ($this->history($method, null, $transferSince) as $row) {
                        $op = Normalize::operation($row, $kind);
                        if ($op !== null) {
                            $operations[$op['id']] = $op;
                        }
                    }
                }
            }
            $balance = Normalize::balances($this->read('fetch_balance', [], ['phase' => 'closing_balance']));
            if ($balanceBefore !== $balance) {
                throw new RuntimeException('Wallet changed during reconciliation; repeat the account snapshot.');
            }
            $quotes = [];
            foreach ($context['symbols'] as $symbol) {
                if (isset($loaded[$symbol]) && $this->supports('fetchTicker')) {
                    try {
                        $quotes[$symbol] = Normalize::quote($this->read('fetch_ticker', [$symbol], ['symbol' => $symbol]));
                    } catch (\Throwable) {/* Quote failure does not invent a price or authorize future execution. */
                    }
                }
            }
            $at = (int)floor(microtime(true) * 1000);
            return ['at_ms' => $at,'started_at_ms' => $started,'since_ms' => $since,'balances' => $balance,'orders' => array_values($orders),'trades' => array_values($trades),
                'operations' => array_values($operations),'markets' => $loaded,'quotes' => $quotes,'complete' => true,'prices' => [],'history_only_orders' => $historyOnlyCount];
        })()->then(null, function (\Throwable $error): never {
            $this->failureContexts ??= new \WeakMap();
            $this->failureContexts[$error] = array_merge($this->requestContext,
                $this->rest instanceof BitsoReadClient ? $this->rest->failureContext($error) : []);
            // Preserve the exact exception class for existing authentication/retry handling.
            throw $error;
        });
    }
    /** Request metadata only; never persist/return credentials, IDs, params or signed URLs. */
    private function read(string $method, array $arguments = [], array $context = []): mixed
    {
        $this->requestContext = ['operation' => $method] + $context;
        $started = (int)floor(microtime(true) * 1000);
        ++$this->progress['requests_started'];
        $this->progress['request'] = $this->requestContext + ['started_at_ms' => $started, 'in_flight' => true];
        try {
            return await($this->rest->$method(...$arguments));
        } finally {
            ++$this->progress['requests_finished'];
            $this->progress['request']['in_flight'] = false;
            $this->progress['request']['elapsed_ms'] = max(0, (int)floor(microtime(true) * 1000) - $started);
        }
    }
    /** Safe metadata only; elapsed time includes CCXT rate-limit waiting. */
    public function snapshotProgress(?int $now = null): array
    {
        $progress = $this->progress;
        if ($progress['request']['in_flight'] ?? false) {
            $now ??= (int)floor(microtime(true) * 1000);
            $progress['request']['elapsed_ms'] = max(0, $now - $progress['request']['started_at_ms']);
        }
        return $progress;
    }
    public function failureContext(\Throwable $error): array
    {
        return $this->failureContexts[$error] ?? [];
    }
    /** Bounded overlapping pagination; full or non-progressing pages never become a complete history. */
    private function history(string $method, ?string $symbol, ?int $since, ?string $orderId = null): array
    {
        $config = $this->account['history_params'][$method] ?? [];
        $mode = $config['mode'] ?? 'time';
        $params = $config['params'] ?? [];
        $limit = $this->settings['history_limit'];
        $bitso = $this->rest instanceof BitsoReadClient;
        if ($bitso && in_array($method, ['fetch_open_orders', 'fetch_order_trades'], true)) {
            $this->requestContext = ['operation' => $method, 'phase' => 'history_configuration'];
            $openOrders = $method === 'fetch_open_orders';
            // Accept the old example's open-order policy, but do not send its
            // undocumented marker/sort. Neither endpoint supports pagination.
            if (($openOrders && (array_diff(array_keys($params), ['sort']) || ($params['sort'] ?? 'desc') !== 'desc'
                    || !in_array($config['mode'] ?? 'all', ['all', 'backward_id'], true)
                    || ($config['cursor_param'] ?? 'marker') !== 'marker'))
                || (!$openOrders && ($params !== [] || ($config['mode'] ?? 'all') !== 'all'))) {
                throw new RuntimeException($openOrders ? 'Bitso open orders require an unfiltered account-wide list.'
                    : 'Bitso order trade verification requires the complete unfiltered order history.');
            }
            $arguments = $openOrders ? [null, null, null, []] : [$orderId, $symbol, null, null, []];
            $rows = $this->read($method, $arguments, ['symbol' => $symbol, 'page' => 1, 'limit' => $openOrders ? 500 : null,
                'pagination_mode' => 'all', 'since_ms' => null, 'cursor_present' => false]);
            if (!is_array($rows)) { throw new RuntimeException('Invalid history page.'); }
            return $rows;
        }
        $bitsoTrades = $bitso && $method === 'fetch_my_trades';
        $bitsoTransfers = $this->rest instanceof BitsoReadClient && in_array($method, ['fetch_deposits', 'fetch_withdrawals'], true);
        if ($bitsoTransfers || $bitsoTrades) {
            $this->requestContext = ['operation' => $method, 'phase' => 'history_configuration'];
            $mode = $config['mode'] ?? 'backward_id';
            if ($mode !== 'backward_id' || ($config['cursor_param'] ?? 'marker') !== 'marker' || array_key_exists('marker', $params)) {
                throw new RuntimeException($bitsoTrades ? 'Bitso trade history requires unfiltered backward marker pagination.'
                    : 'Bitso transfer history requires unfiltered backward marker pagination.');
            }
            $limit = min(100, $limit);
        }
        $result = [];
        $seen = [];
        $cursor = $since;
        for ($page = 0;$page < $this->settings['history_max_pages'];$page++) {
            $requestSince = $mode === 'time' ? $cursor : null;
            $arguments = $orderId === null ? [$symbol, $requestSince, $limit, $params]
                : [$orderId, $symbol, $requestSince, $limit, $params];
            $rows = $this->read($method, $arguments, [
                'symbol' => $symbol, 'page' => $page + 1, 'limit' => $limit,
                'pagination_mode' => $mode, 'since_ms' => $requestSince,
                'cursor_present' => isset($params[$config['cursor_param'] ?? 'marker']),
            ]);
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
                if ($bitsoTransfers || $bitsoTrades || $edge === null || ($mode === 'backward_id' ? [$timestamp,$id] < [$edge['timestamp'],$edge['id']] : [$timestamp,$id] > [$edge['timestamp'],$edge['id']])) {
                    $edge = ['id' => $id,'timestamp' => $timestamp];
                }
                if ($bitsoTransfers && isset($seen[$id]) && $seen[$id] !== $row) {
                    throw new RuntimeException('Bitso transfer changed during pagination; repeat the account snapshot.');
                }
                $evidence = $bitsoTrades ? Normalize::trade($row) : ($bitsoTransfers ? $row : true);
                if ($bitsoTrades && isset($seen[$id]) && $seen[$id] !== $evidence) {
                    throw new RuntimeException('Conflicting exchange trade identity.');
                }
                if (!isset($seen[$id])) {
                    $seen[$id] = $evidence;
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
