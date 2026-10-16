<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;
use Trademinator\Client\M2\Runtime;

/** Network-free fixture with an explicit asynchronous request/response boundary. */
final class EventFixtureApi
{
    private ?string $request = null;
    private array $responses = [];
    public array $sent = [];
    private function start(string $id, string $kind): void
    {
        if ($this->request !== null) { throw new RuntimeException('Overlapping fixture requests.'); }
        $this->request = $id;
        $this->sent[] = $kind;
    }
    public function markets(string $id): void { $this->start($id, 'catalog'); }
    public function risk(string $id): void { $this->start($id, 'risk'); }
    public function market(string $id, string $subscription): void { $this->start($id, 'market'); }
    public function pending(): int { return $this->request === null ? 0 : 1; }
    public function cancelAll(): void { $this->request = null; $this->responses = []; }
    public function respond(?array $data, int $status = 200, ?string $code = null): void
    {
        if ($this->request === null) { throw new RuntimeException('No fixture request pending.'); }
        $this->responses[] = ['request_id' => $this->request, 'status' => $status, 'data' => $data,
            'error' => $status === 200 ? null : 'http_'.$status, 'error_code' => $code];
    }
    public function poll(?int $nowMs = null): array
    {
        $responses = $this->responses;
        if ($responses !== []) { $this->request = null; $this->responses = []; }
        return $responses;
    }
}

final class EventFixture
{
    public const BASE = 1800000000000;
    public int $now = self::BASE;
    public Database $db;
    public Ledger $ledger;
    public EventFixtureApi $api;
    public Runtime $runtime;
    public array $events = [];
    public array $settings;
    public array $catalog;
    public string $account;
    public function __construct(bool $brokenSink = false)
    {
        $this->db = new Database(':memory:');
        $this->ledger = new Ledger($this->db);
        $this->account = $this->ledger->createAccount('bitso', 'private-account-label', null, ['USD' => '943827.31']);
        $this->settings = ['enabled' => true, 'url' => 'https://fixture.invalid', 'token' => 'credential-secret-value',
            'timeout_ms' => 10000, 'catalog_interval_ms' => 60000, 'risk_interval_ms' => 60000,
            'checks_per_candle' => 3, 'min_request_interval_ms' => 600, 'retry_base_ms' => 1000, 'retry_max_ms' => 60000];
        $this->catalog = ['api_version' => 1, 'markets' => [
            ['subscription_id' => 'sub-1', 'active' => true, 'exchange' => ['class' => 'bitso', 'name' => 'Bitso'],
                'symbol' => 'ATOM/USD', 'period' => '1m', 'latest_signal_id' => null],
        ]];
        $this->api = new EventFixtureApi();
        $this->runtime = new Runtime($this->db, $this->settings, $this->api, fn(): int => $this->now,
            function (string $level, string $event, array $context, string $message) use ($brokenSink): void {
                if ($brokenSink) { throw new RuntimeException('Fixture output unavailable.'); }
                $this->events[] = compact('level', 'event', 'context', 'message');
            });
    }
    public function tick(int $offset): void { $this->now = self::BASE + $offset; $this->runtime->tick(); }
    public function risk(): array { return ['api_version' => 1, 'risk_factor' => '0.25', 'algorithm_version' => 'questionnaire-risk-v1', 'source' => 'default']; }
    public function ready(): void
    {
        $this->tick(0); $this->api->respond($this->catalog); $this->tick(1);
        $this->tick(600); $this->api->respond($this->risk()); $this->tick(601);
        // Activate after the previous candle's final tolerance window has closed.
        $this->now = self::BASE + 1200;
    }
    public function activate(): string
    {
        return $this->runtime->command('market-activate', ['account' => $this->account, 'subscription' => 'sub-1'])['market']['id'];
    }
    public function detail(): array
    {
        return ['api_version' => 1, 'market' => [
            'subscription_id' => 'sub-1', 'active' => true, 'exchange' => ['class' => 'bitso'], 'symbol' => 'ATOM/USD', 'period' => '1m',
            'settings' => ['balance' => 'PRIVATE-SERVER-SETTINGS', 'token' => 'credential-secret-value'],
            'signal' => ['id' => 'signal-1', 'period' => '1m', 'model_id' => 'model-1', 'action' => 'buy',
                'reason' => 'supported', 'evidence_status' => 'supported', 'decision_at_ms' => $this->now - 60000,
                'recorded_at_ms' => $this->now - 1000, 'is_current_model' => true, 'is_fresh' => true,
                'expires_at_ms' => $this->now + 30000, 'confidence' => 0.8,
                'scoring' => ['private' => 'RAW-SCORING-VALUE'], 'reference_price' => 'PRIVATE-PRICE-VALUE'],
        ]];
    }
    public function named(string $event): array { return array_values(array_filter($this->events, static fn(array $row): bool => $row['event'] === $event)); }
    public function market(): array { return $this->runtime->command('markets', [])['markets'][0]; }
}

$assertions = 0;
function eventAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
}

try {
    $f = new EventFixture(); $f->ready(); $id = $f->activate();
    eventAssert(count($f->named('server_catalog_refreshed')) === 1, 'Authenticated catalog refresh emits one event.');
    eventAssert($f->named('server_catalog_refreshed')[0]['context']['subscriptions'] === 1, 'Catalog event contains count.');
    eventAssert($f->named('server_risk_refreshed')[0]['context']['risk_factor'] === '0.25', 'Risk refresh exposes R.');
    eventAssert($f->named('server_risk_refreshed')[0]['context']['source'] === 'default', 'Risk refresh exposes source.');
    eventAssert(count($f->named('market_activated')) === 1, 'Activation emits one lifecycle event.');
    $schedule = $f->named('market_schedule_updated')[0]['context'];
    eventAssert($schedule['checks_per_candle'] === 3 && $schedule['next_check_at_ms'] === EventFixture::BASE + 20000, 'Schedule event carries effective checks and next check time.');
    eventAssert($schedule['market_id'] === $id && $schedule['exchange'] === 'bitso' && $schedule['symbol'] === 'ATOM/USD', 'Schedule is scoped to a local market and exchange.');
    $count = count($f->events);
    for ($offset = 1300; $offset < 19900; $offset += 100) { $f->tick($offset); }
    eventAssert(count($f->events) === $count, 'Routine 100 ms ticks emit no event spam.');
    $f->tick(20000); $detail = $f->detail(); $f->api->respond($detail); $f->tick(20001);
    eventAssert(count($f->named('market_check_started')) === 1, 'A scheduled fetch emits a checking event.');
    eventAssert($f->named('market_check_started')[0]['level'] === 'info', 'Scheduled checking is visible without verbose mode.');
    $signal = $f->named('market_signal_received')[0]['context'];
    eventAssert($signal['action'] === 'buy' && $signal['intelligence_eligible'] && !$signal['trade_authorized'], 'Signal event distinguishes evidence eligibility from trade authorization.');
    eventAssert($signal['reason'] === 'supported' && $signal['evidence_status'] === 'supported', 'Signal metadata includes its reason and evidence status.');
    $f->tick(40000); $f->api->respond($detail); $f->tick(40001);
    eventAssert(count($f->named('market_signal_received')) === 2, 'Repeated same signal is reported for every completed scheduled fetch.');
    $f->runtime->command('market-checks', ['market' => $id, 'checks' => 4]);
    eventAssert($f->named('market_checks_updated')[0]['context']['effective_checks_per_candle'] === 4, 'Checks command reports its effective frequency.');
    $f->runtime->command('market-stop', ['market' => $id]);
    eventAssert(count($f->named('market_stopped')) === 1 && $f->market()['state'] === 'STOPPED', 'Stop without open orders reports STOPPED.');
    $f->runtime->command('market-stop', ['market' => $id]);
    eventAssert(count($f->named('market_stopped')) === 1, 'Repeated stop does not invent another transition.');
    $f->activate();
    eventAssert(count($f->named('market_activated')) === 2, 'Reactivation reports the transition again.');
    $order = ['account_id' => $f->account, 'market_id' => $id, 'exchange_order_id' => 'PRIVATE-ORDER-ID',
        'side' => 'BUY', 'quantity' => '1', 'filled_quantity' => '0', 'remaining_quantity' => '1',
        'price' => '10', 'status' => 'open', 'occurred_at' => '2026-10-15T00:00:00Z'];
    $f->ledger->recordOrder($order);
    $f->runtime->command('market-stop', ['market' => $id]);
    eventAssert(count($f->named('market_stopping')) === 1, 'Open order emits STOPPING.');
    $f->ledger->recordOrder(array_replace($order, ['status' => 'cancelled'])); $f->tick(41000);
    eventAssert(count($f->named('market_stopped')) === 2 && $f->market()['state'] === 'STOPPED', 'Authoritative settlement emits the implicit STOPPED transition.');
    $json = json_encode($f->events, JSON_THROW_ON_ERROR);
    foreach (['credential-secret-value', 'PRIVATE-SERVER-SETTINGS', 'RAW-SCORING-VALUE', 'PRIVATE-PRICE-VALUE',
        'private-account-label', '943827.31', 'PRIVATE-ORDER-ID', 'Authorization', '"settings"', '"scoring"'] as $private) {
        eventAssert(!str_contains($json, $private), 'Events omit private/raw value '.$private.'.');
    }

    foreach (['hold', 'abstention', 'stale', 'superseded', 'null', 'invalid', 'period_changed'] as $kind) {
        $f = new EventFixture(); $f->ready(); $f->activate(); $f->tick(20000); $detail = $f->detail();
        if ($kind === 'hold') { $detail['market']['signal']['action'] = 'hold'; }
        elseif ($kind === 'abstention') { $detail['market']['signal'] = array_replace($detail['market']['signal'], ['action' => 'hold', 'reason' => 'knn_abstention', 'evidence_status' => 'abstaining']); }
        elseif ($kind === 'stale') { $detail['market']['signal']['is_fresh'] = false; }
        elseif ($kind === 'superseded') { $detail['market']['signal']['is_current_model'] = false; }
        elseif ($kind === 'null') { $detail['market']['signal'] = null; }
        elseif ($kind === 'invalid') { $detail['market']['signal']['recorded_at_ms'] = 'INVALID-PRIVATE-RAW'; }
        else { $detail['market']['period'] = '5m'; }
        $f->api->respond($detail); $f->tick(20001);
        $eventName = $kind === 'invalid' ? 'market_signal_rejected' : 'market_signal_received';
        eventAssert(count($f->named($eventName)) === 1, $kind.' result emits one explicit outcome event.');
        $event = $f->named($eventName)[0];
        eventAssert(!$event['context']['intelligence_eligible'] && !$event['context']['trade_authorized'], $kind.' outcome is never presented as actionable.');
        eventAssert(!str_contains(json_encode($event), 'INVALID-PRIVATE-RAW'), 'Rejected response values are never included in telemetry.');
    }

    $f = new EventFixture(); $f->ready(); $f->activate(); $f->tick(20000);
    $f->api->respond($f->detail()); $f->tick(20001);
    $f->runtime->command('server-refresh', []); $f->tick(21000);
    $f->api->respond(null, 503, 'service_unavailable'); $f->tick(21001);
    eventAssert(count($f->named('server_connection_lost')) === 1, 'First actual failure reports lost communication.');
    eventAssert($f->named('server_connection_lost')[0]['context']['retry_at_ms'] === EventFixture::BASE + 22001, 'Failure reports planned retry time.');
    eventAssert($f->market()['state'] === 'ACTIVE' && $f->market()['signal'] === null, 'Telemetry leaves fail-closed operational behavior unchanged.');
    $count = count($f->events);
    for ($offset = 21100; $offset < 22000; $offset += 100) { $f->tick($offset); }
    eventAssert(count($f->events) === $count, 'Backoff ticks emit no duplicate failure event.');
    $f->tick(22001); $f->api->respond(null, 503, 'service_unavailable'); $f->tick(22002);
    eventAssert(count($f->named('server_retry_failed')) === 1 && count($f->named('server_connection_lost')) === 1, 'Another actual failure is a retry failure, not another loss transition.');
    $f->tick(24002); $f->api->respond($f->catalog); $f->tick(24003);
    eventAssert($f->named('server_connection_restored') === [], 'Catalog alone does not falsely announce complete recovery.');
    $f->tick(24602); $f->api->respond($f->risk()); $f->tick(24603);
    eventAssert(count($f->named('server_connection_restored')) === 1 && $f->runtime->status()['warning'] === null, 'Valid catalog and risk restore communication once.');
    eventAssert($f->market()['warning'] === 'awaiting_intelligence', 'Recovery retains the corrected empty-cache warning.');
    eventAssert(count($f->named('server_retry_started')) === 3, 'Retry events follow actual queued recovery requests.');

    foreach (['catalog', 'detail', 'http'] as $revocation) {
        $f = new EventFixture(); $f->ready(); $f->activate();
        if ($revocation === 'catalog') {
            $f->runtime->command('server-refresh', []); $f->tick(1200);
            $f->api->respond(['api_version' => 1, 'markets' => []]); $f->tick(1201);
        } else {
            $f->tick(20000); $detail = $f->detail(); $detail['market']['active'] = false;
            $f->api->respond($revocation === 'http' ? null : $detail, $revocation === 'http' ? 404 : 200);
            $f->tick(20001);
        }
        eventAssert(count($f->named('market_stopped')) === 1 && $f->named('market_stopped')[0]['context']['cause'] === 'subscription_unavailable', $revocation.' revocation emits its lifecycle cause.');
    }

    $f = new EventFixture(); $f->ready(); $f->activate(); $f->tick(20000);
    $f->api->respond($f->detail()); $f->tick(20001); $f->now = EventFixture::BASE + 50000;
    $f->runtime->opportunities(); $f->runtime->opportunities();
    eventAssert(count($f->named('market_signal_expired')) === 1, 'Evidence expiration is emitted once when observed.');
    $f->now = EventFixture::BASE + 40000; $f->runtime->status(); $f->runtime->status();
    eventAssert(count($f->named('server_context_invalidated')) === 1 && $f->named('server_context_invalidated')[0]['context']['reason'] === 'clock_rollback', 'Clock rollback context event does not spam status reads.');
    $f->now = EventFixture::BASE + 80000; $f->runtime->status(); $f->runtime->status();
    eventAssert(count($f->named('server_context_invalidated')) === 2 && $f->named('server_context_invalidated')[1]['context']['reason'] === 'subscription_catalog_expired', 'Context expiration emits only its transition.');

    $f = new EventFixture(); $f->ready(); $f->activate(); $f->tick(20000); $detail = $f->detail();
    $detail['market']['signal']['id'] = 'credential-secret-value';
    $f->api->respond($detail); $f->tick(20001);
    eventAssert(!str_contains(json_encode($f->events), 'credential-secret-value'), 'Credential repeated inside selected response metadata is redacted before the sink.');

    $f = new EventFixture(true); $f->ready(); $id = $f->activate(); $f->tick(20000);
    $f->api->respond($f->detail()); $f->tick(20001);
    eventAssert($f->market()['state'] === 'ACTIVE' && $f->market()['intelligence_eligible'], 'Throwing output sink cannot reject valid intelligence or change market state.');
    $f->runtime->command('market-stop', ['market' => $id]);
    eventAssert($f->market()['state'] === 'STOPPED', 'Throwing output sink cannot block a control command.');

    echo json_encode(['status' => 'passed', 'assertions' => $assertions, 'network_requests' => 0, 'exchange_orders_submitted' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'assertions' => $assertions, 'error' => $error->getMessage(), 'line' => $error->getLine()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
