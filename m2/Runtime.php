<?php
declare(strict_types=1);
namespace Trademinator\Client\M2;

use RuntimeException;
use Throwable;
use Trademinator\Client\M1\Database;

/** Subscription-scoped, read-only Server polling. No exchange/order execution lives here. */
final class Runtime
{
    private Markets $markets;
    private Scheduler $scheduler;
    private ?object $api;
    private bool $injectedApi;
    private \Closure $clock;
    private array $settings;
    private array $catalog = [];
    private ?int $catalogAt = null;
    private ?array $risk = null;
    private ?int $riskAt = null;
    private array $cache = [];
    private array $warnings = [];
    private array $generation = [];
    private array $configured = [];
    private array $requests = [];
    private int $requestSequence = 0;
    private int $catalogDue = 0;
    private int $riskDue = 0;
    private int $nextRequestAt = 0;
    private int $blockedUntil = 0;
    private int $failures = 0;
    private int $freshnessHighWaterMs = 0;
    private ?string $serverWarning = null;
    private ?\Closure $eventSink;
    private array $observedMarketStates = [];
    private ?string $observedContextWarning = null;

    public function __construct(Database $database, array $settings, ?object $api = null, ?callable $clock = null, ?callable $eventSink = null)
    {
        $this->markets = new Markets($database);
        $this->scheduler = new Scheduler();
        $this->clock = $clock === null ? static fn(): int => (int) floor(microtime(true) * 1000) : \Closure::fromCallable($clock);
        $this->settings = $settings;
        $this->injectedApi = $api !== null;
        $this->api = $api ?? $this->buildApi($settings);
        $this->eventSink = $eventSink === null ? null : \Closure::fromCallable($eventSink);
        foreach ($this->markets->all() as $market) { $this->observedMarketStates[$market['id']] = $market['state']; }
    }

    private function buildApi(array $settings): ?object
    {
        if (!($settings['enabled'] ?? false)) { return null; }
        return new ServerApi(new Transport($settings['url'], $settings['token'], $settings['timeout_ms']));
    }

    public function reconfigure(array $settings): void
    {
        // Construct first; invalid credentials or URL must leave the running configuration intact.
        $candidate = $this->injectedApi ? $this->api : $this->buildApi($settings);
        $this->api?->cancelAll();
        $this->settings = $settings;
        $this->api = $candidate;
        $this->catalog = $this->cache = $this->requests = $this->warnings = $this->configured = [];
        $this->catalogAt = $this->riskAt = null;
        $this->risk = null;
        $this->catalogDue = $this->riskDue = $this->nextRequestAt = $this->blockedUntil = $this->failures = 0;
        $this->serverWarning = null;
        $this->observedContextWarning = null;
        $this->scheduler->reset();
        foreach ($this->generation as &$generation) { ++$generation; }
        $this->emit('info', 'server_configuration_reloaded', ['enabled' => (bool) ($settings['enabled'] ?? false)],
            'Server configuration reloaded; operational context will be fetched again.');
    }

    public function tick(?int $nowMs = null): void
    {
        $nowMs ??= ($this->clock)();
        $this->freshnessTime($nowMs);
        $this->markets->settleStopping();
        $this->synchronize($nowMs);
        if ($this->api === null || !($this->settings['enabled'] ?? false)) { return; }
        foreach ($this->api->poll($nowMs) as $response) { $this->complete($response, $nowMs); }
        $this->synchronize($nowMs);
        // Advancing even while throttled drops expired windows without a catch-up burst.
        $due = $this->scheduler->due($nowMs);
        if ($this->api->pending() !== 0 || $nowMs < max($this->nextRequestAt, $this->blockedUntil)) { return; }
        if ($nowMs >= $this->catalogDue) { $this->start('catalog', [], $nowMs); return; }
        if ($nowMs >= $this->riskDue) { $this->start('risk', [], $nowMs); return; }
        if ($due !== []) {
            $id = $due[0]['market_id'];
            $market = $this->markets->get($id);
            if ($market['state'] !== 'ACTIVE') { $this->invalidate($id); return; }
            $subscription = $this->catalog[$market['subscription_id']] ?? null;
            if ($subscription === null || !$subscription['is_active']) { return; }
            $slot = $this->scheduler->claim($id, $nowMs);
            if ($slot !== null) {
                $this->start('market', ['market_id' => $id, 'subscription_id' => $market['subscription_id'],
                    'period' => $subscription['period'], 'generation' => $this->generation[$id] ?? 0, 'slot' => $slot], $nowMs);
            }
        }
    }

    private function start(string $kind, array $context, int $nowMs): void
    {
        $id = 'm2_'.(++$this->requestSequence);
        $this->requests[$id] = ['kind' => $kind, 'started_at_ms' => $nowMs] + $context;
        $this->nextRequestAt = $nowMs + $this->settings['min_request_interval_ms'];
        try {
            if ($kind === 'catalog') { $this->api->markets($id); }
            elseif ($kind === 'risk') { $this->api->risk($id); }
            else { $this->api->market($id, $context['subscription_id']); }
            if ($kind === 'market') {
                $market = $this->markets->get($context['market_id']);
                $this->emit('info', 'market_check_started', $this->marketContext($market) + [
                    'request_id' => $id, 'period' => $context['period'], 'started_at_ms' => $nowMs,
                    'slot' => $context['slot'],
                ], 'Checking '.$market['exchange'].' '.$market['symbol'].' Server signal.');
            } else {
                $this->emit('debug', 'server_request_started', ['request_id' => $id, 'kind' => $kind, 'started_at_ms' => $nowMs],
                    'Fetching Server '.($kind === 'catalog' ? 'subscriptions' : 'risk factor').'.');
            }
            if ($this->failures > 0) {
                $this->emit('info', 'server_retry_started', ['request_id' => $id, 'kind' => $kind, 'failures' => $this->failures],
                    'Retrying Server communication.');
            }
        } catch (Throwable) {
            unset($this->requests[$id]);
            $this->communicationFailure('transport_error', $nowMs, 0);
        }
    }

    private function complete(array $response, int $nowMs): void
    {
        $requestId = $response['request_id'] ?? '';
        $request = $this->requests[$requestId] ?? null;
        unset($this->requests[$requestId]);
        if ($request === null) { return; }
        $current = $request['kind'] !== 'market' || $this->isCurrent($request);
        if (($response['error'] ?? null) !== null || ($response['status'] ?? 0) !== 200) {
            $code = $response['error_code'] ?? $response['error'] ?? 'server_error';
            if ($request['kind'] === 'market' && in_array($response['status'] ?? 0, [403, 404], true) && $code !== 'account_unavailable') {
                if (!$current) { return; }
                $id = $request['market_id'];
                $market = $this->markets->stop($id);
                $this->observeMarketState($market, 'subscription_unavailable');
                $this->invalidate($id);
                $this->warnings[$id] = 'subscription_unavailable';
                return;
            }
            $this->communicationFailure($code, $nowMs, (int) ($response['retry_after_ms'] ?? 0));
            return;
        }
        if (!$current) { return; }
        try {
            if ($request['kind'] === 'catalog') {
                $catalog = ServerApi::validateMarkets($response['data']);
                $this->catalog = array_column($catalog, null, 'subscription_id');
                $this->catalogAt = $nowMs;
                $this->catalogDue = $nowMs + $this->settings['catalog_interval_ms'];
                $this->emit('info', 'server_catalog_refreshed', ['subscriptions' => count($catalog),
                    'active_subscriptions' => count(array_filter($catalog, static fn(array $row): bool => $row['is_active'])),
                    'fetched_at_ms' => $nowMs], 'Server subscriptions refreshed: '.count($catalog).' available.');
                $this->synchronize($nowMs);
            } elseif ($request['kind'] === 'risk') {
                $this->risk = ServerApi::validateRisk($response['data']);
                $this->riskAt = $nowMs;
                $this->riskDue = $nowMs + $this->settings['risk_interval_ms'];
                $this->emit('info', 'server_risk_refreshed', ['risk_factor' => $this->risk['risk_factor'],
                    'source' => $this->risk['source'], 'fetched_at_ms' => $nowMs], 'Server risk factor refreshed.');
            } else {
                $id = $request['market_id'];
                $detail = $response['data']['market'] ?? null;
                $market = $this->markets->get($id);
                if (($response['data']['api_version'] ?? null) !== 1 || !is_array($detail)
                    || ($detail['subscription_id'] ?? null) !== $request['subscription_id']
                    || ($detail['exchange']['class'] ?? null) !== $market['exchange']
                    || ($detail['symbol'] ?? null) !== $market['symbol']
                    || !is_bool($detail['active'] ?? null) || !array_key_exists('period', $detail)
                    || !array_key_exists('signal', $detail)
                    || ($detail['signal'] !== null && !is_array($detail['signal']))) {
                    throw new RuntimeException('Invalid market detail identity.');
                }
                if (!$detail['active']) {
                    $market = $this->markets->stop($id);
                    $this->observeMarketState($market, 'subscription_unavailable');
                    $this->invalidate($id);
                    $this->warnings[$id] = 'subscription_unavailable';
                    $this->catalogDue = $nowMs;
                    return;
                }
                $validated = Intelligence::validate($response['data'], $request['subscription_id'], $request['period'], $nowMs, 5000, $market['exchange'], $market['symbol']);
                $this->signalEvent($market, $validated, $nowMs);
                if ($validated['reason'] === 'candle_period_changed') {
                    // A new Server period changes scheduling, never local market identity.
                    $this->catalogDue = $nowMs;
                    unset($this->cache[$id]);
                    $this->warnings[$id] = 'candle_period_changed';
                    return;
                }
                $this->cache[$id] = ['detail' => $validated['detail'], 'received_at_ms' => $nowMs,
                    'subscription_id' => $request['subscription_id'], 'period' => $request['period']];
                $this->warnings[$id] = $validated['eligible'] ? null : $validated['reason'];
            }
            if ($this->risk !== null) {
                $recovered = $this->serverWarning !== null;
                $this->failures = 0; $this->serverWarning = null;
                if ($recovered) {
                    $this->emit('info', 'server_connection_restored', ['restored_at_ms' => $nowMs],
                        'Server communication restored; scheduled market checks will resume.');
                }
            }
        } catch (Throwable $error) {
            if ($error instanceof \PDOException) { throw $error; }
            if ($request['kind'] === 'market') {
                unset($this->cache[$request['market_id']]);
                $this->warnings[$request['market_id']] = 'invalid_intelligence_contract';
                $market = $this->markets->get($request['market_id']);
                $this->emit('warning', 'market_signal_rejected', $this->marketContext($market) + [
                    'reason' => 'invalid_intelligence_contract', 'intelligence_eligible' => false, 'trade_authorized' => false,
                ], 'Rejected invalid Server signal for '.$market['exchange'].' '.$market['symbol'].'.');
            } else { $this->communicationFailure('invalid_server_contract', $nowMs, 0); }
        }
    }

    private function communicationFailure(string $warning, int $nowMs, int $retryAfterMs): void
    {
        $alreadyFailed = $this->serverWarning !== null;
        ++$this->failures;
        $delay = min($this->settings['retry_max_ms'], $this->settings['retry_base_ms'] * (2 ** min(16, $this->failures - 1)));
        $this->blockedUntil = $nowMs + (int) max($delay, $retryAfterMs);
        $this->serverWarning = $warning;
        // Loss of Server communication never deactivates an operator-selected market.
        $this->discardEvidence();
        $this->risk = null;
        $this->riskAt = null;
        $this->catalogDue = $this->riskDue = $nowMs;
        $this->emit('warning', $alreadyFailed ? 'server_retry_failed' : 'server_connection_lost', [
            'reason' => self::eventCode($warning, 'server_error'), 'failures' => $this->failures,
            'retry_at_ms' => $this->blockedUntil, 'retry_delay_ms' => $this->blockedUntil - $nowMs,
        ], $alreadyFailed ? 'Server retry failed; another retry is scheduled.'
            : 'Server communication failed; active markets remain selected and cached intelligence was cleared.');
    }

    private function isCurrent(array $request): bool
    {
        $market = $this->markets->get($request['market_id']);
        return $market['state'] === 'ACTIVE'
            && $market['subscription_id'] === $request['subscription_id']
            && ($this->generation[$market['id']] ?? 0) === $request['generation']
            && ($this->catalog[$market['subscription_id']]['period'] ?? null) === $request['period'];
    }

    private function invalidate(string $id, bool $keepSchedule = false): void
    {
        $this->generation[$id] = ($this->generation[$id] ?? 0) + 1;
        unset($this->cache[$id], $this->configured[$id]);
        if (!$keepSchedule) { $this->scheduler->remove($id); }
    }

    private function synchronize(int $nowMs): void
    {
        foreach ($this->markets->all() as $market) {
            $id = $market['id'];
            $this->observeMarketState($market, 'authoritative_settlement');
            $this->generation[$id] ??= 0;
            if ($market['state'] !== 'ACTIVE') {
                if (isset($this->configured[$id]) || isset($this->cache[$id])) { $this->invalidate($id); }
                continue;
            }
            if ($this->catalogAt === null) { continue; }
            $subscription = $this->catalog[$market['subscription_id']] ?? null;
            if ($subscription === null || !$subscription['is_active'] || $subscription['exchange'] !== $market['exchange'] || $subscription['symbol'] !== $market['symbol']) {
                $market = $this->markets->stop($id);
                $this->observeMarketState($market, 'subscription_unavailable');
                $this->invalidate($id);
                $this->warnings[$id] = 'subscription_unavailable';
                continue;
            }
            $period = $subscription['period'];
            $checks = $market['checks_per_candle'] ?? $this->settings['checks_per_candle'];
            if ($period === null) {
                if (isset($this->configured[$id])) { $this->invalidate($id); }
                if (($this->warnings[$id] ?? null) !== 'candle_period_unavailable') {
                    $this->emit('warning', 'market_schedule_unavailable', $this->marketContext($market) + ['reason' => 'candle_period_unavailable'],
                        'Waiting for a candle period for '.$market['exchange'].' '.$market['symbol'].'.');
                }
                $this->warnings[$id] = 'candle_period_unavailable';
                continue;
            }
            $signature = $market['subscription_id'].'|'.$period.'|'.$checks;
            if (($this->configured[$id] ?? null) === $signature) { continue; }
            $this->invalidate($id, true);
            try {
                $this->scheduler->configure($id, $period, $checks, $nowMs);
                $this->configured[$id] = $signature;
                $this->warnings[$id] = 'awaiting_intelligence';
                $this->emit('info', 'market_schedule_updated', $this->marketContext($market) + $this->scheduler->status($id, $nowMs),
                    'Scheduled '.$checks.' checks per '.$period.' candle for '.$market['exchange'].' '.$market['symbol'].'.');
            } catch (\InvalidArgumentException) {
                $this->scheduler->remove($id);
                if (($this->warnings[$id] ?? null) !== 'unsupported_candle_period') {
                    $this->emit('warning', 'market_schedule_unavailable', $this->marketContext($market) + ['reason' => 'unsupported_candle_period', 'period' => $period],
                        'Unsupported candle period for '.$market['exchange'].' '.$market['symbol'].'.');
                }
                $this->warnings[$id] = 'unsupported_candle_period';
            }
        }
    }

    public function command(string $command, array $arguments): array
    {
        $nowMs = ($this->clock)();
        if ($command === 'subscriptions') {
            return ['fetched_at_ms' => $this->catalogAt, 'warning' => $this->serverWarning] + $this->page('subscriptions', array_values($this->catalog), $arguments);
        }
        if ($command === 'markets') { return $this->page('markets', $this->marketStatus($nowMs), $arguments); }
        if ($command === 'server-refresh') {
            $this->catalogDue = $this->riskDue = $nowMs;
            $this->emit('info', 'server_refresh_requested', [], 'Server subscriptions and risk refresh requested.');
            return ['status' => 'refresh_scheduled'];
        }
        if ($command === 'market-activate') {
            if (!($this->settings['enabled'] ?? false) || $this->catalogAt === null || $this->serverWarning !== null
                || $this->freshnessTime($nowMs) > $this->catalogAt + $this->settings['catalog_interval_ms'] + $this->settings['timeout_ms']) {
                throw new RuntimeException('A current authenticated subscription catalog is required before activation.');
            }
            $subscription = $this->catalog[$arguments['subscription'] ?? ''] ?? throw new RuntimeException('Unknown subscription; refresh the Server catalog first.');
            $checks = isset($arguments['checks']) ? Scheduler::checks($arguments['checks']) : null;
            $market = $this->markets->activate((string) ($arguments['account'] ?? ''), $subscription, $checks);
            $this->observeMarketState($market, 'operator_request');
            $this->generation[$market['id']] ??= 0;
            $this->invalidate($market['id']);
            $this->synchronize($nowMs);
            return ['market' => $market];
        }
        if ($command === 'market-stop' || $command === 'market-checks') {
            $id = (string) ($arguments['market'] ?? '');
            $market = $command === 'market-stop' ? $this->markets->stop($id)
                : $this->markets->setChecks($id, isset($arguments['checks']) && $arguments['checks'] !== 'default' ? Scheduler::checks($arguments['checks']) : null);
            if ($command === 'market-stop') { $this->observeMarketState($market, 'operator_request'); }
            else {
                $this->emit('info', 'market_checks_updated', $this->marketContext($market) + [
                    'checks_per_candle' => $market['checks_per_candle'],
                    'effective_checks_per_candle' => $market['checks_per_candle'] ?? $this->settings['checks_per_candle'],
                ], 'Polling frequency updated for '.$market['exchange'].' '.$market['symbol'].'.');
            }
            $this->generation[$id] ??= 0;
            $this->invalidate($id, $command === 'market-checks');
            if ($command === 'market-stop') { $this->warnings[$id] = null; }
            $this->synchronize($nowMs);
            return ['market' => $market];
        }
        throw new RuntimeException('Unsupported M2 control command.');
    }

    private function marketStatus(int $nowMs): array
    {
        $freshnessMs = $this->freshnessTime($nowMs);
        $result = [];
        foreach ($this->markets->all() as $market) {
            $id = $market['id'];
            $entry = $market + ['subscription_active' => in_array($market['state'], ['ACTIVE', 'STOPPING'], true),
                'period' => $this->catalog[$market['subscription_id']]['period'] ?? null,
                'warning' => $this->warnings[$id] ?? null, 'intelligence_eligible' => false, 'trade_authorized' => false,
                'signal' => null, 'received_at_ms' => $this->cache[$id]['received_at_ms'] ?? null,
                'schedule' => $this->scheduler->status($id, $nowMs)];
            if ($market['state'] === 'ACTIVE') {
                if (($warning = $this->contextWarning($nowMs)) !== null) { $entry['warning'] = $warning; }
                elseif (isset($this->cache[$id])) {
                    $cache = $this->cache[$id];
                    try {
                        $valid = Intelligence::validate($cache['detail'], $cache['subscription_id'], $cache['period'], $freshnessMs, 5000, $market['exchange'], $market['symbol']);
                        $entry['intelligence_eligible'] = $valid['eligible'];
                        $entry['signal'] = $valid['signal'] === null ? null : array_intersect_key($valid['signal'], array_flip(['id', 'action', 'period', 'reason', 'evidence_status', 'decision_at_ms', 'recorded_at_ms', 'is_current_model', 'is_fresh', 'expires_at_ms']));
                        $entry['warning'] = $valid['eligible'] ? null : $valid['reason'];
                        if ($valid['reason'] === 'intelligence_expired') {
                            $this->expireSignal($market);
                        }
                    } catch (Throwable) { $entry['warning'] = 'invalid_intelligence_contract'; }
                }
            }
            $result[] = $entry;
        }
        return $result;
    }

    private function contextWarning(int $nowMs): ?string
    {
        $rawNowMs = $nowMs;
        $nowMs = $this->freshnessTime($nowMs);
        if (!($this->settings['enabled'] ?? false)) { return $this->observeContextWarning('server_disabled', $nowMs); }
        if ($rawNowMs < $nowMs) {
            $this->discardEvidence();
            return $this->observeContextWarning('clock_rollback', $rawNowMs);
        }
        if ($this->serverWarning !== null) { return $this->observeContextWarning($this->serverWarning, $nowMs); }
        if ($this->catalogAt === null) { return $this->observeContextWarning('subscription_catalog_unavailable', $nowMs); }
        if ($nowMs > $this->catalogAt + $this->settings['catalog_interval_ms'] + $this->settings['timeout_ms']) {
            $this->discardEvidence();
            return $this->observeContextWarning('subscription_catalog_expired', $nowMs);
        }
        if ($this->risk === null || $this->riskAt === null) { return $this->observeContextWarning('risk_factor_unavailable', $nowMs); }
        if ($nowMs > $this->riskAt + $this->settings['risk_interval_ms'] + $this->settings['timeout_ms']) {
            $this->discardEvidence();
            return $this->observeContextWarning('risk_factor_expired', $nowMs);
        }
        return $this->observeContextWarning(null, $nowMs);
    }

    private function discardEvidence(): void
    {
        foreach (array_keys($this->cache) as $id) { $this->warnings[$id] = 'awaiting_intelligence'; }
        $this->cache = [];
    }

    private function freshnessTime(int $nowMs): int
    {
        // This RAM-only clock cannot move backwards and revive expired evidence or authorization context.
        return $this->freshnessHighWaterMs = max($this->freshnessHighWaterMs, $nowMs);
    }

    private function page(string $key, array $rows, array $arguments): array
    {
        $offset = $arguments['offset'] ?? 0;
        $limit = $arguments['limit'] ?? 50;
        foreach (['offset' => &$offset, 'limit' => &$limit] as $name => &$value) {
            if (is_string($value) && preg_match('/^[0-9]{1,8}$/D', $value)) { $value = (int) $value; }
            if (!is_int($value) || $value < ($name === 'limit' ? 1 : 0) || $value > ($name === 'limit' ? 100 : 10000000)) {
                throw new RuntimeException('Invalid pagination '.$name.'.');
            }
        }
        unset($value);
        // Market rows contain status and timing fields; keep even maximal valid rows within the IPC bound.
        if ($key === 'markets') { $limit = min(50, $limit); }
        return [$key => array_slice($rows, $offset, $limit), 'total' => count($rows), 'offset' => $offset,
            'limit' => $limit, 'has_more' => $offset + $limit < count($rows)];
    }

    /** Local evidence eligible for future M4 evaluation, never trade authorization or an ACT record. */
    public function opportunities(?int $nowMs = null): array
    {
        $nowMs ??= ($this->clock)();
        if ($this->contextWarning($nowMs) !== null) { return []; }
        $nowMs = $this->freshnessTime($nowMs);
        $result = [];
        foreach ($this->markets->all() as $market) {
            $cache = $this->cache[$market['id']] ?? null;
            if ($market['state'] !== 'ACTIVE' || $cache === null || $cache['subscription_id'] !== $market['subscription_id']
                || ($this->catalog[$market['subscription_id']]['period'] ?? null) !== $cache['period']) { continue; }
            try {
                $valid = Intelligence::validate($cache['detail'], $cache['subscription_id'], $cache['period'], $nowMs, 5000, $market['exchange'], $market['symbol']);
                if ($valid['reason'] === 'intelligence_expired') {
                    $this->expireSignal($market);
                }
                if ($valid['eligible']) {
                    $result[] = ['market_id' => $market['id'], 'account_id' => $market['account_id'],
                        'exchange' => $market['exchange'], 'symbol' => $market['symbol'],
                        'subscription_id' => $market['subscription_id'], 'risk' => $this->risk] + $valid;
                }
            } catch (Throwable) { /* Invalid cached data cannot become a trading opportunity. */ }
        }
        return $result;
    }

    public function status(): array
    {
        $nowMs = ($this->clock)();
        $markets = $this->marketStatus($nowMs);
        $requiredPerMinute = 60000 / $this->settings['catalog_interval_ms'] + 60000 / $this->settings['risk_interval_ms'];
        foreach ($markets as $market) {
            if ($market['schedule'] !== null) { $requiredPerMinute += $market['schedule']['checks_per_candle'] * 60000 / Scheduler::periodMilliseconds($market['schedule']['period'], $nowMs); }
        }
        $availablePerMinute = 60000 / $this->settings['min_request_interval_ms'];
        return ['enabled' => (bool) ($this->settings['enabled'] ?? false), 'live_order_execution' => false,
            'exchange_connected' => false, 'warning' => $this->contextWarning($nowMs), 'catalog_fetched_at_ms' => $this->catalogAt,
            'subscriptions' => count($this->catalog), 'risk' => $this->risk, 'risk_fetched_at_ms' => $this->riskAt,
            'pending_requests' => $this->api?->pending() ?? 0, 'retry_at_ms' => $this->blockedUntil > $nowMs ? $this->blockedUntil : null,
            'requests_per_minute' => ['required' => round($requiredPerMinute, 3), 'available' => round($availablePerMinute, 3),
                'capacity_exceeded' => $requiredPerMinute > $availablePerMinute], 'markets' => array_slice($markets, 0, 50),
            'markets_total' => count($markets), 'markets_has_more' => count($markets) > 50];
    }

    private function marketContext(array $market): array
    {
        return ['market_id' => $market['id'], 'exchange' => $market['exchange'], 'symbol' => $market['symbol'],
            'subscription_id' => $market['subscription_id']];
    }

    private function observeMarketState(array $market, string $cause): void
    {
        $previous = $this->observedMarketStates[$market['id']] ?? null;
        $this->observedMarketStates[$market['id']] = $market['state'];
        if ($previous === $market['state']) { return; }
        $event = match ($market['state']) {
            'ACTIVE' => 'market_activated', 'STOPPING' => 'market_stopping', default => 'market_stopped',
        };
        $details = match ($market['state']) {
            'ACTIVE' => 'activated', 'STOPPING' => 'stopping; intelligence polling is disabled while orders settle',
            default => 'stopped; intelligence polling is disabled',
        };
        $this->emit('info', $event, $this->marketContext($market) + ['previous_state' => $previous,
            'state' => $market['state'], 'cause' => $cause], $market['exchange'].' '.$market['symbol'].' '.$details.'.');
    }

    private function signalEvent(array $market, array $validated, int $nowMs): void
    {
        $signal = $validated['signal'];
        $reason = self::eventCode($signal['reason'] ?? $validated['reason'], 'unspecified');
        $outcome = self::eventCode($validated['reason'], 'unavailable');
        $action = $signal['action'] ?? 'hold';
        $this->emit('info', 'market_signal_received', $this->marketContext($market) + [
            'signal_id' => $signal['id'] ?? null, 'action' => $action, 'reason' => $reason,
            'evidence_status' => $signal['evidence_status'] ?? null, 'period' => $validated['detail']['market']['period'],
            'outcome' => $outcome, 'intelligence_eligible' => $validated['eligible'], 'trade_authorized' => false,
            'is_current_model' => $signal['is_current_model'] ?? null, 'is_fresh' => $signal['is_fresh'] ?? null,
            'expires_at_ms' => $signal['expires_at_ms'] ?? null, 'received_at_ms' => $nowMs,
        ], $market['exchange'].' '.$market['symbol'].' signal: '.strtoupper($action).' ('.$outcome.').');
    }

    private function expireSignal(array $market): void
    {
        unset($this->cache[$market['id']]);
        $this->warnings[$market['id']] = 'intelligence_expired';
        $this->emit('info', 'market_signal_expired', $this->marketContext($market) + [
            'reason' => 'intelligence_expired', 'intelligence_eligible' => false, 'trade_authorized' => false,
        ], 'Cached signal expired for '.$market['exchange'].' '.$market['symbol'].'.');
    }

    private function observeContextWarning(?string $warning, int $nowMs): ?string
    {
        if ($this->observedContextWarning !== $warning
            && in_array($warning, ['clock_rollback', 'subscription_catalog_expired', 'risk_factor_expired'], true)) {
            $this->emit('warning', 'server_context_invalidated', ['reason' => $warning, 'observed_at_ms' => $nowMs],
                'Server context is no longer usable ('.$warning.'); cached intelligence was cleared.');
        }
        $this->observedContextWarning = $warning;
        return $warning;
    }

    private static function eventCode(string $value, string $fallback): string
    {
        return preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $value) === 1 ? $value : $fallback;
    }

    /** Telemetry is local, selected metadata only, and must never alter operational behavior. */
    private function emit(string $level, string $event, array $context, string $message): void
    {
        if ($this->eventSink === null) { return; }
        try {
            // Defense in depth if a remote metadata field unexpectedly repeats our credential.
            $token = $this->settings['token'] ?? '';
            if (is_string($token) && $token !== '') {
                array_walk_recursive($context, static function (mixed &$value) use ($token): void {
                    if (is_string($value)) { $value = str_replace($token, '[redacted]', $value); }
                });
                $message = str_replace($token, '[redacted]', $message);
            }
            ($this->eventSink)($level, $event, $context, $message);
        } catch (Throwable) { /* An unavailable output sink cannot change market or request state. */ }
    }

    public function __debugInfo(): array { return ['enabled' => (bool) ($this->settings['enabled'] ?? false), 'markets' => count($this->configured)]; }
    public function __serialize(): array { throw new RuntimeException('Server runtime cannot be serialized.'); }
}
