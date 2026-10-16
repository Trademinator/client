<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;
use Trademinator\Client\M2\Runtime;

final class RuntimeFixtureApi
{
    public array $sent = [];
    private ?array $request = null;
    private array $responses = [];
    public function __construct(private readonly \Closure $clock) {}
    private function start(string $id, string $kind, ?string $subscription = null): void
    {
        if ($this->request !== null) { throw new RuntimeException('Runtime queued overlapping requests.'); }
        $this->request = ['request_id' => $id, 'kind' => $kind, 'subscription' => $subscription, 'at' => ($this->clock)()];
        $this->sent[] = $this->request;
    }
    public function markets(string $id): void { $this->start($id, 'catalog'); }
    public function risk(string $id): void { $this->start($id, 'risk'); }
    public function market(string $id, string $subscription): void { $this->start($id, 'market', $subscription); }
    public function pending(): int { return $this->request === null ? 0 : 1; }
    public function cancelAll(): void { $this->request = null; $this->responses = []; }
    public function current(): ?array { return $this->request; }
    public function respond(?array $data, int $status = 200, ?string $code = null, int $retryMs = 0): void
    {
        if ($this->request === null) { throw new RuntimeException('No fixture request is pending.'); }
        $this->responses[] = ['request_id' => $this->request['request_id'], 'status' => $status,
            'data' => $data, 'error' => $status === 200 ? null : 'http_'.$status,
            'error_code' => $code, 'retry_after_ms' => $retryMs];
    }
    public function poll(?int $nowMs = null): array
    {
        $result = $this->responses;
        if ($result !== []) { $this->request = null; $this->responses = []; }
        return $result;
    }
}

final class RuntimeFixture
{
    public const BASE = 1800000000000;
    public int $now = self::BASE;
    public Database $db;
    public Ledger $ledger;
    public RuntimeFixtureApi $api;
    public Runtime $runtime;
    public string $account;
    public array $catalog;
    public array $settings;
    public function __construct()
    {
        $this->db = new Database(':memory:');
        $this->ledger = new Ledger($this->db);
        $this->account = $this->ledger->createAccount('bitso', 'M2 fixture', null, ['USD' => '1000']);
        $this->settings = ['enabled' => true, 'url' => 'https://fixture.invalid', 'token' => 'fixture-secret',
            'timeout_ms' => 10000, 'catalog_interval_ms' => 60000, 'risk_interval_ms' => 60000,
            'checks_per_candle' => 3, 'min_request_interval_ms' => 600, 'retry_base_ms' => 1000, 'retry_max_ms' => 60000];
        $this->catalog = ['api_version' => 1, 'markets' => [
            $this->subscription('sub-1', 'ATOM/USD'), $this->subscription('sub-2', 'XRP/USD'),
            $this->subscription('sub-inactive', 'BTC/USD', false),
        ]];
        $this->api = new RuntimeFixtureApi(fn(): int => $this->now);
        $this->runtime = new Runtime($this->db, $this->settings, $this->api, fn(): int => $this->now);
    }
    public function subscription(string $id, string $symbol, bool $active = true, ?string $period = '1m'): array
    {
        return ['subscription_id' => $id, 'active' => $active, 'exchange' => ['class' => 'bitso', 'name' => 'Bitso'],
            'symbol' => $symbol, 'period' => $period, 'latest_signal_id' => null];
    }
    public function tick(int $offset): void { $this->now = self::BASE + $offset; $this->runtime->tick(); }
    public function ready(): void
    {
        $this->tick(0);
        $this->api->respond($this->catalog); $this->tick(1);
        $this->tick(600);
        $this->api->respond(['api_version' => 1, 'risk_factor' => '0.25', 'algorithm_version' => 'questionnaire-risk-v1', 'source' => 'default']);
        $this->tick(601);
    }
    public function activate(string $subscription = 'sub-1', ?int $checks = null, ?string $account = null): string
    {
        $args = ['account' => $account ?? $this->account, 'subscription' => $subscription];
        if ($checks !== null) { $args['checks'] = $checks; }
        return $this->runtime->command('market-activate', $args)['market']['id'];
    }
    public function detail(string $subscription = 'sub-1', string $symbol = 'ATOM/USD', string $period = '1m'): array
    {
        return ['api_version' => 1, 'market' => [
            'subscription_id' => $subscription, 'active' => true, 'exchange' => ['class' => 'bitso', 'name' => 'Bitso'],
            'symbol' => $symbol, 'period' => $period, 'tick_size' => '0.01',
            'settings' => ['max_order_quote' => '1000000'],
            'signal' => [
                    'id' => 'signal-1', 'period' => $period, 'model_id' => 'model-1', 'action' => 'buy', 'reason' => 'supported',
                    'evidence_status' => 'supported', 'decision_at_ms' => $this->now - 60000, 'recorded_at_ms' => $this->now - 1000,
                    'is_current_model' => true, 'is_fresh' => true, 'expires_at_ms' => $this->now + 30000, 'confidence' => 0.8,
                    'action_meaning' => 'supported_buy_by_outcome_action_matrix', 'reference_price' => '10.00',
                    'reference_price_source' => 'closed_candle_close', 'prediction_input_basis' => 'completed_candle',
                    'scoring' => ['large_fixture_detail' => str_repeat('x', 5000)],
                ],
        ]];
    }
    public function status(string $id): array
    {
        foreach ($this->runtime->command('markets', [])['markets'] as $market) { if ($market['id'] === $id) { return $market; } }
        throw new RuntimeException('Fixture market missing.');
    }
    public function order(string $market, string $status = 'open'): array
    {
        $input = ['account_id' => $this->account, 'market_id' => $market, 'exchange_order_id' => 'fixture-order',
            'side' => 'BUY', 'quantity' => '1', 'filled_quantity' => '0', 'remaining_quantity' => '1',
            'price' => '10', 'status' => $status, 'occurred_at' => '2026-10-15T00:00:00Z'];
        $this->ledger->recordOrder($input);
        return $input;
    }
}

$assertions = 0;
function m2assert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
}
function m2reject(callable $call, string $message): void
{
    try { $call(); } catch (Throwable) { m2assert(true, $message); return; }
    m2assert(false, $message);
}

try {
    $f = new RuntimeFixture();
    m2reject(fn() => $f->activate(), 'Activation needs authenticated catalog.');
    $f->ready();
    m2assert($f->runtime->command('subscriptions', [])['total'] === 3, 'All subscriptions, including inactive, are exposed.');
    m2assert($f->runtime->command('markets', [])['total'] === 0, 'Catalog discovery never selects markets automatically.');
    $page = $f->runtime->command('subscriptions', ['offset' => '1', 'limit' => '1']);
    m2assert(count($page['subscriptions']) === 1 && $page['subscriptions'][0]['subscription_id'] === 'sub-2' && $page['has_more'], 'Catalog pagination fits local IPC.');
    m2reject(fn() => $f->activate('sub-inactive'), 'Inactive subscription cannot be activated.');
    m2reject(fn() => $f->activate('unknown'), 'Unknown subscription cannot be activated.');
    $foreign = $f->ledger->createAccount('kraken', 'foreign exchange');
    m2reject(fn() => $f->activate('sub-1', null, $foreign), 'Account exchange must match subscription.');
    $id = $f->activate();
    m2assert($f->status($id)['state'] === 'ACTIVE' && $f->status($id)['schedule']['checks_per_candle'] === 3, 'Live activation starts the configurable default schedule.');
    $f->tick(19999);
    m2assert($f->api->pending() === 0, 'No detail fetch before aligned UTC check.');
    $f->tick(20000);
    m2assert($f->api->current()['kind'] === 'market' && $f->api->current()['subscription'] === 'sub-1', 'Only explicitly selected subscription is polled.');
    $initialDetail = $f->detail(); $f->api->respond($initialDetail); $f->tick(20001);
    m2assert($f->status($id)['intelligence_eligible'] && count($f->runtime->opportunities()) === 1, 'Validated intelligence is a RAM-only opportunity.');
    m2assert(count($f->runtime->opportunities()) === 1, 'Reading an opportunity is repeatable before a real decision.');
    m2assert($f->runtime->opportunities()[0]['trade_authorized'] === false, 'Local evidence eligibility never authorizes a trade.');
    m2assert(!isset($f->runtime->opportunities()[0]['detail']['market']['settings']), 'Legacy Server sizing settings are ignored.');
    m2assert(!str_contains(json_encode($f->runtime->status()), 'large_fixture_detail'), 'Local status omits large scoring payload.');
    m2assert(!str_contains(json_encode($f->runtime->status()), 'fixture-secret'), 'Local status never exposes bearer credentials.');
    m2assert((int) $f->db->pdo()->query('SELECT COUNT(*) FROM signal_decisions')->fetchColumn() === 0
        && (int) $f->db->pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0, 'M2 never records ACT or places orders.');
    $f->tick(40000); $f->api->respond($initialDetail); $f->tick(40001);
    m2assert(count($f->runtime->opportunities()) === 1 && $f->runtime->opportunities()[0]['signal']['id'] === 'signal-1', 'Repeated source signal remains locally reevaluable without ACT.');
    $f->now = RuntimeFixture::BASE + 50000;
    m2assert(!$f->status($id)['intelligence_eligible'] && $f->runtime->opportunities() === [], 'Expiry blocks cached intelligence at the exact deadline.');
    m2assert($f->status($id)['received_at_ms'] === null, 'Observed signal expiry evicts cached detail.');
    $f->now = RuntimeFixture::BASE + 40000;
    m2assert(!$f->status($id)['intelligence_eligible'] && $f->runtime->opportunities() === [], 'Clock rollback cannot revive expired cached signal evidence.');

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $f->api->respond($f->detail()); $f->tick(20001);
    $f->now = RuntimeFixture::BASE + 30000;
    m2assert(count($f->runtime->opportunities()) === 1, 'Evidence remains available before expiry and a clock rollback.');
    $f->now = RuntimeFixture::BASE + 25000;
    m2assert($f->runtime->opportunities() === [] && !$f->status($id)['intelligence_eligible']
        && $f->status($id)['warning'] === 'clock_rollback' && $f->status($id)['state'] === 'ACTIVE', 'Clock rollback before expiry suppresses candidates while keeping ACTIVE.');
    $f->now = RuntimeFixture::BASE + 30000;
    m2assert($f->runtime->opportunities() === [] && $f->status($id)['warning'] === 'awaiting_intelligence', 'Reaching the observed clock high water does not restore pre-rollback cached evidence.');
    $f->tick(40000); $recovered = $f->detail(); $recovered['market']['signal']['id'] = 'signal-after-clock-check';
    $f->api->respond($recovered); $f->tick(40001);
    m2assert(count($f->runtime->opportunities()) === 1 && $f->status($id)['warning'] === null,
        'Recovery after clock rollback requires a new authenticated Server detail.');

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $inflight = $f->detail();
    $f->runtime->command('market-stop', ['market' => $id]);
    $f->api->respond($inflight); $f->tick(20001);
    m2assert($f->status($id)['state'] === 'STOPPED' && $f->runtime->opportunities() === [], 'Stopping immediately discards in-flight intelligence.');
    $idAgain = $f->activate('sub-1', 5);
    m2assert($idAgain === $id && $f->status($id)['schedule']['checks_per_candle'] === 5, 'Reactivation preserves account-symbol identity.');
    $order = $f->order($id);
    $f->runtime->command('market-stop', ['market' => $id]);
    m2assert($f->status($id)['state'] === 'STOPPING' && $f->status($id)['subscription_active'], 'Open order retains subscription-active STOPPING state.');
    $f->tick(600000);
    m2assert($f->status($id)['state'] === 'STOPPING', 'Elapsed time never invents authoritative cancellation.');
    $f->ledger->recordOrder(array_replace($order, ['status' => 'cancelled']));
    $f->tick(600001);
    m2assert($f->status($id)['state'] === 'STOPPED', 'Authoritative cancellation releases STOPPING.');

    foreach (['filled', 'expired', 'rejected'] as $terminal) {
        $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $order = $f->order($id);
        $f->runtime->command('market-stop', ['market' => $id]);
        $final = array_replace($order, ['status' => $terminal]);
        if ($terminal === 'filled') { $final['filled_quantity'] = '1'; $final['remaining_quantity'] = '0'; }
        $f->ledger->recordOrder($final); $f->tick(1000);
        m2assert($f->status($id)['state'] === 'STOPPED', 'Terminal '.$terminal.' order releases STOPPING.');
    }

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $inactive = $f->detail();
    $inactive['market']['active'] = false;
    $f->api->respond($inactive); $f->tick(20001);
    m2assert($f->status($id)['state'] === 'STOPPED', 'Detail revocation immediately stops polling before next catalog refresh.');

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $inactive = $f->detail('sub-1', 'ATOM/USD', '5m');
    $inactive['market']['active'] = false;
    $f->api->respond($inactive); $f->tick(20001);
    m2assert($f->status($id)['state'] === 'STOPPED', 'Inactive detail takes precedence over simultaneous period drift.');

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $order = $f->order($id);
    $f->runtime->command('server-refresh', []); $f->tick(1200);
    $revoked = $f->catalog; array_shift($revoked['markets']);
    $f->api->respond($revoked); $f->tick(1201);
    m2assert($f->status($id)['state'] === 'STOPPING' && $f->status($id)['schedule'] === null, 'Catalog revocation drains existing orders without pulling intelligence.');

    foreach ([[401, 'unauthenticated'], [403, 'account_unavailable'], [503, 'service_unavailable']] as [$status, $code]) {
        $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
        $f->api->respond(null, $status, $code); $f->tick(20001);
        m2assert($f->status($id)['state'] === 'ACTIVE' && $f->status($id)['warning'] === $code && $f->runtime->opportunities() === [], 'Global '.$code.' keeps ACTIVE and fails closed.');
        $sent = count($f->api->sent); $f->tick(21000);
        m2assert(count($f->api->sent) === $sent, 'Global failure honors backoff.');
        $f->tick(21001);
        m2assert($f->api->current()['kind'] === 'catalog', 'Communication recovery retries automatically.');
        $f->api->respond($f->catalog); $f->tick(21002); $f->tick(21601);
        $f->api->respond(['api_version' => 1, 'risk_factor' => '0.25', 'algorithm_version' => 'questionnaire-risk-v1', 'source' => 'default']);
        $f->tick(21602); $f->tick(40000); $f->api->respond($f->detail()); $f->tick(40001);
        m2assert($f->status($id)['intelligence_eligible'], 'Fresh successful checks recover without restart.');
    }

    foreach ([
        'buy' => [[], null, true],
        'hold' => [['action' => 'hold'], 'server_hold', false],
        'abstention' => [['action' => 'hold', 'reason' => 'knn_abstention', 'evidence_status' => 'abstaining', 'confidence' => null], 'knn_abstention', false],
    ] as $name => [$changes, $warning, $eligible]) {
        $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
        $detail = $f->detail();
        $detail['market']['signal'] = array_replace($detail['market']['signal'], $changes);
        $f->api->respond($detail); $f->tick(20001);
        $market = $f->status($id);
        m2assert($market['signal']['id'] === 'signal-1' && $market['warning'] === $warning
            && $market['intelligence_eligible'] === $eligible, 'Recovery '.$name.' starts with a cached Server result.');

        $f->runtime->command('server-refresh', []); $f->tick(20600);
        $f->api->respond(null, 0, 'transport_error'); $f->tick(20601);
        $market = $f->status($id);
        m2assert($market['state'] === 'ACTIVE' && $market['warning'] === 'transport_error'
            && $market['signal'] === null && $market['received_at_ms'] === null
            && !$market['intelligence_eligible'] && !$market['trade_authorized']
            && $f->runtime->opportunities() === [], 'Failure discards cached '.$name.' evidence without stopping the market.');

        $f->tick(21601); $f->api->respond($f->catalog); $f->tick(21602);
        m2assert($f->runtime->status()['warning'] === 'transport_error'
            && $f->runtime->opportunities() === [], 'Catalog alone cannot recover '.$name.' without fresh risk context.');
        $f->tick(22201);
        $f->api->respond(['api_version' => 1, 'risk_factor' => '0.25', 'algorithm_version' => 'questionnaire-risk-v1', 'source' => 'default']);
        $f->tick(22202);
        $market = $f->status($id);
        m2assert($f->runtime->status()['warning'] === null && $market['state'] === 'ACTIVE'
            && $market['warning'] === 'awaiting_intelligence' && $market['signal'] === null
            && $market['received_at_ms'] === null && !$market['intelligence_eligible']
            && !$market['trade_authorized'] && $f->runtime->opportunities() === [],
            'Recovered context awaits new intelligence instead of retaining the old '.$name.' status.');
        m2assert(count(array_filter($f->api->sent, static fn(array $request): bool => $request['kind'] === 'market')) === 1,
            'Context recovery does not fetch '.$name.' detail before the next scheduled check.');

        $f->tick(40000); $detail = $f->detail();
        $detail['market']['signal'] = array_replace($detail['market']['signal'], $changes, ['id' => 'signal-after-recovery']);
        $f->api->respond($detail); $f->tick(40001);
        $market = $f->status($id);
        m2assert($market['signal']['id'] === 'signal-after-recovery' && $market['warning'] === $warning
            && $market['received_at_ms'] === RuntimeFixture::BASE + 40001
            && $market['state'] === 'ACTIVE' && $market['intelligence_eligible'] === $eligible
            && !$market['trade_authorized'] && count($f->runtime->opportunities()) === (int) $eligible,
            'A new scheduled detail restores the actual '.$name.' result after recovery.');
    }

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $f->runtime->command('market-stop', ['market' => $id]);
    $f->api->respond(null, 429, 'rate_limited', 42000); $f->tick(20001);
    m2assert($f->runtime->status()['retry_at_ms'] === RuntimeFixture::BASE + 62001, 'Retry-After still applies to a request stopped while in flight.');
    $sent = count($f->api->sent); $f->tick(62000);
    m2assert(count($f->api->sent) === $sent, 'Global Retry-After suppresses all requests.');

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $f->api->respond(null, 404, 'subscription_not_found'); $f->tick(20001);
    m2assert($f->status($id)['state'] === 'STOPPED', 'Per-market missing subscription stops the selected market.');

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $long = $f->detail(); $long['market']['signal']['expires_at_ms'] = $f->now + 3600000;
    $f->api->respond($long); $f->tick(20001); $f->tick(60601);
    $f->now = RuntimeFixture::BASE + 70602;
    m2assert($f->runtime->opportunities() === [] && !$f->status($id)['intelligence_eligible'], 'Hanging refresh cannot extend subscription/risk authorization indefinitely.');
    m2assert($f->status($id)['received_at_ms'] === null, 'Expired authorization context discards cached evidence.');
    m2assert($f->status($id)['state'] === 'ACTIVE', 'Expired operational context preserves operator-selected ACTIVE state.');
    $f->now = RuntimeFixture::BASE + 60000;
    m2assert($f->runtime->opportunities() === [] && !$f->status($id)['intelligence_eligible'], 'Clock rollback cannot revive expired subscription and risk context.');
    m2reject(fn() => $f->activate('sub-2'), 'Clock rollback cannot authorize activation with an expired catalog.');

    $replacements = [
        'stale' => [['is_fresh' => false], 'signal_stale'],
        'superseded' => [['is_current_model' => false], 'signal_superseded'],
        'hold' => [['action' => 'hold'], 'server_hold'],
        'abstention' => [['action' => 'hold', 'reason' => 'holdout_failed', 'evidence_status' => 'abstaining', 'confidence' => null], 'holdout_failed'],
        'no_model' => [['model_id' => null, 'is_current_model' => false], 'signal_superseded'],
        'unknown_expiry' => [['is_fresh' => false, 'expires_at_ms' => null, 'decision_at_ms' => null], 'signal_stale'],
        'wrong_signal_period' => [['period' => '5m'], 'signal_period_mismatch'],
        'null_signal' => [null, 'signal_unavailable'],
    ];
    foreach ($replacements as $name => [$changes, $warning]) {
        $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
        $f->api->respond($f->detail()); $f->tick(20001);
        m2assert(count($f->runtime->opportunities()) === 1, 'Replacement '.$name.' starts with eligible evidence.');
        $f->tick(40000); $replacement = $f->detail();
        $replacement['market']['signal'] = $changes === null ? null : array_replace($replacement['market']['signal'], $changes);
        $f->api->respond($replacement); $f->tick(40001);
        m2assert($f->runtime->opportunities() === [] && $f->status($id)['warning'] === $warning
            && $f->status($id)['state'] === 'ACTIVE', 'New '.$name.' detail replaces previous eligible cache normally.');
    }

    foreach (['missing_freshness', 'future_decision', 'future_recording', 'legacy_envelope'] as $malformed) {
        $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
        $bad = $f->detail();
        if ($malformed === 'missing_freshness') { unset($bad['market']['signal']['is_fresh']); }
        elseif ($malformed === 'future_decision') {
            $bad['market']['signal']['decision_at_ms'] = $f->now + 5002;
            $bad['market']['signal']['recorded_at_ms'] = $f->now + 5002;
        } elseif ($malformed === 'future_recording') { $bad['market']['signal']['recorded_at_ms'] = $f->now + 5002; }
        else { $bad['market']['intelligence'] = ['eligible' => true]; unset($bad['market']['signal']); }
        $f->api->respond($bad); $f->tick(20001);
        m2assert($f->runtime->opportunities() === [] && $f->status($id)['warning'] === 'invalid_intelligence_contract', 'Malformed '.$malformed.' never becomes local evidence.');
    }

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $f->api->respond($f->detail('sub-1', 'XRP/USD')); $f->tick(20001);
    m2assert(!$f->status($id)['intelligence_eligible'] && $f->status($id)['warning'] === 'invalid_intelligence_contract', 'Cross-symbol detail cannot authorize an opportunity.');
    $f->tick(40000); $f->api->respond($f->detail('sub-1', 'ATOM/USD', '5m')); $f->tick(40001);
    m2assert($f->status($id)['warning'] === 'candle_period_changed' && $f->runtime->opportunities() === [], 'Period drift requires fresh catalog and timetable.');
    $f->tick(40600);
    $f->catalog['markets'][0]['period'] = '5m'; $f->api->respond($f->catalog); $f->tick(40601);
    m2assert($f->status($id)['id'] === $id && $f->status($id)['schedule']['period'] === '5m'
        && (int) $f->db->pdo()->query('SELECT COUNT(*) FROM markets')->fetchColumn() === 1, 'A changed period does not create another market identity.');
    $f->runtime->command('server-refresh', []); $f->tick(41200);
    $f->catalog['markets'][0]['period'] = '30s'; $f->api->respond($f->catalog); $f->tick(41201);
    m2assert($f->status($id)['schedule'] === null && $f->status($id)['warning'] === 'unsupported_candle_period', 'Unsupported new period removes the previous schedule.');

    $f = new RuntimeFixture(); $f->ready(); $id = $f->activate('sub-1', 4);
    $second = $f->ledger->createAccount('bitso', 'second account');
    $secondMarket = $f->activate('sub-1', null, $second);
    m2assert($secondMarket !== $id, 'Same subscription in two local exchange accounts keeps distinct identities.');
    $f->runtime->command('market-stop', ['market' => $id]); $f->activate();
    m2assert($f->status($id)['checks_per_candle'] === 4, 'Explicit market check override survives stop and reactivation.');
    $f->runtime->command('market-checks', ['market' => $id, 'checks' => 'default']);
    m2assert($f->status($id)['checks_per_candle'] === null && $f->status($id)['schedule']['checks_per_candle'] === 3, 'Default command clears per-market override.');
    $restarted = new Runtime($f->db, $f->settings, new RuntimeFixtureApi(fn(): int => $f->now), fn(): int => $f->now);
    m2assert($restarted->status()['catalog_fetched_at_ms'] === null && $restarted->opportunities() === [], 'Restart rebuilds catalog and operational cache from scratch.');
    m2assert($restarted->command('markets', [])['markets'][0]['state'] === 'ACTIVE', 'Restart retains durable market selection.');
    $f->runtime->reconfigure($f->settings);
    m2assert($f->runtime->status()['catalog_fetched_at_ms'] === null && $f->runtime->opportunities() === [], 'Reload invalidates old credential context and opportunities.');
    $f->runtime->reconfigure(array_replace($f->settings, ['enabled' => false])); $f->tick(20000);
    m2assert($f->status($id)['state'] === 'ACTIVE' && $f->status($id)['warning'] === 'server_disabled' && $f->api->pending() === 0, 'Disabling Server integration leaves selection and suppresses network.');

    echo json_encode(['status' => 'passed', 'assertions' => $assertions, 'network_requests' => 0, 'exchange_orders_submitted' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'assertions' => $assertions, 'error' => $error->getMessage(), 'line' => $error->getLine()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
