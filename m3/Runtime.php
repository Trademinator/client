<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use Throwable;
use RuntimeException;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;

use function React\Async\async;
use function React\Async\await;

/** Account-level nonblocking reads, independent of Server subscription scheduling. */
final class Runtime
{
    private array $clients = [];
    private array $states = [];
    private array $tasks = [];
    private array $watchers = [];
    private array $generation = [];
    private Reconciler $reconciler;
    private Accounting $accounting;
    private ?\Closure $sink;
    private \Closure $factory;
    private bool $closed = false;
    private array $transferCandidates = [];
    private array $transferBlocks = [];
    public function __construct(private Database $db, private array $settings, ?callable $factory = null, ?callable $sink = null, private ?HardPoints $hard = null, private ?string $dataDir = null)
    {
        $this->sink = $sink === null ? null : \Closure::fromCallable($sink);
        $this->reconciler = new Reconciler($db);
        $this->accounting = new Accounting($db);
        $this->factory = $factory === null ? static fn ($exchange, $settings, $account) => new CcxtAccount($exchange, $settings, $account) : \Closure::fromCallable($factory);
        foreach ($settings['accounts'] as $account) {
            $id = $account['account_id'];
            $row = $this->accounting->rows('SELECT exchange FROM exchange_accounts WHERE id=?', [$id])[0] ?? throw new RuntimeException('Configured exchange account does not exist.');
            $this->states[$id] = ['state' => 'RECONCILING','rest_ok' => false,'ws' => [],'due' => 0,'failures' => 0,'at_ms' => null,'warning' => 'startup_reconciliation','balances' => [],'quotes' => [],'discrepancies' => [],'last_event_ms' => null,'event_sequence' => 0,'cursor_ms' => null];
            $this->generation[$id] = 0;
            try {
                $this->clients[$id] = ($this->factory)($row['exchange'], $settings, $account);
            } catch (Throwable $error) {
                if (!self::auth($error)) {
                    throw $error;
                } $this->blockAuth($id);
            }
        }
        foreach (array_keys($this->states) as $id) {
            $this->reconciler->invalidate($id);
        }
    }
    public function tick(?int $now = null): void
    {
        if ($this->closed) {
            return;
        }$now ??= (int)floor(microtime(true) * 1000);
        // Run one bounded React turn; M1's IPC and M2 curl polling retain control.
        if (class_exists(Loop::class)) {
            Loop::futureTick(static function (): void {Loop::stop();});
            Loop::run();
        }
        foreach ($this->settings['accounts'] as $account) {
            $id = $account['account_id'];
            $s = &$this->states[$id];
            if ($s['state'] === 'AUTH_BLOCKED') {
                unset($s);
                continue;
            }
            foreach ($this->clients[$id]->wsCapabilities() as $channel) {
                $symbols = in_array($channel, ['orders','trades','ticker'], true) ? $this->reconciler->context($id, $account)['symbols'] : [null];
                foreach ($symbols as $symbol) {
                    $this->watch($id, $channel, $symbol, $now);
                }
            }
            if (!isset($this->tasks[$id]) && $now >= $s['due']) {
                $this->start($id, $account, $now);
            }
            unset($s);
        }
    }
    private function watch(string $id, string $channel, ?string $symbol, int $now): void
    {
        $key = $channel.':'.($symbol ?? 'account');
        $state = $this->states[$id]['ws'][$key] ?? ['state' => 'pending','due' => 0];
        if (isset($this->watchers[$id][$key]) || $now < $state['due']) {
            return;
        }
        $generation = $this->generation[$id];
        $this->watchers[$id][$key] = true;
        try {
            $promise = $this->clients[$id]->watch($channel, $symbol);
            $this->watchers[$id][$key] = $promise;
            $promise->then(function ($payload) use ($id, $key, $channel, $generation): void {
                if ($this->closed || $generation !== $this->generation[$id]) {
                    return;
                }unset($this->watchers[$id][$key]);
                $now = (int)floor(microtime(true) * 1000);
                $this->states[$id]['ws'][$key] = ['state' => 'connected','due' => $now + 50];
                $this->states[$id]['last_event_ms'] = $now;
                if ($channel !== 'ticker') {
                    $this->states[$id]['event_sequence']++;
                } elseif (is_array($payload)) {
                    try {
                        $quote = Normalize::quote($payload);
                        $this->states[$id]['quotes'][$quote['symbol']] = $quote;
                    } catch (Throwable) {/* An incomplete quote is not tradable data. */
                    }
                }
                if ($channel !== 'ticker') {
                    $this->states[$id]['due'] = min($this->states[$id]['due'], $now + 250);
                    $this->states[$id]['rest_ok'] = false;
                    $this->states[$id]['state'] = 'RECONCILING';
                    $this->reconciler->invalidate($id);
                }
            }, function (Throwable $error) use ($id, $key, $generation): void {
                if ($this->closed || $generation !== $this->generation[$id]) {
                    return;
                }unset($this->watchers[$id][$key]);
                $now = (int)floor(microtime(true) * 1000);
                $this->states[$id]['ws'][$key] = ['state' => 'unavailable','due' => $now + $this->settings['ws_retry_ms']];
                if (self::auth($error)) {
                    $this->blockAuth($id);
                } else {
                    $this->states[$id]['due'] = $now;
                    $this->states[$id]['rest_ok'] = false;
                    $this->reconciler->invalidate($id);
                    try {
                        $this->clients[$id]->resetWebSocket();
                    } catch (Throwable) { /* Retry continues; readiness is already invalidated. */
                    }
                    $this->emit('warning', 'exchange_websocket_unavailable', ['account_id' => $id,'channel' => $key], 'WebSocket unavailable; REST reconciliation requested.');
                }
            });
        } catch (Throwable $error) {
            unset($this->watchers[$id][$key]);
            $this->states[$id]['ws'][$key] = ['state' => 'unavailable','due' => $now + $this->settings['ws_retry_ms']];
            if (self::auth($error)) {
                $this->blockAuth($id);
            }
        }
    }
    private function start(string $id, array $account, int $now): void
    {
        $generation = $this->generation[$id];
        $eventSequence = $this->states[$id]['event_sequence'];
        $this->tasks[$id] = true;
        $this->states[$id]['due'] = $now + $this->settings['sync_interval_ms'];
        $this->emit('debug', 'exchange_sync_started', ['account_id' => $id], 'Synchronizing exchange account.');
        try {
            $promise = async(function () use ($id, $account): array {
                $context = $this->reconciler->context($id, $account);
                if ($this->states[$id]['cursor_ms'] !== null && !($this->hard?->verificationPending($id) ?? false)) {
                    $context['since_ms'] = max($context['since_ms'], $this->states[$id]['cursor_ms'] - $this->settings['overlap_ms']);
                }
                $batch = await($this->clients[$id]->snapshot($context));
                if ($this->clients[$id] instanceof CcxtAccount) {
                    $prices = new Prices($this->clients[$id]->publicClient(), $account, $this->settings['timeout_ms']);
                    foreach ($batch['operations'] as $op) {
                        if ($op['kind'] !== 'deposit' || $op['currency'] === $account['valuation_quote']) {
                            continue;
                        }
                        if ($this->accounting->rows('SELECT id FROM operations WHERE account_id=? AND exchange_operation_id=?', [$id,$op['id']]) !== []) {
                            continue;
                        }
                        $market = $this->accounting->currencyMarket($id, $op['currency'], $account);
                        $batch['prices'][$op['id']] = await($prices->estimate($op['currency'], $market['quote_currency'], $op['timestamp']));
                        foreach (array_unique(array_column($this->settings['accounts'], 'valuation_quote')) as $sourceQuote) {
                            if ($sourceQuote !== $market['quote_currency']) {
                                $batch['transfer_rates'][$op['id']][$sourceQuote] = await($prices->estimate($sourceQuote, $market['quote_currency'], $op['timestamp']));
                            }
                        }
                    }
                }
                return $batch;
            })();
            $this->tasks[$id] = $promise;
            $promise->then(function (array $batch) use ($id, $account, $generation, $eventSequence): void {
                if ($this->closed || $generation !== $this->generation[$id]) {
                    return;
                }
                try {
                    if ($eventSequence !== $this->states[$id]['event_sequence']) {
                        $this->states[$id]['due'] = 0;
                        return;
                    }
                    $result = $this->reconciler->apply($id, $batch, $account);
                    $this->states[$id] = array_replace($this->states[$id], $result, ['quotes' => $batch['quotes'] ?? $this->states[$id]['quotes'],'rest_ok' => $result['reconciled'],'state' => $result['reconciled'] ? 'READY' : 'RECONCILING','warning' => $result['reconciled'] ? null : 'wallet_discrepancy','failures' => 0,'due' => (int)floor(microtime(true) * 1000) + $this->settings['sync_interval_ms']]);
                    if ($result['reconciled']) {
                        $this->states[$id]['cursor_ms'] = $batch['started_at_ms'] ?? $batch['at_ms'];
                    }
                    $links = (new Transfers($this->db, $this->settings, $this->dataDir))->autoLink();
                    $this->transferCandidates = (new Transfers($this->db, $this->settings, $this->dataDir))->candidates();
                    $this->transferBlocks = [];
                    foreach ($links as $link) {
                        if (!($link['linked'] ?? false)) {
                            foreach ([$link['withdrawal'] ?? '', $link['deposit'] ?? ''] as $operation) {
                                foreach ($this->accounting->rows('SELECT market_id FROM operations WHERE id=?', [$operation]) as $row) {
                                    if ($row['market_id'] !== null) {
                                        $this->transferBlocks[$row['market_id']] = 'transfer_investigation_required';
                                    }
                                }
                            }
                        }
                        $this->emit(($link['linked'] ?? false) ? 'info' : 'warning', 'exchange_transfer_match', $link, 'Transfer matching reviewed.');
                    }
                    if ($this->hard !== null && $result['reconciled']) {
                        $this->hard->verifyPending($id, $batch);
                        $this->hard->capture($id);
                    }
                    $this->emit($result['reconciled'] ? 'info' : 'warning', 'exchange_sync_completed', ['account_id' => $id,'reconciled' => $result['reconciled'],'discrepancies' => $result['discrepancies']], 'Exchange account synchronization completed.');
                } catch (Throwable $error) {
                    $this->failed($id, $error);
                } finally {
                    unset($this->tasks[$id]);
                }
            }, function (Throwable $error) use ($id, $generation): void {
                if ($this->closed || $generation !== $this->generation[$id]) {
                    return;
                }unset($this->tasks[$id]);
                $this->failed($id, $error);
            });
        } catch (Throwable $error) {
            unset($this->tasks[$id]);
            $this->failed($id, $error);
        }
    }
    private static function auth(Throwable $e): bool
    {
        return is_a($e, 'ccxt\\AuthenticationError') || is_a($e, 'ccxt\\PermissionDenied') || is_a($e, 'ccxt\\AccountSuspended');
    }
    private function failed(string $id, Throwable $error): void
    {
        $this->reconciler->invalidate($id);
        $this->states[$id]['rest_ok'] = false;
        if (self::auth($error)) {
            $this->blockAuth($id);
            return;
        }
        $failures = ++$this->states[$id]['failures'];
        $delay = min($this->settings['retry_max_ms'], $this->settings['retry_base_ms'] * (2 ** min(16, $failures - 1)));
        $this->states[$id]['state'] = 'RECOVERING';
        $this->states[$id]['warning'] = 'exchange_reconciliation_required';
        $this->states[$id]['due'] = (int)floor(microtime(true) * 1000) + $delay;
        $this->clients[$id]->resetRest();
        $this->emit('warning', 'exchange_sync_failed', ['account_id' => $id,'error_type' => get_class($error),'retry_in_ms' => $delay], 'Exchange synchronization failed; new orders are blocked.');
    }
    private function blockAuth(string $id): void
    {
        $this->states[$id]['state'] = 'AUTH_BLOCKED';
        $this->states[$id]['rest_ok'] = false;
        $this->states[$id]['warning'] = 'credentials_or_permissions';
        $this->reconciler->invalidate($id);
        $this->emit('error', 'exchange_auth_blocked', ['account_id' => $id], 'Exchange authentication or permissions need attention. Reload after correcting credentials.');
    }
    public function ready(string $marketId, ?int $now = null): array
    {
        $now ??= (int)floor(microtime(true) * 1000);
        $m = $this->accounting->rows('SELECT * FROM markets WHERE id=?', [$marketId])[0] ?? throw new RuntimeException('Unknown market.');
        $s = $this->states[$m['account_id']] ?? null;
        $reasons = [];
        if (isset($this->transferBlocks[$marketId])) {
            $reasons[] = $this->transferBlocks[$marketId];
        }
        if ($m['state'] !== 'ACTIVE') {
            $reasons[] = 'market_not_active';
        }
        if ($s === null || !$s['rest_ok'] || $s['at_ms'] === null || $now < $s['at_ms'] || $now - $s['at_ms'] > $this->settings['sync_interval_ms'] + $this->settings['timeout_ms']) {
            $reasons[] = 'exchange_reconciliation_required';
        }
        $ledger = new Ledger($this->db);
        $state = $ledger->marketState($marketId);
        if (!$ledger->accountState($m['account_id'])['reconciled']) {
            $reasons[] = 'financial_revision_unreconciled';
        }
        if ($state['open_orders'] > 0) {
            $reasons[] = 'open_or_uncertain_order';
        }if (!$state['acquisition_cost_known']) {
            $reasons[] = 'acquisition_cost_unknown';
        }
        return ['ready' => $reasons === [],'reasons' => array_values(array_unique($reasons)),'live_execution_enabled' => false];
    }
    public function status(): array
    {
        $accounts = $this->states;
        foreach ($accounts as $id => &$state) {
            unset($state['due']);
            if ($state['discrepancies'] !== []) {
                $state['adjustment_token'] = $this->adjustmentToken($id, $state);
            }$state['transport'] = count(array_filter($state['ws'], static fn ($s) => $s['state'] === 'connected')) > 0 ? 'websocket_with_rest' : 'rest';
        }unset($state);
        return ['enabled' => $accounts !== [],'live_execution_enabled' => false,'accounts' => $accounts,'transfer_candidates' => $this->transferCandidates, 'transfer_blocks' => $this->transferBlocks];
    }
    public function command(string $command, array $args): array
    {
        if ($command === 'exchange-status') {
            return $this->status();
        }
        if ($command === 'exchange-refresh') {
            $id = $args['account'] ?? '';
            if (!isset($this->states[$id])) {
                throw new RuntimeException('Account is not connected.');
            }$this->states[$id]['due'] = 0;
            return ['scheduled' => true];
        }
        if ($command === 'exchange-adjust') {
            $id = $args['account'] ?? '';
            $currency = $args['currency'] ?? '';
            $state = $this->states[$id] ?? null;
            if ($state === null || !isset($state['discrepancies'][$currency]) || $state['at_ms'] === null || (int)floor(microtime(true) * 1000) - $state['at_ms'] > $this->settings['sync_interval_ms'] + $this->settings['timeout_ms']) {
                throw new RuntimeException('A recent observed discrepancy is required.');
            }
            $token = $this->adjustmentToken($id, $state);
            if (!hash_equals($token, $args['approve'] ?? '')) {
                throw new RuntimeException('Approve the exact discrepancy token from exchange-status.');
            }
            $result = $this->reconciler->adjustment($id, $currency, $state['discrepancies'][$currency]['actual'], $args['price'] ?? null, $args['reason'] ?? '', $token);
            $this->states[$id]['due'] = 0;
            $this->states[$id]['rest_ok'] = false;
            return $result;
        }
        if ($command === 'deposit-basis') {
            return $this->reconciler->basis($args['operation'] ?? '', $args['price'] ?? '', $args['reason'] ?? '');
        }
        if ($command === 'transfer-link') {
            if (($args['approve'] ?? '') !== 'yes') {
                throw new RuntimeException('Explicit transfer confirmation is required.');
            }return (new Transfers($this->db, $this->settings, $this->dataDir))->link($args['withdrawal'] ?? '', $args['deposit'] ?? '', true);
        }
        if ($command === 'hard-h-propose') {
            return $this->hard?->capture($args['account'] ?? '', true) ?? [];
        }
        if ($command === 'hard-h-promote') {
            return $this->hard?->promote($args['account'] ?? '', $args['approve'] ?? '') ?? [];
        }
        throw new RuntimeException('Unknown exchange command.');
    }
    private function adjustmentToken(string $id, array $state): string
    {
        return hash('sha256', Numbers::json([$id,$this->db->revision($id),$state['at_ms'],$state['discrepancies']]));
    }
    private function emit(string $level, string $event, array $context, string $message): void
    {
        if ($this->sink !== null) {
            ($this->sink)($level, $event, $context, $message);
        }
    }
    public function close(): void
    {
        if ($this->closed) {
            return;
        }$this->closed = true;
        foreach ($this->clients as $id => $client) {
            $this->generation[$id]++;
            $client->close();
        }
        foreach ($this->tasks as $task) {
            if (is_object($task) && method_exists($task, 'cancel')) {
                $task->cancel();
            }
        }
        $this->tasks = $this->watchers = [];
    }
}
