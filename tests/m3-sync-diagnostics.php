<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Database, Ledger};
use Trademinator\Client\M3\{CcxtAccount, Runtime, Settings, SyncDiagnostics};
use React\Promise\Deferred;

use function React\Async\await;
use function React\Promise\resolve;
use function React\Promise\reject;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS '.$message.PHP_EOL;
}

final class DiagnosticRest
{
    public array $has = ['fetchLedger' => true];
    public array $failures = [];
    public mixed $marketsLoading = null;
    public bool $reloadingMarkets = false;
    public int $tradeCalls = 0;
    public ?Deferred $pendingLedger = null;
    public function load_markets(): object
    {
        return resolve(['ATOM/USD' => ['symbol' => 'ATOM/USD', 'spot' => true]]);
    }
    public function fetch_balance(): object
    {
        return resolve(['total' => ['USD' => '100'], 'free' => ['USD' => '100'], 'used' => ['USD' => '0']]);
    }
    public function fetch_my_trades($symbol, $since, $limit, $params): object
    {
        ++$this->tradeCalls;
        $error = array_shift($this->failures);
        return $error === null ? resolve([]) : reject($error);
    }
    public function fetch_open_orders($symbol, $since, $limit, $params): object { return resolve([]); }
    public function fetch_ledger($currency, $since, $limit, $params): object { return $this->pendingLedger?->promise() ?? resolve([]); }
    public function close(): void {}
}

// Use the installed CCXT error parser; no network or exchange account is needed.
$parser = new ccxt\bitso();
$response = ['success' => false, 'error' => ['code' => '0320',
    'message' => "PRIVATE_RESPONSE Authorization: Bearer PRIVATE_TOKEN\nhttps://private.invalid/?signature=PRIVATE_SIGNATURE"],
    'apiKey' => 'PRIVATE_KEY'];
try {
    $parser->handle_errors(400, '', '', 'GET', [], json_encode($response), $response, [], null);
    throw new RuntimeException('Bitso error parser did not reject the response');
} catch (ccxt\ExchangeError $error) {
    $paginationError = $error;
}
$detail = SyncDiagnostics::describe($paginationError, 'bitso');
check($detail['exchange_code'] === '0320' && str_contains($detail['detail'], 'Pagination'), 'pinned CCXT generic ExchangeError retains the Bitso pagination reason');
check(!str_contains(json_encode($detail), 'PRIVATE_'), 'exchange messages, headers, signatures and key values are excluded');
$denied = new ccxt\ExchangeError('bitso {"success":false,"error":{"code":"0202","message":"PRIVATE_MESSAGE"}}');
check(str_contains(SyncDiagnostics::describe($denied, 'bitso')['detail'], 'permission'), 'Bitso permission code is explained even when CCXT uses ExchangeError');
$unknown = new ccxt\ExchangeError('bitso {"success":false,"error":{"code":"9999","message":"PRIVATE_MESSAGE"}}');
check(SyncDiagnostics::describe($unknown, 'bitso')['exchange_code'] === '9999', 'unknown numeric code remains available for investigation');
check(!str_contains(json_encode(SyncDiagnostics::describe(new RuntimeException('PRIVATE_ERROR'), 'bitso')), 'PRIVATE_ERROR'), 'unknown local exception text stays private');
check(SyncDiagnostics::describe(new ccxt\RequestTimeout('PRIVATE_URL'), 'bitso')['reason'] === 'request_timeout', 'network timeout has an explicit reason');
check(SyncDiagnostics::describe(new RuntimeException('Wallet free + used differs from total.'), 'bitso')['detail'] === 'Wallet free + used differs from total.', 'known accounting invariant is reported explicitly');
$malformed = new ccxt\ExchangeError('bitso {"success":false,"error":{"code":"PRIVATE_CODE","message":"PRIVATE_MESSAGE"}}');
check(!str_contains(json_encode(SyncDiagnostics::describe($malformed, 'bitso')), 'PRIVATE_'), 'non-numeric response codes are not published');

// The actual pinned HTTP handler includes private URL/body text in its error.
// The public diagnostic must retain only the status and a local explanation.
$httpErrors = [];
foreach ([403 => 'http_forbidden', 404 => 'http_not_found', 410 => 'http_gone',
    429 => 'http_rate_limit', 500 => 'http_server_error', 502 => 'http_server_error',
    503 => 'http_server_error', 504 => 'http_timeout'] as $status => $reason) {
    try {
        $parser->handle_http_status_code($status, 'PRIVATE_STATUS',
            'https://api.bitso.com/v3/ledger/?key=PRIVATE_KEY&signature=PRIVATE_SIGNATURE',
            'GET', '<html>PRIVATE_BODY bitso bitso https://private.invalid/ GET 403</html>');
        throw new RuntimeException('HTTP handler did not reject status '.$status);
    } catch (ccxt\BaseError $error) {
        $httpErrors[$status] = $error;
    }
    $detail = SyncDiagnostics::describe($httpErrors[$status], 'bitso');
    check(($detail['http_status'] ?? null) === $status && $detail['reason'] === $reason,
        'pinned CCXT HTTP '.$status.' is distinguished from a general exchange outage');
    check(!str_contains(json_encode($detail), 'PRIVATE_') && !str_contains(json_encode($detail), 'https:'),
        'HTTP '.$status.' does not expose URL, signature, status text or response body');
}
foreach ([
    'PRIVATE_PREFIX bitso bitso https://private.invalid/ GET 503 PRIVATE_BODY',
    'bitso bitso https://private.invalid/ GET 200 PRIVATE_BODY GET 503',
    "bitso bitso https://private.invalid/\nGET 503 PRIVATE_BODY",
    'another another https://private.invalid/ GET 503 PRIVATE_BODY',
    'bitso bitso https://private.invalid/'.str_repeat('x', 9000).' GET 503 PRIVATE_BODY',
] as $message) {
    $detail = SyncDiagnostics::describe(new ccxt\ExchangeNotAvailable($message), 'bitso');
    check(!isset($detail['http_status']) && !str_contains(json_encode($detail), 'PRIVATE_'),
        'unrecognized HTTP error layout does not guess a status or disclose text');
}
check(!isset(SyncDiagnostics::describe(new RuntimeException($httpErrors[503]->getMessage()), 'bitso')['http_status']),
    'local errors cannot masquerade as CCXT HTTP failures');

// Real pinned CCXT argument validation, with all network access forbidden by the fixture.
$bitso = new class extends ccxt\async\bitso {
    public int $networkCalls = 0;
    public function fetch($url, $method = 'GET', $headers = null, $body = null)
    {
        ++$this->networkCalls;
        throw new RuntimeException('Unexpected network access in diagnostics test');
    }
};
$bitso->set_markets([['id' => 'atom_usd', 'symbol' => 'ATOM/USD', 'base' => 'ATOM', 'quote' => 'USD',
    'baseId' => 'atom', 'quoteId' => 'usd', 'spot' => true, 'type' => 'spot', 'precision' => [], 'limits' => []]]);
try {
    await($bitso->fetch_my_trades('ATOM/USD', 0, 100));
    throw new RuntimeException('Expected timestamp rejection');
} catch (ccxt\ExchangeError $error) {
    check(SyncDiagnostics::describe($error, 'bitso')['reason'] === 'timestamp_pagination_unsupported', 'real CCXT timestamp rejection explains the required cursor configuration');
}
check($bitso->networkCalls === 0, 'timestamp diagnosis uses no network');
$bitso->close();

$db = new Database(':memory:');
$ledger = new Ledger($db);
$id = $ledger->createAccount('bitso', 'diagnostics', null, ['USD' => '100']);
$market = $ledger->registerMarket($id, 'ATOM/USD', 'ATOM', 'USD');
$db->pdo()->exec("UPDATE markets SET state='ACTIVE'");
$settings = Settings::validate(['accounts' => [['account_id' => $id, 'api_key_env' => 'UNUSED_KEY',
    'secret_env' => 'UNUSED_SECRET', 'history_since_ms' => 0, 'history_params' => [
        'fetch_my_trades' => ['mode' => 'backward_id', 'params' => ['marker' => 'PRIVATE_CURSOR', 'custom' => 'PRIVATE_PARAM']],
    ]]]]);
$account = $settings['accounts'][0];
$rest = new DiagnosticRest();
$adapter = new CcxtAccount('bitso', $settings, $account, $rest);
$rest->failures = [$paginationError];
$context = ['symbols' => ['ATOM/USD'], 'since_ms' => 0, 'known_orders' => []];
try {
    await($adapter->snapshot($context));
    throw new RuntimeException('Expected snapshot failure');
} catch (ccxt\ExchangeError $error) {
    check($error === $paginationError, 'adapter preserves original error identity and classification');
    $call = $adapter->failureContext($error);
    check($call['operation'] === 'fetch_my_trades' && $call['symbol'] === 'ATOM/USD' && $call['page'] === 1, 'failure identifies the exact method, symbol and page');
    check($call['pagination_mode'] === 'backward_id' && $call['since_ms'] === null && $call['cursor_present'], 'failure reports actual pagination mode without cursor contents');
    check(!str_contains(json_encode($call), 'PRIVATE_'), 'request parameters and cursor values stay private');
}
check($adapter->failureContext(new RuntimeException()) === [], 'another exception cannot inherit stale request context');

$rest->failures = [$paginationError];
$events = [];
$runtime = new Runtime($db, $settings, fn() => $adapter, function ($level, $event, $context, $message) use (&$events): void {
    $events[] = ['event' => $event, 'context' => $context, 'message' => $message];
});
$now = (int)floor(microtime(true) * 1000);
$runtime->tick($now);
$state = $runtime->status()['accounts'][$id];
check($state['state'] === 'RECOVERING' && !$state['rest_ok'], 'diagnostics do not authorize trading after failed synchronization');
check($state['last_error']['operation'] === 'fetch_my_trades' && $state['last_error']['exchange_code'] === '0320', 'exchange-status exposes the actionable failure');
$failure = array_values(array_filter($events, fn($event) => $event['event'] === 'exchange_sync_failed'))[0];
check(str_contains($failure['message'], 'bitso fetch_my_trades ATOM/USD (0320)') && $failure['context']['retry_in_ms'] === 1000, 'console event includes operation, code and existing retry delay');
check(!str_contains(json_encode([$events, $runtime->status()]), 'PRIVATE_'), 'neither status nor events expose raw private response or request data');
$runtime->tick($now + 100000);
$state = $runtime->status()['accounts'][$id];
check($state['rest_ok'] && !isset($state['last_error']), 'successful reconciliation clears the stored sync failure');
$runtime->close();

// Keep the ledger request pending, as in a slow or rate-limited startup scan.
// Advance the runtime clock instead of sleeping or making network requests.
$rest = new DiagnosticRest();
$rest->pendingLedger = new Deferred();
$adapter = new CcxtAccount('bitso', $settings, $account, $rest);
$events = [];
$runtime = new Runtime($db, $settings, fn() => $adapter, function ($level, $event, $context, $message) use (&$events): void {
    $events[] = ['level' => $level, 'event' => $event, 'context' => $context, 'message' => $message];
});
$now = (int)floor(microtime(true) * 1000);
check($runtime->status()['accounts'][$id]['sync'] === ['in_progress' => false], 'startup status distinguishes a queued sync from active work');
$runtime->tick($now);
$state = $runtime->status()['accounts'][$id];
$progress = $state['sync'];
check($events[0]['event'] === 'exchange_sync_started' && $events[0]['level'] === 'info', 'sync start is visible at the default info level');
check($progress['in_progress'] && $progress['stage'] === 'rest_snapshot' && !$state['rest_ok'] && $state['at_ms'] === null,
    'in-flight progress does not imply a completed reconciliation');
check($progress['request']['operation'] === 'fetch_ledger' && $progress['request']['page'] === 1
    && $progress['request']['in_flight'], 'status identifies the pending ledger request and page');
check($progress['requests_started'] === 5 && $progress['requests_finished'] === 4,
    'status counts finished requests separately from a pending request');
$runtime->tick($now + 29999);
// Capture by reference so events generated by later ticks are visible.
$progressEvents = static function () use (&$events): array { return array_values(array_filter($events, fn($event) => $event['event'] === 'exchange_sync_progress')); };
check($progressEvents() === [], 'progress does not flood logs before the 30-second interval');
$runtime->tick($now + 30000);
$heartbeat = $progressEvents()[0];
check($heartbeat['level'] === 'info' && $heartbeat['context']['elapsed_ms'] === 30000
    && $heartbeat['context']['request']['elapsed_ms'] >= 29000, 'progress includes total and pending-request elapsed time');
$runtime->command('exchange-refresh', ['account' => $id]);
$runtime->tick($now + 30001);
check(count($progressEvents()) === 1 && $rest->tradeCalls === 1, 'refresh during a pending sync neither floods logs nor launches another snapshot');
check(!str_contains(json_encode([$events, $runtime->status()]), 'PRIVATE_'), 'progress excludes configured parameters and cursor contents');
$rest->pendingLedger->reject($httpErrors[503]);
$state = $runtime->status()['accounts'][$id];
$failure = array_values(array_filter($events, fn($event) => $event['event'] === 'exchange_sync_failed'))[0];
check(!$state['sync']['in_progress'] && $state['state'] === 'RECOVERING' && !$state['rest_ok'],
    'failed ledger request clears in-flight progress and leaves reconciliation blocked');
check($state['last_error']['http_status'] === 503 && $state['last_error']['operation'] === 'fetch_ledger'
    && str_contains($failure['message'], 'bitso fetch_ledger (HTTP 503)') && $failure['context']['retry_in_ms'] === 1000,
    'status and console show the ledger HTTP status without changing retry policy');
check(!str_contains(json_encode([$events, $runtime->status()]), 'PRIVATE_'), 'HTTP failure status and console never expose signed URLs or response text');
$rest->pendingLedger = new Deferred();
$runtime->tick($now + 60000);
$progress = $runtime->status()['accounts'][$id]['sync'];
check($progress['requests_started'] === 5 && $progress['requests_finished'] === 4, 'retry resets progress counters for the new snapshot');
$rest->pendingLedger->resolve([]);
$state = $runtime->status()['accounts'][$id];
check(!$state['sync']['in_progress'] && $state['rest_ok'] && !isset($state['last_error']),
    'successful retry clears progress and the HTTP error only after reconciliation');
$rest->pendingLedger = new Deferred();
$runtime->command('exchange-refresh', ['account' => $id]);
$runtime->tick($now + 60001);
$runtime->close();
check(!$runtime->status()['accounts'][$id]['sync']['in_progress'], 'shutdown clears pending progress');

echo json_encode(['suite' => 'm3-sync-diagnostics', 'status' => 'passed', 'checks' => $checks, 'network_requests' => 0], JSON_PRETTY_PRINT).PHP_EOL;
