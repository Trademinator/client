<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Backup, Database, Ledger};
use Trademinator\Client\M3\{Accounting, CcxtAccount, Runtime, Settings, SyncDiagnostics};
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
    public array $ledgerRows = [];
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
    public function fetch_ledger($currency, $since, $limit, $params): object { return $this->pendingLedger?->promise() ?? resolve($this->ledgerRows); }
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
check(SyncDiagnostics::describe(new RuntimeException('Same-timestamp history has unresolved funding dependencies; finer exchange timing or acquisition evidence is required.'), 'bitso')['reason'] === 'ambiguous_same_timestamp_funding',
    'same-time funding ambiguity has an explicit diagnostic without claiming a wallet discrepancy');
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

// Exercise the actual older ledger restriction rather than fabricating its message.
$roleDb = new Database(':memory:');
$roleLedger = new Ledger($roleDb);
$roleId = $roleLedger->createAccount('bitso', 'currency role diagnostics', null, ['USD' => '100']);
$roleMarket = $roleLedger->registerMarket($roleId, 'ATOM/MXN', 'ATOM', 'MXN');
$roleSettings = Settings::validate(['accounts' => [['account_id' => $roleId,
    'api_key_env' => 'UNUSED_KEY', 'secret_env' => 'UNUSED_SECRET', 'history_since_ms' => 0]]]);
$roleAccount = $roleSettings['accounts'][0];
$roleRevision = $roleDb->revision($roleId);
$roleError = null;
try {
    $roleLedger->registerMarket($roleId, 'MXN/USD', 'MXN', 'USD');
} catch (RuntimeException $error) {
    $roleError = $error;
}
check($roleError instanceof RuntimeException, 'real quote-currency inventory conflict is rejected');
$roleDiagnostic = SyncDiagnostics::describe($roleError, 'bitso');
check($roleDiagnostic['reason'] === 'inventory_currency_role_conflict'
    && str_contains(strtolower($roleDiagnostic['detail']), 'quote'),
    'legacy currency-role guard has an actionable accounting explanation');
check(($roleDiagnostic['source_file'] ?? null) === 'm1/Ledger.php'
    && is_int($roleDiagnostic['source_line'] ?? null) && $roleDiagnostic['source_line'] > 0,
    'real local guard identifies its project source and line');
check($roleDb->revision($roleId) === $roleRevision
    && (int)$roleDb->pdo()->query('SELECT COUNT(*) FROM markets')->fetchColumn() === 1,
    'diagnosing an unsupported currency role preserves the financial state');

$orderDb = new Database(':memory:');
$orderLedger = new Ledger($orderDb);
$orderAccount = $orderLedger->createAccount('bitso', 'order invariant diagnostics');
$orderMarket = $orderLedger->registerMarket($orderAccount, 'ATOM/USD', 'ATOM', 'USD');
$orderInput = ['account_id' => $orderAccount, 'market_id' => $orderMarket,
    'exchange_order_id' => 'PRIVATE_ORDER_ID', 'side' => 'BUY', 'quantity' => '10',
    'price' => '10', 'filled_quantity' => '2', 'status' => 'partially_filled'];
$orderLedger->recordOrder($orderInput);
$orderRevision = $orderDb->revision($orderAccount);
$orderError = null;
try {
    $orderLedger->recordOrder(array_replace($orderInput, ['filled_quantity' => '1']));
} catch (RuntimeException $error) {
    $orderError = $error;
}
check($orderError instanceof RuntimeException && $orderError->getMessage() === 'Cumulative order fills cannot decrease.',
    'real order update enforces cumulative-fill monotonicity');
$orderDiagnostic = SyncDiagnostics::describe($orderError, 'bitso');
check($orderDiagnostic['reason'] !== 'synchronization_error'
    && str_contains(strtolower($orderDiagnostic['detail']), 'fill')
    && ($orderDiagnostic['source_file'] ?? null) === 'm1/Ledger.php',
    'real order invariant is diagnosed without publishing the private order identity');
check($orderDb->revision($orderAccount) === $orderRevision
    && $orderDb->pdo()->query('SELECT filled_quantity FROM orders')->fetchColumn() === '2'
    && !str_contains(json_encode($orderDiagnostic), 'PRIVATE_'),
    'failed order update rolls back and its diagnostics contain no order data');

// A real exception from a trusted source can contain a private dynamic path.
$privateRoot = sys_get_temp_dir().'/trademinator-PRIVATE_DIAGNOSTIC_'.bin2hex(random_bytes(8));
mkdir($privateRoot, 0700);
try {
    $missingMetadata = $privateRoot.'/PRIVATE_ACCOUNT.json';
    $storageError = null;
    try {
        @Backup::readJson($missingMetadata);
    } catch (RuntimeException $error) {
        $storageError = $error;
    }
    check($storageError instanceof RuntimeException && str_contains($storageError->getMessage(), $missingMetadata),
        'storage fixture exercises a real exception carrying a private path');
    $storageDiagnostic = SyncDiagnostics::describe($storageError, 'bitso');
    check(($storageDiagnostic['source_file'] ?? null) === 'm1/Backup.php'
        && is_int($storageDiagnostic['source_line'] ?? null) && $storageDiagnostic['source_line'] > 0
        && !str_contains(json_encode($storageDiagnostic), 'PRIVATE_')
        && !str_contains(json_encode($storageDiagnostic), sys_get_temp_dir()),
        'trusted source location remains useful while dynamic storage details stay private');

    // Merely sharing a trusted basename and directory suffix is insufficient.
    mkdir($privateRoot.'/m1', 0700);
    $untrustedSource = $privateRoot.'/m1/Ledger.php';
    file_put_contents($untrustedSource, "<?php return new RuntimeException('PRIVATE_RESPONSE Authorization: PRIVATE_KEY');\n");
    $untrustedError = require $untrustedSource;
    $untrustedDiagnostic = SyncDiagnostics::describe($untrustedError, 'bitso');
    check($untrustedDiagnostic['reason'] === 'synchronization_error'
        && !isset($untrustedDiagnostic['source_file']) && !isset($untrustedDiagnostic['source_line'])
        && !str_contains(json_encode($untrustedDiagnostic), 'PRIVATE_')
        && !str_contains(json_encode($untrustedDiagnostic), $privateRoot),
        'untrusted file with a matching Ledger basename cannot expose a source path or raw message');
} finally {
    if (is_file($privateRoot.'/m1/Ledger.php')) { unlink($privateRoot.'/m1/Ledger.php'); }
    if (is_dir($privateRoot.'/m1')) { rmdir($privateRoot.'/m1'); }
    rmdir($privateRoot);
}

// REST collection succeeds, then inventory preflight reaches a real invalid currency mapping.
$roleAccount['currency_markets']['MXN'] = 'ATOM/MXN';
$roleSettings['accounts'][0] = $roleAccount;
$roleDb->pdo()->exec("UPDATE markets SET state='ACTIVE'");
$roleRest = new DiagnosticRest();
$roleRest->ledgerRows = [['id' => 'PRIVATE_DEPOSIT_ID', 'type' => 'transaction', 'direction' => 'in',
    'status' => 'ok', 'currency' => 'MXN', 'amount' => '25', 'timestamp' => 1700000000000,
    'info' => ['recipient' => 'PRIVATE_RECIPIENT']]];
$roleAdapter = new CcxtAccount('bitso', $roleSettings, $roleAccount, $roleRest);
$roleEvents = [];
$roleRuntime = new Runtime($roleDb, $roleSettings, fn() => $roleAdapter,
    function ($level, $event, $context, $message) use (&$roleEvents): void {
        $roleEvents[] = ['event' => $event, 'context' => $context, 'message' => $message];
    });
$roleNow = (int)floor(microtime(true) * 1000);
$roleRuntime->tick($roleNow);
$roleState = $roleRuntime->status()['accounts'][$roleId];
$roleFailure = array_values(array_filter($roleEvents, static fn($event) => $event['event'] === 'exchange_sync_failed'))[0];
check($roleState['last_error']['stage'] === 'inventory_validation'
    && $roleState['last_error']['operation'] === 'inventory_validation'
    && $roleState['last_error']['reason'] === 'reconciliation_validation',
    'inventory preflight failure preserves its actual stage after REST collection succeeds');
check($roleState['last_error']['source_file'] === 'm3/Accounting.php'
    && str_contains($roleFailure['message'], '(m3/Accounting.php:'.$roleState['last_error']['source_line'].')'),
    'runtime status and console identify the real local source without an absolute path');
check($roleState['state'] === 'RECOVERING' && !$roleState['rest_ok'] && $roleState['failures'] === 1
    && !$roleState['sync']['in_progress'] && !$roleRuntime->ready($roleMarket)['ready']
    && $roleFailure['context']['retry_in_ms'] === $roleSettings['retry_base_ms'],
    'local diagnostic enrichment preserves blocked readiness and the initial retry delay');
check((int)$roleDb->pdo()->query('SELECT COUNT(*) FROM operations')->fetchColumn() === 0
    && $roleDb->revision($roleId) === $roleRevision,
    'failed deposit pricing cannot fabricate or ingest a financial operation');
$roleRuntime->tick($roleNow + 100000);
$roleFailures = array_values(array_filter($roleEvents, static fn($event) => $event['event'] === 'exchange_sync_failed'));
check(count($roleFailures) === 2 && $roleFailures[1]['context']['retry_in_ms'] === 2 * $roleSettings['retry_base_ms']
    && $roleRuntime->status()['accounts'][$roleId]['failures'] === 2,
    'repeated local validation failure retains exponential retry behavior');
check(!str_contains(json_encode([$roleEvents, $roleRuntime->status()]), 'PRIVATE_')
    && !str_contains(json_encode([$roleEvents, $roleRuntime->status()]), dirname(__DIR__)),
    'pricing failure events and status exclude account evidence and absolute source paths');
$roleRest->ledgerRows = [];
$roleRuntime->tick($roleNow + 200000);
$roleState = $roleRuntime->status()['accounts'][$roleId];
check($roleState['rest_ok'] && $roleState['state'] === 'READY' && $roleState['failures'] === 0
    && !isset($roleState['last_error']) && !$roleState['sync']['in_progress'],
    'successful later reconciliation clears a local accounting diagnostic');
$roleRuntime->close();

echo json_encode(['suite' => 'm3-sync-diagnostics', 'status' => 'passed', 'checks' => $checks, 'network_requests' => 0], JSON_PRETTY_PRINT).PHP_EOL;
