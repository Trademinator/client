<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fixture-token') {
        http_response_code(401); echo '{"error":{"code":"unauthenticated","message":"fixture"}}'; return;
    }
    $path = $_SERVER['REQUEST_URI'];
    if (str_ends_with($path, '/redirect')) { http_response_code(302); header('Location: /api/v1/client/markets'); echo '{}'; return; }
    if (str_ends_with($path, '/large')) { echo str_repeat('x', 2048); return; }
    if (str_ends_with($path, '/slow')) { usleep(400000); }
    echo json_encode(['api_version'=>1, 'markets'=>[], 'auth_ok'=>true,
        'observed_method'=>$_SERVER['REQUEST_METHOD'], 'observed_body'=>file_get_contents('php://input'),
        'observed_query'=>$_SERVER['QUERY_STRING'] ?? ''], JSON_UNESCAPED_SLASHES); return;
}

require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M2\Intelligence;
use Trademinator\Client\M2\ServerApi;
use Trademinator\Client\M2\Transport;

$passed = 0; $failed = 0;
function check(bool $condition, string $name): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo 'PASS '.$name.PHP_EOL; }
    else { $failed++; echo 'FAIL '.$name.PHP_EOL; }
}
function rejects(callable $operation): bool { try { $operation(); return false; } catch (Throwable) { return true; } }
function detail(int $now): array {
    return ['api_version' => 1, 'market' => ['subscription_id' => 'sub-1', 'active' => true,
        'exchange' => ['class' => 'bitso', 'name' => 'Bitso'], 'symbol' => 'ATOM/USD', 'period' => '15m',
        'signal' => ['id' => 'signal-1', 'period' => '15m', 'model_id' => 'model-1', 'action' => 'buy', 'reason' => 'supported',
            'is_current_model' => true, 'is_fresh' => true, 'expires_at_ms' => $now + 300000,
            'evidence_status' => 'supported', 'action_meaning' => 'supported_buy_by_outcome_action_matrix', 'confidence' => 0.8,
            'decision_at_ms' => $now - 900000, 'recorded_at_ms' => $now - 1000, 'reference_price' => '5.25000000',
            'reference_price_source' => 'closed_candle_close', 'prediction_input_basis' => 'completed_candle', 'scoring' => null]]];
}
final class ApiFixtureTransport {
    public array $sent = []; public array $responses = [];
    public function start(string $id, string $path): void { $this->sent[] = [$id, $path]; }
    public function poll(): array { $rows = $this->responses; $this->responses = []; return $rows; }
    public function pending(): int { return 0; }
    public function cancelAll(): void { $this->responses = []; }
}
function response(string $id, int $status, string $body, array $headers = [], ?string $error = null): array {
    return ['request_id' => $id, 'status' => $status, 'body' => $body, 'headers' => $headers, 'error' => $error];
}
function drain(Transport $transport, float $seconds = 3): array {
    $until = microtime(true) + $seconds; $result = [];
    do { $result = array_merge($result, $transport->poll()); if ($transport->pending() === 0) { return $result; } usleep(1000); }
    while (microtime(true) < $until);
    throw new RuntimeException('Fixture transport did not complete within its bound.');
}

$process = null; $temporary = sys_get_temp_dir().'/tm2-api-'.bin2hex(random_bytes(5));
mkdir($temporary, 0700, true);
try {
    foreach (['http://example.com', 'https://user:password@example.com', 'https://example.com/path',
        'https://example.com?secret=x', 'https://example.com#x', "https://example.com\n", 'ftp://example.com'] as $url) {
        check(rejects(fn() => Transport::validateOrigin($url)), 'reject unsafe Server origin '.parse_url($url, PHP_URL_SCHEME));
    }
    check(rejects(fn() => Transport::validateOrigin('http://127.0.0.1')), 'loopback HTTP requires explicit test option');
    Transport::validateOrigin('https://server.trademinator.com');
    Transport::validateOrigin('http://127.0.0.1:12345', true);
    check(true, 'HTTPS origin and explicit loopback fixture accepted');
    $transport = new ApiFixtureTransport(); $api = new ServerApi($transport);
    $api->markets('catalog'); $api->risk('risk'); $api->market('detail', 'sub-1');
    check($transport->sent === [['catalog', '/api/v1/client/markets'], ['risk', '/api/v1/client/risk-factor'],
        ['detail', '/api/v1/client/markets/sub-1']], 'only documented GET endpoints queued');
    check(rejects(fn() => $api->market('injection', '../risk-factor')), 'subscription cannot inject a URL path');
    $now = 1800000000000;
    $transport->responses = [response('ok', 200, '{"api_version":1,"markets":[]}'), response('bad', 200, '{broken'),
        response('future', 200, '{"api_version":2}'), response('list', 200, '[]'),
        response('account', 403, '{"error":{"code":"account_unavailable","message":"fixture-token"}}'),
        response('limited', 429, '{"error":{"code":"authentication_rate_limited"}}', ['retry-after' => '42']),
        response('network', 0, '', [], 'https://fixture-token@private.example')];
    $rows = $api->poll($now);
    check($rows[0]['data']['markets'] === [] && $rows[0]['error'] === null, 'versioned JSON response decoded');
    check($rows[1]['error'] === 'invalid_json' && $rows[2]['error'] === 'unsupported_api_version' && $rows[3]['error'] === 'invalid_json', 'malformed and future contracts fail closed');
    check($rows[4]['error_code'] === 'account_unavailable' && $rows[4]['data'] === null, 'account outage remains distinguishable without copying remote message');
    check($rows[5]['retry_after_ms'] === 42000 && $rows[6]['error'] === 'transport_error'
        && !str_contains(json_encode($rows), 'fixture-token'), 'backoff and sanitized network errors preserve credential privacy');
    check(ServerApi::retryAfterMs(gmdate('D, d M Y H:i:s \G\M\T', (int) ($now / 1000) + 90), $now) === 90000
        && ServerApi::retryAfterMs('tomorrow', $now) === 60000, 'HTTP-date Retry-After parsed without relative date guesses');
    $market = ['subscription_id' => 'sub-1', 'active' => false, 'exchange' => ['class' => 'bitso', 'name' => 'Bitso'],
        'symbol' => 'ATOM/USD', 'period' => '15m', 'latest_signal_id' => null];
    $catalog = ServerApi::validateMarkets(['api_version' => 1, 'markets' => [$market]]);
    check($catalog[0]['is_active'] === false && $catalog[0]['exchange'] === 'bitso', 'inactive subscriptions remain in normalized catalog');
    check(rejects(fn() => ServerApi::validateMarkets(['api_version' => 1, 'markets' => [$market, $market]])), 'duplicate catalog identity rejected atomically');
    check(rejects(fn() => ServerApi::validateMarkets(['api_version' => 1, 'markets' => [array_replace($market, ['active' => 'true'])]])), 'catalog rejects coerced active flag');
    $risk = ['api_version' => 1, 'risk_factor' => '0.2500000000000000', 'algorithm_version' => 'questionnaire-risk-v1', 'source' => 'default'];
    check(ServerApi::validateRisk($risk)['risk_factor'] === '0.2500000000000000', 'Server default risk retained exactly as decimal');
    foreach (['0', '0.00999999999999999999999999999999', '1.00000000000000000000000000000001', 0.25, '-0.5'] as $value) {
        check(rejects(fn() => ServerApi::validateRisk(array_replace($risk, ['source' => 'questionnaire', 'risk_factor' => $value]))), 'out-of-range or nondecimal risk rejected');
    }
    check(ServerApi::validateRisk(array_replace($risk, ['source' => 'questionnaire', 'risk_factor' => '0.01']))['risk_factor'] === '0.01', 'risk floor accepted exactly');
    $good = detail($now);
    $verdict = Intelligence::validate($good, 'sub-1', '15m', $now, 5000, 'bitso', 'ATOM/USD');
    check($verdict['eligible'] && $verdict['action'] === 'buy' && $verdict['trade_authorized'] === false,
        'fresh supported BUY is eligible evidence but never trade authorization');
    check($verdict['signal']['id'] === 'signal-1' && $verdict['detail']['market']['subscription_id'] === 'sub-1',
        'validated source signal and market detail remain available');
    check(Intelligence::validate($good, 'sub-1', '15m', $now + 1000)['eligible'], 'same valid signal remains reevaluable');
    check(Intelligence::validate($good, 'sub-1', '15m', $now + 30000)['eligible'], 'no invented thirty-second TTL invalidates Server evidence');
    $expired = Intelligence::validate($good, 'sub-1', '15m', $now + 300000);
    check(!$expired['eligible'] && $expired['action'] === 'hold' && $expired['reason'] === 'intelligence_expired',
        'local expiry invalidates cached opportunity at its exact boundary');
    check(rejects(fn() => Intelligence::validate($good, 'other-subscription', '15m', $now)), 'cross-subscription intelligence rejected');
    check(rejects(fn() => Intelligence::validate($good, 'sub-1', '15m', $now, 5000, 'kraken', 'ATOM/USD')), 'exchange mismatch rejected');
    check(rejects(fn() => Intelligence::validate($good, 'sub-1', '15m', $now, 5000, 'bitso', 'XRP/USD')), 'symbol mismatch rejected');
    $changedPeriod = Intelligence::validate($good, 'sub-1', '5m', $now);
    check(!$changedPeriod['eligible'] && $changedPeriod['reason'] === 'candle_period_changed' && $changedPeriod['action'] === 'hold',
        'selected period changes are valid detail but invalidate actionable evidence');
    $signalPeriod = $good; $signalPeriod['market']['signal']['period'] = '5m';
    $changedSignal = Intelligence::validate($signalPeriod, 'sub-1', '15m', $now);
    check(!$changedSignal['eligible'] && $changedSignal['reason'] === 'signal_period_mismatch', 'signal period must match the selected candle period');
    $inactive = $good; $inactive['market']['active'] = false;
    $inactiveVerdict = Intelligence::validate($inactive, 'sub-1', '15m', $now);
    check(!$inactiveVerdict['eligible'] && $inactiveVerdict['action'] === 'hold' && $inactiveVerdict['reason'] === 'inactive_subscription',
        'inactive subscription is accepted as valid but ineligible detail');
    $stale = $good; $stale['market']['signal']['is_fresh'] = false;
    $staleVerdict = Intelligence::validate($stale, 'sub-1', '15m', $now);
    check(!$staleVerdict['eligible'] && $staleVerdict['reason'] === 'signal_stale', 'Server stale flag invalidates evidence despite a future expiry');
    $superseded = $good; $superseded['market']['signal']['is_current_model'] = false;
    $supersededVerdict = Intelligence::validate($superseded, 'sub-1', '15m', $now);
    check(!$supersededVerdict['eligible'] && $supersededVerdict['reason'] === 'signal_superseded', 'superseded model evidence cannot be actionable');
    $unavailable = $good; $unavailable['market']['signal'] = null;
    $unavailableVerdict = Intelligence::validate($unavailable, 'sub-1', '15m', $now);
    check(!$unavailableVerdict['eligible'] && $unavailableVerdict['reason'] === 'signal_unavailable', 'null signal is valid unavailable evidence');
    $unavailable['market']['period'] = null;
    check(!Intelligence::validate($unavailable, 'sub-1', null, $now)['eligible'], 'waiting market with no selected period and no signal is valid');
    $hold = $good; $hold['market']['signal']['action'] = 'hold'; $hold['market']['signal']['action_meaning'] = null;
    $holdVerdict = Intelligence::validate($hold, 'sub-1', '15m', $now);
    check(!$holdVerdict['eligible'] && $holdVerdict['action'] === 'hold' && $holdVerdict['reason'] === 'server_hold', 'supported HOLD remains HOLD evidence');
    $sell = $good; $sell['market']['signal']['action'] = 'sell';
    $sell['market']['signal']['action_meaning'] = 'supported_sell_by_outcome_action_matrix';
    $sellVerdict = Intelligence::validate($sell, 'sub-1', '15m', $now);
    check($sellVerdict['eligible'] && $sellVerdict['action'] === 'sell' && !$sellVerdict['trade_authorized'], 'supported SELL is eligible evidence without execution authorization');
    $degraded = $good; $degraded['market']['signal']['reason'] = 'degraded_action_only';
    $degraded['market']['signal']['evidence_status'] = 'degraded';
    $degraded['market']['signal']['action_meaning'] = 'degraded_buy_by_action_knn_without_outcome_confirmation';
    check(Intelligence::validate($degraded, 'sub-1', '15m', $now)['eligible'], 'degraded Action KNN BUY retains eligible evidence semantics');
    $abstaining = $hold; $abstaining['market']['signal']['reason'] = 'holdout_failed';
    $abstaining['market']['signal']['evidence_status'] = 'abstaining'; $abstaining['market']['signal']['confidence'] = null;
    $abstentionVerdict = Intelligence::validate($abstaining, 'sub-1', '15m', $now);
    check(!$abstentionVerdict['eligible'] && $abstentionVerdict['action'] === 'hold' && $abstentionVerdict['reason'] === 'holdout_failed',
        'abstention preserves its reason while returning HOLD');
    $abstaining['market']['signal']['action'] = 'buy';
    check(rejects(fn() => Intelligence::validate($abstaining, 'sub-1', '15m', $now)), 'abstention cannot masquerade as eligible BUY');
    $unknownReason = $good; $unknownReason['market']['signal']['reason'] = 'unknown_future_reason';
    $unknownReason['market']['signal']['evidence_status'] = 'abstaining';
    check(rejects(fn() => Intelligence::validate($unknownReason, 'sub-1', '15m', $now)), 'unknown reason cannot authorize directional evidence');
    $wrongEvidence = $good; $wrongEvidence['market']['signal']['evidence_status'] = 'abstaining';
    check(rejects(fn() => Intelligence::validate($wrongEvidence, 'sub-1', '15m', $now)), 'supported reason requires supported evidence status');
    $wrongEvidence = $degraded; $wrongEvidence['market']['signal']['evidence_status'] = 'supported';
    check(rejects(fn() => Intelligence::validate($wrongEvidence, 'sub-1', '15m', $now)), 'degraded reason requires degraded evidence status');
    $oldButFresh = $good; $oldButFresh['market']['signal']['decision_at_ms'] = $now - 7200000;
    check(Intelligence::validate($oldButFresh, 'sub-1', '15m', $now)['eligible'], 'authenticated Server freshness remains authoritative over guessed candle ages');
    $longExpiry = $good; $longExpiry['market']['signal']['expires_at_ms'] = $now + 86400000;
    check(Intelligence::validate($longExpiry, 'sub-1', '15m', $now)['eligible'], 'Server expiry is not limited by a guessed client TTL');
    $futureRecorded = $good; $futureRecorded['market']['signal']['recorded_at_ms'] = $now + 5000;
    check(Intelligence::validate($futureRecorded, 'sub-1', '15m', $now)['eligible'], 'recording timestamp accepts the exact clock-skew boundary');
    $futureRecorded['market']['signal']['recorded_at_ms'] = $now + 5001;
    check(rejects(fn() => Intelligence::validate($futureRecorded, 'sub-1', '15m', $now)), 'future recording beyond clock tolerance rejected');
    $futureDecision = $good; $futureDecision['market']['signal']['decision_at_ms'] = $now + 5001;
    $futureDecision['market']['signal']['recorded_at_ms'] = $now + 5001;
    check(rejects(fn() => Intelligence::validate($futureDecision, 'sub-1', '15m', $now)), 'future decision beyond clock tolerance rejected');
    $zeroLifetime = $good; $zeroLifetime['market']['signal']['decision_at_ms'] = $now + 1000;
    $zeroLifetime['market']['signal']['recorded_at_ms'] = $now + 1000;
    $zeroLifetime['market']['signal']['expires_at_ms'] = $now + 1000;
    check(rejects(fn() => Intelligence::validate($zeroLifetime, 'sub-1', '15m', $now)),
        'fresh signal cannot have zero lifetime even inside clock-skew tolerance');
    $zeroLifetime['market']['signal']['is_fresh'] = false;
    $zeroLifetimeVerdict = Intelligence::validate($zeroLifetime, 'sub-1', '15m', $now);
    check(!$zeroLifetimeVerdict['eligible'] && $zeroLifetimeVerdict['reason'] === 'signal_stale',
        'zero-lifetime stale evidence remains valid and ineligible');
    $reversed = $good; $reversed['market']['signal']['recorded_at_ms'] = $reversed['market']['signal']['decision_at_ms'] - 1;
    check(rejects(fn() => Intelligence::validate($reversed, 'sub-1', '15m', $now)), 'recording cannot predate its decision candle');
    foreach (['is_fresh', 'is_current_model'] as $flag) {
        $missing = $good; unset($missing['market']['signal'][$flag]);
        check(rejects(fn() => Intelligence::validate($missing, 'sub-1', '15m', $now)), 'missing required '.$flag.' rejected');
        foreach (['true', 1, null] as $invalidFlag) {
            $coerced = $good; $coerced['market']['signal'][$flag] = $invalidFlag;
            check(rejects(fn() => Intelligence::validate($coerced, 'sub-1', '15m', $now)), 'nonboolean '.$flag.' rejected: '.json_encode($invalidFlag));
        }
    }
    foreach (['expires_at_ms', 'decision_at_ms'] as $field) {
        $missingFreshness = $good; $missingFreshness['market']['signal'][$field] = null;
        check(rejects(fn() => Intelligence::validate($missingFreshness, 'sub-1', '15m', $now)), 'fresh evidence requires '.$field);
    }
    $missingModel = $good; $missingModel['market']['signal']['model_id'] = null;
    check(rejects(fn() => Intelligence::validate($missingModel, 'sub-1', '15m', $now)), 'current-model evidence requires a model identity');
    $nullableStale = $good; $nullableStale['market']['signal']['is_fresh'] = false;
    $nullableStale['market']['signal']['is_current_model'] = false;
    $nullableStale['market']['signal']['expires_at_ms'] = null; $nullableStale['market']['signal']['decision_at_ms'] = null;
    $nullableStale['market']['signal']['model_id'] = null;
    check(!Intelligence::validate($nullableStale, 'sub-1', '15m', $now)['eligible'], 'unavailable freshness and model metadata are valid only as ineligible evidence');
    foreach (['action' => 'BUY', 'recorded_at_ms' => (string)$now, 'decision_at_ms' => (float)$now,
        'expires_at_ms' => 'tomorrow', 'confidence' => '0.8'] as $field => $invalidValue) {
        $malformed = $good; $malformed['market']['signal'][$field] = $invalidValue;
        check(rejects(fn() => Intelligence::validate($malformed, 'sub-1', '15m', $now)), 'malformed signal '.$field.' rejected');
    }
    $invalidActive = $good; $invalidActive['market']['active'] = 'true';
    check(rejects(fn() => Intelligence::validate($invalidActive, 'sub-1', '15m', $now)), 'detail active flag cannot be coerced');
    foreach ([['api_version'=>1], ['api_version'=>1,'market'=>[]], ['api_version'=>1,'market'=>'bad'],
        ['api_version'=>2,'market'=>$good['market']]] as $malformed) {
        check(rejects(fn() => Intelligence::validate($malformed, 'sub-1', '15m', $now)), 'malformed detail envelope rejected');
    }

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($listener)) { throw new RuntimeException('Cannot allocate loopback fixture port.'); }
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $process = proc_open([PHP_BINARY, '-S', $address, __FILE__],
        [0 => ['pipe', 'r'], 1 => ['file', $temporary.'/stdout', 'a'], 2 => ['file', $temporary.'/stderr', 'a']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot launch HTTP fixture.'); }
    fclose($pipes[0]);
    $until = microtime(true) + 5; $ready = false;
    do { $probe = @stream_socket_client('tcp://'.$address, $errno, $error, 0.05);
        if (is_resource($probe)) { fclose($probe); $ready = true; break; } usleep(10000);
    } while (microtime(true) < $until);
    if (!$ready) { throw new RuntimeException('HTTP fixture did not start.'); }
    $live = new Transport('http://'.$address, 'fixture-token', 2000, 1024, true);
    $live->start('authenticated', '/api/v1/client/markets');
    $liveRows = drain($live);
    $observed = json_decode($liveRows[0]['body'], true);
    check($liveRows[0]['status'] === 200 && $observed['auth_ok'], 'real curl transport sends Bearer authentication');
    check($observed['observed_method'] === 'GET', 'real curl Server request uses GET');
    check($observed['observed_body'] === '', 'real curl Server request has no body');
    check($observed['observed_query'] === '', 'real curl Server request has no query string');
    $live->start('redirect', '/api/v1/client/markets/redirect');
    $liveRows = drain($live);
    check($liveRows[0]['status'] === 302, 'real curl transport never follows redirect');
    $live->start('large', '/api/v1/client/markets/large');
    $liveRows = drain($live);
    check($liveRows[0]['error'] === 'response_too_large' && $liveRows[0]['body'] === '', 'response cap aborts and discards oversized body');
    ob_start(); var_dump($live); $debug = ob_get_clean();
    check(!str_contains($debug, 'fixture-token') && rejects(fn() => serialize($live)), 'transport credentials hidden from debug and serialization');
    $slow = new Transport('http://'.$address, 'fixture-token', 100, 1024, true);
    $slow->start('slow', '/api/v1/client/markets/slow');
    $started = microtime(true); $slow->poll(); $elapsed = microtime(true) - $started;
    check($elapsed < 0.15 && $slow->pending() === 1, 'network wait does not block daemon tick');
    $liveRows = drain($slow);
    check($liveRows[0]['error'] === 'transport_error', 'timeout fails closed with sanitized error');
    $live->cancelAll(); $slow->cancelAll();
} catch (Throwable $error) { check(false, 'test exception: '.$error->getMessage()); }
finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (glob($temporary.'/*') ?: [] as $file) { unlink($file); } rmdir($temporary);
}
echo json_encode(['suite' => 'm2-api', 'passed' => $passed, 'failed' => $failed], JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed === 0 ? 0 : 1);
