<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use React\Http\Message\Response;
use Trademinator\Client\M1\{Database, Ledger};
use Trademinator\Client\M3\{BitsoReadClient, CcxtAccount, LightningHashError, LightningInvoice, Normalize, Reconciler, Runtime, Settings, SyncDiagnostics, WithdrawalEvidenceError};

use function React\Async\await;
use function React\Promise\resolve;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS '.$message.PHP_EOL;
}
function refuses(callable $action, string $message): void
{
    try { $action(); } catch (Throwable) { check(true, $message); return; }
    check(false, $message);
}

// Exercise the real CCXT signing, implicit GET methods and HTTP response parser.
// This replaces the HTTP browser completely: no sockets or exchange requests.
final class TransferBrowser
{
    public array $fundings = [];
    public array $withdrawals = [];
    public array $calls = [];
    public bool $repeat = false;
    public bool $overlap = false;
    public bool $conflict = false;
    public ?array $payloadOverride = null;
    public ?array $detailResponse = null;
    public int $detailHttpStatus = 200;
    public int $httpStatus = 200;
    public function request($method, $url, $headers, $body): object
    {
        if ($method !== 'GET') { throw new RuntimeException('Unexpected write request'); }
        $path = preg_replace('~^/api/~', '/', parse_url($url, PHP_URL_PATH));
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $detail = preg_match('~^/v3/withdrawals/([^/]+)$~D', $path, $match) === 1;
        if (!$detail && !in_array($path, ['/v3/fundings', '/v3/withdrawals/'], true)) {
            throw new RuntimeException('Unexpected endpoint');
        }
        $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $query];
        if ($detail) {
            if ($this->detailHttpStatus !== 200) { return resolve(new Response($this->detailHttpStatus, [], '')); }
            $rows = array_values(array_filter($this->withdrawals, fn($r) => $r['wid'] === rawurldecode($match[1])));
            return resolve(new Response(200, [], json_encode($this->detailResponse ?? ['success' => true, 'payload' => $rows])));
        }
        if ($this->httpStatus !== 200) { return resolve(new Response($this->httpStatus, [], '')); }
        if ($this->payloadOverride !== null) { return resolve(new Response(200, [], json_encode($this->payloadOverride))); }
        $rows = $path === '/v3/fundings' ? $this->fundings : $this->withdrawals;
        $id = $path === '/v3/fundings' ? 'fid' : 'wid';
        $start = 0;
        if (isset($query['marker']) && !$this->repeat) {
            $index = array_search($query['marker'], array_column($rows, $id), true);
            if ($index === false) { throw new RuntimeException('Unknown fixture marker'); }
            $start = $index + ($this->overlap ? 0 : 1);
        }
        $page = array_slice($rows, $start, (int)($query['limit'] ?? 25));
        if ($this->conflict && isset($query['marker']) && $page !== []) { $page[0]['amount'] = '999'; }
        return resolve(new Response(200, [], json_encode(['success' => true, 'payload' => $page])));
    }
}

final class TransferClient extends BitsoReadClient
{
    public TransferBrowser $transport;
    public array $balances = ['USD' => '1000'];
    public function set_request_browser($connector)
    {
        $this->transport ??= new TransferBrowser();
        $this->browser = $this->transport;
    }
    public function __construct()
    {
        parent::__construct(['apiKey' => 'PRIVATE_FIXTURE_KEY', 'secret' => 'PRIVATE_FIXTURE_SECRET',
            'enableRateLimit' => false, 'number' => 'strval']);
        $this->has['fetchTicker'] = false;
        $this->set_markets([['id' => 'atom_usd', 'symbol' => 'ATOM/USD', 'base' => 'ATOM', 'quote' => 'USD',
            'baseId' => 'atom', 'quoteId' => 'usd', 'spot' => true, 'type' => 'spot', 'precision' => [], 'limits' => []]]);
    }
    public function fetch_balance($params = []): \React\Promise\PromiseInterface
    {
        return resolve(['total' => $this->balances, 'free' => $this->balances,
            'used' => array_fill_keys(array_keys($this->balances), '0')]);
    }
    public function fetch_my_trades(?string $symbol = null, ?int $since = null, ?int $limit = null, $params = []): \React\Promise\PromiseInterface { return resolve([]); }
    public function fetch_open_orders(?string $symbol = null, ?int $since = null, ?int $limit = null, $params = []): \React\Promise\PromiseInterface { return resolve([]); }
}

function funding(string $id, int $at = 1000, string $amount = '2', string $status = 'complete'): array
{
    return ['fid' => $id, 'status' => $status, 'created_at' => gmdate('Y-m-d\TH:i:s\Z', $at),
        'currency' => 'atom', 'amount' => $amount, 'network' => 'atom', 'method' => 'atom',
        'details' => ['receiving_address' => 'receiving-address', 'tx_hash' => 'confirmed-hash', 'sender_name' => 'PRIVATE_PERSON']];
}
function withdrawal(string $id, array $details = ['fee' => '0.1', 'amount_to_send' => '0.4'], string $status = 'complete'): array
{
    return ['wid' => $id, 'status' => $status, 'created_at' => '1970-01-01T00:33:20Z',
        'currency' => 'atom', 'amount' => '0.5', 'network' => 'atom', 'method' => 'atom',
        'details' => $details + ['withdrawal_address' => 'destination-address', 'tx_hash' => 'withdrawal-hash']];
}
function settings(int $limit = 100, int $pages = 1000): array
{
    return Settings::validate(['history_limit' => $limit, 'history_max_pages' => $pages, 'accounts' => [[
        'account_id' => 'fixture', 'api_key_env' => 'UNUSED_KEY', 'secret_env' => 'UNUSED_SECRET',
        'history_since_ms' => 0, 'history_params' => [
            // Existing local configurations may retain this now-unused policy.
            'fetch_ledger' => ['mode' => 'backward_id', 'params' => ['sort' => 'desc']],
        ],
    ]]]);
}
function snapshot(TransferClient $client, ?array $settings = null, ?array $context = null): array
{
    $settings ??= settings();
    $context ??= ['symbols' => ['ATOM/USD'], 'since_ms' => 0, 'known_orders' => []];
    return await((new CcxtAccount('bitso', $settings, $settings['accounts'][0], $client))->snapshot($context));
}

$client = new TransferClient();
$client->transport->fundings = [funding('d1')];
$client->transport->withdrawals = [withdrawal('w1')];
$client->balances['ATOM'] = '1.5';
$batch = snapshot($client);
check(count($batch['operations']) === 2 && $batch['complete'], 'Bitso uses complete deposit and withdrawal endpoints without ledger');
check(array_column($client->transport->calls, 'path') === ['/v3/fundings', '/v3/withdrawals/'], 'only documented transfer GET endpoints are requested');
check($client->transport->calls[0]['params'] === ['limit' => '100'], 'deposit page limit is sent to Bitso rather than only filtering locally');
$withdrawal = $batch['operations'][1];
check($withdrawal['amount'] === '0.4' && $withdrawal['fees'] === [['currency' => 'ATOM', 'cost' => '0.1']], 'total withdrawal debit is split into net principal and confirmed expense');
check($withdrawal['txid'] === 'withdrawal-hash' && $withdrawal['address'] === 'destination-address' && $withdrawal['network'] === 'ATOM', 'CCXT transfer identities, hash, address and network are retained');
check(!str_contains(json_encode($batch), 'PRIVATE_'), 'raw transfer personal details never enter the financial batch');

$db = new Database(':memory:');
$ledger = new Ledger($db);
$id = $ledger->createAccount('bitso', 'transfer-read', null, ['USD' => '1000']);
$market = $ledger->registerMarket($id, 'ATOM/USD', 'ATOM', 'USD');
$account = settings()['accounts'][0]; $account['account_id'] = $id;
$batch['prices']['deposit:d1'] = ['price' => '10', 'source' => 'fixture', 'estimated' => true];
$reconciler = new Reconciler($db);
check($reconciler->apply($id, $batch, $account)['reconciled'], 'net transfer plus fee reconciles the exact wallet debit');
$revision = $db->revision($id);
check($reconciler->apply($id, $batch, $account)['reconciled'] && $db->revision($id) === $revision, 'replaying transfer endpoints is financially idempotent');
check($ledger->expectedBalances($id)['ATOM'] === '1.5', 'withdrawal fee is deducted exactly once');

foreach ([['fee' => '0.1'], ['amount_to_send' => '0.4'], ['fee' => '0', 'amount_to_send' => '0.5']] as $details) {
    $client->transport->withdrawals = [withdrawal('w2', $details)];
    $row = await($client->fetch_withdrawals())[0];
    check(bccomp(bcadd($row['amount'], $row['fee']['cost'], 24), '0.5', 24) === 0,
        'documented total and fee/net evidence reconstruct the exact debit');
}
foreach ([[], ['fee' => '0.2', 'amount_to_send' => '0.4'], ['fee' => '0.6'], ['amount_to_send' => '0.6']] as $details) {
    $client->transport->withdrawals = [withdrawal('bad', $details)];
    refuses(fn() => await($client->fetch_withdrawals()), 'missing or inconsistent withdrawal fee evidence fails closed');
}
$client->transport->withdrawals = [withdrawal('pending', [], 'processing'), withdrawal('failed', [], 'failed')];
$rows = await($client->fetch_withdrawals());
check(Normalize::operation($rows[0], 'withdrawal') === null && Normalize::operation($rows[1], 'withdrawal') === null,
    'pending and failed withdrawals never deduct confirmed inventory');

$lookup = new TransferClient();
$lookup->transport->withdrawals = [withdrawal('PRIVATE_WITHDRAWAL_ID', [])];
$lookup->transport->detailResponse = ['success' => true, 'payload' => [withdrawal('PRIVATE_WITHDRAWAL_ID')]];
$row = await($lookup->fetch_withdrawals())[0];
check($row['amount'] === '0.4' && $row['fee']['cost'] === '0.1', 'individual withdrawal lookup supplies missing fee evidence without changing the total debit');
check(array_column($lookup->transport->calls, 'path') === ['/v3/withdrawals/', '/v3/withdrawals/PRIVATE_WITHDRAWAL_ID']
    && $lookup->transport->calls[1]['params'] === [], 'missing evidence triggers one GET by withdrawal ID with no pagination or status filters');
check(!str_contains(json_encode($row), 'PRIVATE_PERSON') && !isset($row['info']), 'individual lookup never adds raw private response data to financial operations');
foreach ([['fee' => '0'], ['amount_to_send' => '0.5'], ['fee' => '0.1'], ['amount_to_send' => '0.4']] as $details) {
    $lookup->transport->detailResponse['payload'] = [withdrawal('PRIVATE_WITHDRAWAL_ID', $details)];
    $row = await($lookup->fetch_withdrawals())[0];
    check(bccomp(bcadd($row['amount'], $row['fee']['cost'], 24), '0.5', 24) === 0
        && (bccomp($row['fee']['cost'], '0', 24) === 0) === (isset($details['fee']) ? $details['fee'] === '0' : $details['amount_to_send'] === '0.5'),
        'individual fee/net evidence supports confirmed zero fees and exact nonzero expenses');
}
foreach ([['wid' => 'OTHER_ID'], ['currency' => 'btc'], ['amount' => '0.6'],
    ['created_at' => '1970-01-01T00:33:21Z'], ['status' => 'pending'], ['fid' => 'DEPOSIT_ID']] as $change) {
    $lookup->transport->detailResponse['payload'] = [array_replace(withdrawal('PRIVATE_WITHDRAWAL_ID'), $change)];
    refuses(fn() => await($lookup->fetch_withdrawals()), 'individual withdrawal must match list identity, currency, amount, time, status and direction');
}
foreach ([[], ['success' => true, 'payload' => []], ['success' => true, 'payload' => [withdrawal('PRIVATE_WITHDRAWAL_ID'), withdrawal('OTHER_ID')]],
    ['success' => true, 'payload' => withdrawal('PRIVATE_WITHDRAWAL_ID')]] as $response) {
    $lookup->transport->detailResponse = $response;
    refuses(fn() => await($lookup->fetch_withdrawals()), 'individual lookup rejects malformed, empty or multiple-record payloads');
}
$lookup->transport->detailResponse = ['success' => true, 'payload' => [withdrawal('PRIVATE_WITHDRAWAL_ID', ['fee' => '0.2', 'amount_to_send' => '0.4'])]];
refuses(fn() => await($lookup->fetch_withdrawals()), 'individual lookup cannot bypass exact gross/net/fee validation');
$lookup->transport->detailResponse = null;
$facade = new CcxtAccount('bitso', settings(), settings()['accounts'][0], $lookup);
try {
    await($facade->snapshot(['symbols' => ['ATOM/USD'], 'since_ms' => 0, 'known_orders' => []]));
    throw new RuntimeException('Expected missing withdrawal evidence');
} catch (WithdrawalEvidenceError $error) {
    $diagnostic = SyncDiagnostics::describe($error, 'bitso');
    check($diagnostic['reason'] === 'withdrawal_fee_evidence_missing'
        && $diagnostic['withdrawal_created_at'] === '1970-01-01T00:33:20.000000Z'
        && $diagnostic['withdrawal_total_debit'] === '0.5' && $diagnostic['withdrawal_currency'] === 'ATOM',
        'missing evidence identifies exact UTC date, currency and total balance debit for console lookup');
    check(str_contains($diagnostic['detail'], '1970-01-01T00:33:20.000000Z') && str_contains($diagnostic['detail'], '0.5 ATOM'),
        'human-readable sync warning contains the withdrawal date and amount');
    check($diagnostic['withdrawal_lookup_attempted'] && !$diagnostic['list_has_fee'] && !$diagnostic['lookup_has_amount_to_send']
        && strlen($diagnostic['withdrawal_ref']) === 16, 'missing evidence reports lookup completion, absent fields and a stable hashed reference');
    $context = $facade->failureContext($error);
    check($context['phase'] === 'withdrawal_detail_lookup' && $context['withdrawal_total_debit'] === '0.5'
        && $context['operation'] === 'fetch_withdrawals', 'snapshot failure retains the nested lookup stage and safe withdrawal metadata');
    $serialized = json_encode([$diagnostic, $context]);
    check(!str_contains($serialized, 'PRIVATE_') && !str_contains($serialized, 'destination-address')
        && !str_contains($serialized, 'withdrawal-hash') && !str_contains($serialized, 'https://'),
        'withdrawal diagnostics exclude credentials, raw IDs, destination addresses, hashes and URLs');
}
$lookup->transport->detailHttpStatus = 404;
try {
    snapshot($lookup);
    throw new RuntimeException('Expected detail HTTP failure');
} catch (ccxt\ExchangeNotAvailable $error) {
    check(SyncDiagnostics::describe($error, 'bitso')['http_status'] === 404
        && $lookup->failureContext($error)['phase'] === 'withdrawal_detail_lookup',
        'individual lookup preserves network exception type and safe HTTP diagnostics');
}
$lookup->transport->detailHttpStatus = 200;
$lookup->transport->detailResponse = ['success' => false, 'error' => ['code' => '0201', 'message' => 'PRIVATE_SECRET']];
try {
    snapshot($lookup);
    throw new RuntimeException('Expected detail authentication failure');
} catch (ccxt\AuthenticationError $error) {
    check(SyncDiagnostics::describe($error, 'bitso')['exchange_code'] === '0201',
        'individual lookup preserves authentication failure classification');
}
$lookup->transport->calls = [];
$lookup->transport->withdrawals = [withdrawal('fee-known'), withdrawal('pending', [], 'pending'), withdrawal('failed', [], 'failed')];
await($lookup->fetch_withdrawals());
check(count($lookup->transport->calls) === 1, 'complete fee evidence and unconfirmed withdrawals do not cause unnecessary individual requests');

$lookup->transport->detailResponse = null;
$lookup->transport->withdrawals = [array_replace(withdrawal('fiat-without-evidence', []), ['currency' => 'mxn'])];
refuses(fn() => await($lookup->fetch_withdrawals()), 'current fiat fee policy cannot substitute for evidence on a historical withdrawal');

$runtimeSettings = settings();
$runtimeSettings['accounts'][0]['account_id'] = $id;
$events = [];
$runtime = new Runtime($db, $runtimeSettings,
    fn() => new CcxtAccount('bitso', $runtimeSettings, $runtimeSettings['accounts'][0], $lookup),
    function ($level, $event, $context, $message) use (&$events): void {
        $events[] = ['event' => $event, 'context' => $context, 'message' => $message];
    });
$runtime->tick();
$state = $runtime->status()['accounts'][$id];
check($state['state'] === 'RECOVERING' && !$state['rest_ok'], 'missing withdrawal evidence keeps runtime authorization blocked');
check($state['last_error']['withdrawal_created_at'] === '1970-01-01T00:33:20.000000Z'
    && $state['last_error']['withdrawal_total_debit'] === '0.5' && $state['last_error']['withdrawal_currency'] === 'MXN',
    'exchange-status retains the exact withdrawal date, amount and currency');
$warnings = array_values(array_filter($events, fn($e) => $e['event'] === 'exchange_sync_failed'));
check(count($warnings) === 1 && str_contains($warnings[0]['message'], '0.5 MXN')
    && str_contains($warnings[0]['message'], '1970-01-01T00:33:20.000000Z'),
    'EXCHANGE_SYNC_FAILED includes the date and amount in its normal warning text');
$runtime->close();

// The documented SPEI response intentionally has no crypto fee/net fields.
// Fixture identifiers and personal fields are synthetic, unrelated to any account.
$spei = new TransferClient();
$speiRaw = ['wid' => 'synthetic-spei', 'status' => 'complete', 'created_at' => '2023-05-17T12:34:56Z',
    'currency' => 'mxn', 'amount' => '20', 'asset' => 'mxn', 'method' => 'praxis',
    'network' => 'spei', 'protocol' => 'clabe', 'integration' => 'praxis',
    'details' => ['clave_de_rastreo' => 'PRIVATE_TRACKING', 'beneficiary_name' => 'PRIVATE_PERSON',
        'beneficiary_clabe' => 'PRIVATE_BANK_ACCOUNT', 'cep_link' => 'https://example.invalid/PRIVATE_RECEIPT']];
foreach (['clabe', 'debitcard', 'phonenum'] as $protocol) {
    $spei->transport->calls = [];
    $spei->transport->withdrawals = [array_replace($speiRaw, ['protocol' => $protocol, 'wid' => 'spei-'.$protocol])];
    $op = snapshot($spei)['operations'][0];
    check($op['amount'] === '20' && $op['fees'] === [] && $op['network'] === 'SPEI'
        && $op['fee_evidence']['basis'] === 'exchange_documentation' && $op['fee_evidence']['protocol'] === $protocol,
        'documented MXN SPEI protocols normalize absent fee fields with explicit rule provenance');
    check(count(array_filter($spei->transport->calls, fn($c) => str_contains($c['path'], '/withdrawals/'))) === 2,
        'SPEI fallback checks the same individual withdrawal before applying the documented rule');
}
$spei->transport->withdrawals = [$speiRaw];
foreach ([['method' => null, 'network' => null, 'protocol' => null], ['details' => []]] as $change) {
    $spei->transport->withdrawals = [array_replace($speiRaw, $change)];
    $spei->transport->detailResponse = ['success' => true, 'payload' => [$speiRaw]];
    check(snapshot($spei)['operations'][0]['fee_evidence']['source'] === 'bitso_spei_contract',
        'matching individual SPEI metadata can supply fields absent from the list');
}
foreach ([['currency' => 'mxnb'], ['method' => 'unknown'], ['network' => 'arbitrum'], ['protocol' => 'unknown'],
    ['asset' => 'mxnb'], ['integration' => 'juno'], ['method' => null], ['network' => null], ['protocol' => null]] as $change) {
    $spei->transport->detailResponse = null;
    $spei->transport->withdrawals = [array_replace($speiRaw, $change)];
    refuses(fn() => snapshot($spei), 'currency, method, network and protocol must identify the documented MXN SPEI service');
}
$spei->transport->withdrawals = [$speiRaw];
$spei->transport->detailResponse = ['success' => true, 'payload' => [array_replace($speiRaw, ['protocol' => 'phonenum'])]];
refuses(fn() => snapshot($spei), 'conflicting list and lookup SPEI protocols cannot establish a zero fee');
foreach (['wid' => 'other-withdrawal', 'amount' => '21', 'created_at' => '2023-05-17T12:34:57Z'] as $field => $value) {
    $spei->transport->detailResponse = ['success' => true, 'payload' => [array_replace($speiRaw, [$field => $value])]];
    refuses(fn() => snapshot($spei), 'SPEI rule cannot bypass withdrawal identity and financial consistency checks');
}
foreach (['list', 'lookup'] as $source) {
    $explicitSpei = $speiRaw; $explicitSpei['details']['fee'] = '0.25';
    $spei->transport->withdrawals = [$source === 'list' ? $explicitSpei : $speiRaw];
    $spei->transport->detailResponse = ['success' => true, 'payload' => [$explicitSpei]];
    $op = snapshot($spei)['operations'][0];
    check($op['amount'] === '19.75' && $op['fees'] === [['currency' => 'MXN', 'cost' => '0.25']]
        && !isset($op['fee_evidence']), 'explicit nonzero SPEI fee overrides the documented zero-fee fallback');
}
$spei->transport->detailResponse = null;
$spei->transport->withdrawals = [array_replace($speiRaw, ['status' => 'pending'])];
check(snapshot($spei)['operations'] === [], 'SPEI method metadata cannot confirm a pending withdrawal');
$spei->transport->withdrawals = [array_replace($speiRaw, ['method' => 'PRIVATE_FIXTURE_SECRET', 'protocol' => 'unknown'])];
try {
    snapshot($spei);
    throw new RuntimeException('Expected unsupported MXN withdrawal');
} catch (WithdrawalEvidenceError $error) {
    $diagnostic = SyncDiagnostics::describe($error, 'bitso');
    check($diagnostic['withdrawal_taxonomy']['list']['method'] === 'redacted'
        && $diagnostic['withdrawal_taxonomy']['lookup']['network'] === 'spei'
        && $diagnostic['withdrawal_taxonomy']['lookup']['spei_receipt_present'],
        'unsupported MXN records expose safe payment-method and receipt-presence diagnostics');
    check(!str_contains(json_encode($diagnostic), 'PRIVATE_') && !str_contains(json_encode($diagnostic), 'https://'),
        'payment-method diagnostics never reveal bank details, tracking codes, receipt URLs or configured credentials');
}
// Synthetic unknown machine codes must remain visible without changing accounting.
$unknownMethods = ['method' => 'fixture_transfer', 'network' => 'fixture_network',
    'protocol' => 'fixture_protocol', 'integration' => 'fixture-v2.1'];
$spei->transport->withdrawals = [array_replace($speiRaw, $unknownMethods)];
try {
    snapshot($spei);
    throw new RuntimeException('Expected unknown withdrawal method to remain blocked');
} catch (WithdrawalEvidenceError $error) {
    $diagnostic = SyncDiagnostics::describe($error, 'bitso');
    $context = $spei->failureContext($error);
    foreach (['list', 'lookup'] as $source) {
        check(array_intersect_key($diagnostic['withdrawal_taxonomy'][$source], $unknownMethods) === $unknownMethods
            && $context['withdrawal_taxonomy'][$source] === $diagnostic['withdrawal_taxonomy'][$source],
            'unknown method codes survive both error diagnostics and transport context without granting a fee fallback');
    }
}
foreach ([['method' => null, 'network' => '', 'protocol' => [], 'integration' => 123],
    ['method' => "unsafe\ncode", 'network' => 'https://example.invalid/PRIVATE', 'protocol' => 'PRIVATE@example.invalid', 'integration' => str_repeat('x', 65)],
    ['method' => str_repeat('a', 32), 'network' => '123456789012345678', 'protocol' => 'lnbc1234', 'integration' => 'code/PRIVATE']] as $index => $values) {
    $taxonomy = WithdrawalEvidenceError::taxonomyContext($values)['withdrawal_taxonomy']['list'];
    $expected = $index === 0 ? ['method' => 'missing', 'network' => 'missing', 'protocol' => 'invalid_type', 'integration' => 'invalid_type']
        : array_fill_keys(array_keys($values), 'redacted');
    check(array_intersect_key($taxonomy, $values) === $expected,
        'taxonomy diagnostics distinguish absent and malformed values while excluding free text, identifiers, URLs and invoice-like content');
}
$spei->password = 'fixture-passphrase'; $spei->uid = 'fixture-user';
$credentialEcho = ['method' => $spei->apiKey, 'network' => strtoupper($spei->secret),
    'protocol' => $spei->password, 'integration' => 'prefix-'.$spei->uid];
$spei->transport->withdrawals = [array_replace($speiRaw, $credentialEcho)];
try {
    snapshot($spei);
    throw new RuntimeException('Expected echoed credentials to remain blocked');
} catch (WithdrawalEvidenceError $error) {
    $diagnostic = SyncDiagnostics::describe($error, 'bitso');
    $context = $spei->failureContext($error);
    foreach (['list', 'lookup'] as $source) {
        check(array_intersect_key($diagnostic['withdrawal_taxonomy'][$source], $credentialEcho) === array_fill_keys(array_keys($credentialEcho), 'redacted')
            && $context['withdrawal_taxonomy'][$source] === $diagnostic['withdrawal_taxonomy'][$source],
            'all four configured credential values are redacted in both exception and transport diagnostics');
    }
}
$spei->transport->detailHttpStatus = 404;
try {
    snapshot($spei);
    throw new RuntimeException('Expected detail request failure');
} catch (ccxt\ExchangeNotAvailable $error) {
    $context = $spei->failureContext($error);
    check(array_intersect_key($context['withdrawal_taxonomy']['list'], $credentialEcho) === array_fill_keys(array_keys($credentialEcho), 'redacted')
        && !isset($context['withdrawal_taxonomy']['lookup']), 'lookup network failures retain only sanitized list taxonomy');
}
$spei->transport->detailHttpStatus = 200;
$spei->transport->withdrawals = [$speiRaw];
$spei->balances = ['MXN' => '80'];
$speiBatch = snapshot($spei);
$speiDb = new Database(':memory:'); $speiLedger = new Ledger($speiDb);
$speiId = $speiLedger->createAccount('bitso', 'spei-read', null, ['MXN' => '100']);
$speiSettings = settings()['accounts'][0]; $speiSettings['account_id'] = $speiId; $speiSettings['valuation_quote'] = 'MXN';
$speiReconciler = new Reconciler($speiDb);
check($speiReconciler->apply($speiId, $speiBatch, $speiSettings)['reconciled']
    && $speiLedger->expectedBalances($speiId)['MXN'] === '80', 'SPEI full principal reconciles the exact historical wallet debit');
$speiRevision = $speiDb->revision($speiId);
check($speiReconciler->apply($speiId, $speiBatch, $speiSettings)['reconciled'] && $speiDb->revision($speiId) === $speiRevision,
    'replayed SPEI history does not deduct the withdrawal twice');
$metadata = json_decode($speiDb->pdo()->query("SELECT metadata_json FROM operations WHERE kind='withdrawal'")->fetchColumn(), true);
check($metadata['m3']['fee_evidence']['basis'] === 'exchange_documentation'
    && $metadata['m3']['fee_evidence']['rule'] === 'mxn_spei_praxis_v1',
    'SPEI accounting audit distinguishes a documented method rule from an explicit API fee');
$row = await($spei->fetch_withdrawals())[0];
foreach ([['rule' => 'unknown'], ['basis' => 'confirmed'], ['amount' => '21'], ['protocol' => 'unknown']] as $change) {
    $invalid = $row; $invalid['m3_fee_evidence'] = array_replace($row['m3_fee_evidence'], $change);
    refuses(fn() => Normalize::operation($invalid, 'withdrawal'), 'SPEI evidence must retain its exact amount, method rule and documented basis');
}
$spei->close();

// Internal transfers use the same bt tuple for different customers/currencies.
$internal = new TransferClient();
$btRaw = ['wid' => 'synthetic-bitso-transfer', 'status' => 'complete', 'created_at' => '2023-04-12T08:09:10Z',
    'currency' => 'mxn', 'amount' => '25', 'method' => 'bt', 'network' => 'bt', 'protocol' => 'bt', 'integration' => 'bt',
    'method_name' => 'Bitso Transfer',
    'details' => ['beneficiary_name' => 'PRIVATE_PERSON', 'email' => 'PRIVATE_EMAIL', 'notes' => 'PRIVATE_MESSAGE',
        'origin_id' => 'PRIVATE_ORIGIN', 'phone' => null, 'refcode' => 'PRIVATE_REF', 'recipient_ref' => 'PRIVATE_RECIPIENT', 'emoji' => '']];
foreach (['mxn' => '25', 'usd' => '7.89', 'brl' => '12.34', 'btc' => '0.00012345', 'atom' => '1.23456789'] as $currency => $amount) {
    $internal->transport->calls = [];
    $internal->transport->withdrawals = [array_replace($btRaw, ['wid' => 'internal-'.$currency, 'currency' => $currency, 'amount' => $amount])];
    $config = settings(); $config['accounts'][0]['account_id'] = 'unrelated-'.$currency;
    $internalSnapshot = snapshot($internal, $config);
    $op = $internalSnapshot['operations'][0];
    $label = $internalSnapshot['transfer_labels'][$op['id']] ?? null;
    check(($label['label'] ?? null) === 'user_withdrawal' && $label['status'] === 'confirmed'
        && $label['reason'] === 'bitso_user_transfer_route' && $label['evidence']['counterparty_present'],
        'real Bitso adapter retains confirmed user-withdrawal evidence after redacting raw metadata');
    check($op['currency'] === strtoupper($currency) && $op['amount'] === $amount && $op['fees'] === []
        && $op['network'] === 'BT' && $op['fee_evidence']['source'] === 'bitso_transfer_contract'
        && $op['fee_evidence']['currency'] === $op['currency'],
        'Bitso Transfer handles unrelated accounts, currencies and amounts without a per-withdrawal mapping');
    check(array_column($internal->transport->calls, 'path') === ['/v3/fundings', '/v3/withdrawals/', '/v3/withdrawals/internal-'.$currency]
        && !str_contains(json_encode($op), 'PRIVATE_') && $op['txid'] === null,
        'internal transfer evidence uses one matching lookup and does not invent a blockchain hash or expose recipient details');
}
// A partial, mixed or contradictory taxonomy cannot activate the method rule.
foreach (['method', 'network', 'protocol', 'integration'] as $field) {
    foreach (['list', 'lookup'] as $source) {
        $incomplete = $btRaw; unset($incomplete[$field]);
        $internal->transport->withdrawals = [$source === 'list' ? $incomplete : $btRaw];
        $internal->transport->detailResponse = ['success' => true, 'payload' => [$source === 'lookup' ? $incomplete : $btRaw]];
        refuses(fn() => snapshot($internal), 'each response must independently identify the complete internal-transfer format');
    }
}
$internal->transport->withdrawals = [$btRaw];
foreach ([['network' => 'spei'], ['integration' => 'external'], ['protocol' => ['bt']],
    ['wid' => 'another-withdrawal'], ['currency' => 'btc'], ['amount' => '26'],
    ['created_at' => '2023-04-12T08:09:11Z'], ['status' => 'pending']] as $change) {
    $internal->transport->detailResponse = ['success' => true, 'payload' => [array_replace($btRaw, $change)]];
    refuses(fn() => snapshot($internal), 'internal-transfer fallback cannot override conflicting method, identity or financial data');
}
$internal->transport->detailResponse = null;
$internal->transport->withdrawals = [array_replace($btRaw, ['method' => 'bt', 'network' => 'spei', 'protocol' => 'clabe', 'integration' => 'praxis'])];
refuses(fn() => snapshot($internal), 'a bt method alone cannot label an external transfer as fee-free');
foreach (['pending', 'failed'] as $status) {
    $internal->transport->withdrawals = [array_replace($btRaw, ['status' => $status])];
    check(snapshot($internal)['operations'] === [], 'unconfirmed internal transfers do not affect confirmed inventory');
}
foreach (['list', 'lookup'] as $source) {
    foreach ([['fee' => '0.5'], ['amount_to_send' => '24.5']] as $details) {
        $explicit = array_replace($btRaw, ['details' => $details]);
        $internal->transport->withdrawals = [$source === 'list' ? $explicit : $btRaw];
        $internal->transport->detailResponse = ['success' => true, 'payload' => [$explicit]];
        $op = snapshot($internal)['operations'][0];
        check($op['amount'] === '24.5' && $op['fees'] === [['currency' => 'MXN', 'cost' => '0.5']]
            && !isset($op['fee_evidence']), 'explicit fee or net amount overrides the internal-transfer zero-fee rule');
    }
}
$internal->transport->withdrawals = [array_replace($btRaw, ['details' => ['fee' => '1', 'amount_to_send' => '25']])];
refuses(fn() => snapshot($internal), 'internal-transfer method cannot bypass an inconsistent explicit fee/net total');
$internal->transport->detailResponse = null;
$internal->transport->withdrawals = [$btRaw];
$row = await($internal->fetch_withdrawals())[0];
foreach ([['currency' => 'BTC'], ['amount' => '26'], ['network' => 'SPEI'], ['basis' => 'confirmed'],
    ['rule' => 'unknown'], ['operator_confirmation' => []]] as $change) {
    $invalid = $row; $invalid['m3_fee_evidence'] = array_replace($row['m3_fee_evidence'], $change);
    refuses(fn() => Normalize::operation($invalid, 'withdrawal'), 'internal-transfer audit evidence is bound to currency, amount, network and documented basis');
}
refuses(fn() => Normalize::operation($row, 'deposit'), 'withdrawal fee evidence cannot masquerade as a deposit');
$internal->balances = ['MXN' => '75'];
$internalBatch = snapshot($internal);
$internalDb = new Database(':memory:'); $internalLedger = new Ledger($internalDb);
$internalId = $internalLedger->createAccount('bitso', 'internal-transfer-read', null, ['MXN' => '100']);
$internalSettings = settings()['accounts'][0]; $internalSettings['account_id'] = $internalId;
$internalSettings['valuation_quote'] = 'MXN';
$internalReconciler = new Reconciler($internalDb);
check($internalReconciler->apply($internalId, $internalBatch, $internalSettings)['reconciled']
    && $internalLedger->expectedBalances($internalId)['MXN'] === '75', 'internal transfer reconciles the exact principal debit without adding an expense');
$internalRevision = $internalDb->revision($internalId);
check($internalReconciler->apply($internalId, $internalBatch, $internalSettings)['reconciled']
    && $internalDb->revision($internalId) === $internalRevision, 'internal-transfer history is financially idempotent');
$metadata = json_decode($internalDb->pdo()->query("SELECT metadata_json FROM operations WHERE kind='withdrawal'")->fetchColumn(), true);
check($metadata['m3']['fee_evidence']['source'] === 'bitso_transfer_contract'
    && $metadata['m3']['fee_evidence']['basis'] === 'exchange_documentation'
    && $metadata['m3']['fee_evidence']['rule'] === 'bitso_transfer_bt_v1', 'internal-transfer audit preserves its method rule and documentation basis');
$internal->transport->withdrawals = [array_replace($btRaw, ['details' => ['fee' => '0']])];
check($internalReconciler->apply($internalId, snapshot($internal), $internalSettings)['reconciled']
    && $internalLedger->expectedBalances($internalId)['MXN'] === '75'
    && (int) $internalDb->pdo()->query("SELECT COUNT(*) FROM operations WHERE kind='withdrawal'")->fetchColumn() === 1,
    'later explicit zero-fee evidence does not duplicate an internal debit');
$internalRevision = $internalDb->revision($internalId);
$metadata = json_decode($internalDb->pdo()->query("SELECT metadata_json FROM operations WHERE kind='withdrawal'")->fetchColumn(), true);
check($metadata['m3']['fee_evidence']['rule'] === 'bitso_transfer_bt_v1', 'later API enrichment preserves the original internal-transfer audit');
$internal->transport->withdrawals = [array_replace($btRaw, ['details' => ['fee' => '0.5']])];
refuses(fn() => $internalReconciler->apply($internalId, snapshot($internal), $internalSettings),
    'contradictory later fee evidence requires investigation instead of silently changing financial history');
check($internalLedger->expectedBalances($internalId)['MXN'] === '75' && $internalDb->revision($internalId) === $internalRevision,
    'conflicting later fee evidence rolls back without changing balances or revision');
$internal->close();

// Exercise classification through the actual Bitso parser/redaction boundary.
$labelClient = new TransferClient();
$incoming = $btRaw; unset($incoming['wid']); $incoming['fid'] = 'synthetic-peer-funding';
$incoming['details'] = ['sender_name' => 'PRIVATE_SENDER', 'sender_ref' => 'PRIVATE_REF', 'notes' => '', 'emoji' => ''];
$labelClient->transport->fundings = [$incoming];
$labelClient->transport->withdrawals = [$btRaw];
$labelRows = await($labelClient->fetch_withdrawals());
check(!isset($labelRows[0]['info']) && !str_contains(json_encode($labelRows), 'PRIVATE_')
    && ($labelRows[0]['m3_transfer_label']['label'] ?? null) === 'user_withdrawal',
    'Bitso transports only a safe label descriptor after removing private raw fields');
$labelBatch = snapshot($labelClient);
check(($labelBatch['transfer_labels']['deposit:synthetic-peer-funding']['label'] ?? null) === 'user_deposit'
    && $labelBatch['transfer_labels']['deposit:synthetic-peer-funding']['status'] === 'confirmed'
    && $labelBatch['transfer_labels']['deposit:synthetic-peer-funding']['evidence']['counterparty_present']
    && $labelBatch['transfer_labels']['withdrawal:synthetic-bitso-transfer']['status'] === 'confirmed'
    && count($labelBatch['transfer_labels']) === 2,
    'documented Bitso funding and withdrawal payloads confirm their respective directional labels');
check(!str_contains(json_encode($labelBatch), 'PRIVATE_')
    && !isset($labelBatch['operations'][1]['m3_transfer_label'])
    && $labelBatch['operations'][1] === Normalize::operation($labelRows[0], 'withdrawal'),
    'Bitso labels remain outside financial operations and retain no private response data');
$labelClient->transport->fundings = [];
foreach ([
    ['details' => ['fee' => '0', 'tx_hash' => 'external-chain-hash']],
    ['method' => 'spei', 'network' => 'spei', 'protocol' => 'clabe', 'integration' => 'praxis', 'details' => ['fee' => '0']],
    ['method' => null, 'network' => null, 'protocol' => null, 'integration' => null,
        'type' => 'conversion', 'details' => ['fee' => '0']],
    ['method' => null, 'network' => null, 'protocol' => 'unknown_route', 'integration' => null, 'details' => ['fee' => '0']],
    ['method' => null, 'network' => null, 'protocol' => null, 'integration' => null,
        'details' => ['fee' => '0', 'bank_account' => 'PRIVATE_BANK_ACCOUNT']],
] as $change) {
    $labelClient->transport->withdrawals = [array_replace($btRaw, $change)];
    check(snapshot($labelClient)['transfer_labels'] === [],
        'classification rejected before Bitso redaction cannot become a fallback user-transfer label');
}
$labelClient->transport->withdrawals = [array_replace($btRaw, ['currency' => 'usd', 'amount' => '185'])];
$labelDb = new Database(':memory:'); $labelLedger = new Ledger($labelDb);
$labelAccount = $labelLedger->createAccount('bitso', 'Missing acquisition with real Bitso transport');
$labelLedger->registerMarket($labelAccount, 'USD/EUR', 'USD', 'EUR');
$labelSettings = settings(); $labelSettings['accounts'][0]['account_id'] = $labelAccount;
$labelAdapter = new CcxtAccount('bitso', $labelSettings, $labelSettings['accounts'][0], $labelClient);
$labelEvents = [];
$labelRuntime = new Runtime($labelDb, $labelSettings, fn () => $labelAdapter,
    function ($level, $event, $data, $message) use (&$labelEvents): void { $labelEvents[] = $event; });
try {
    $labelRuntime->tick((int)floor(microtime(true) * 1000));
    $labelState = $labelRuntime->status()['accounts'][$labelAccount];
    check($labelState['state'] === 'REVIEW_REQUIRED' && !$labelState['rest_ok']
        && $labelState['warning'] === 'user_deposit_confirmation_required'
        && ($labelState['last_review']['label'] ?? null) === 'user_deposit'
        && ($labelState['last_review']['status'] ?? null) === 'pending_confirmation'
        && !isset($labelState['last_error'])
        && ($labelState['unclassified_review']['count'] ?? 0) === 1
        && $labelState['transfer_review']['count'] === 0
        && $labelState['confirmed_user_transfers']['count'] === 1
        && $labelState['confirmed_user_transfers']['items'][0]['label'] === 'user_withdrawal'
        && $labelState['confirmed_user_transfers']['items'][0]['status'] === 'confirmed'
        && $labelState['confirmed_user_transfers']['items'][0]['amount'] === '185',
        'explicit Bitso withdrawal is confirmed while its missing acquisition remains a separate source review');
    check(count(array_filter($labelEvents, fn ($event) => $event === 'exchange_user_transfers_confirmed')) === 1
        && !in_array('exchange_transfer_review_pending', $labelEvents, true),
        'runtime announces confirmed Bitso transfers without emitting a soft-transfer review event');
    $labelRuntime->command('exchange-refresh', ['account' => $labelAccount]);
    $labelRuntime->tick((int)floor(microtime(true) * 1000));
    check(count(array_filter($labelEvents, fn ($event) => $event === 'exchange_user_transfers_confirmed')) === 1
        && $labelRuntime->status()['accounts'][$labelAccount]['confirmed_user_transfers']['count'] === 1,
        'repeated REST confirmation neither duplicates transfers nor repeats the confirmation announcement');
    check($labelLedger->accountSnapshot($labelAccount)['operations'] === [],
        'a missing acquisition never creates an invented user deposit or bypasses inventory validation');
} finally { $labelRuntime->close(); }

$invoices = json_decode(file_get_contents(__DIR__.'/fixtures/m3-lightning.json'), true, 32, JSON_THROW_ON_ERROR);
// Static synthetic fixtures were signed independently with Python cryptography.
$decoded = LightningInvoice::decode($invoices['valid']);
check($decoded['amount'] === '0.04033153' && $decoded['payment_hash'] === $invoices['payment_hash'],
    'signed Lightning invoice yields the exact decimal BTC amount and payment hash');
check(LightningInvoice::decode(strtoupper($invoices['valid'])) === $decoded
    && LightningInvoice::decode('lightning:'.$invoices['valid']) === $decoded,
    'uppercase and lightning URI representations retain the same verified invoice identity');
foreach (['amount_m' => '0.01', 'amount_u' => '0.00001', 'amount_p' => '0.0000000001', 'amount_whole' => '1'] as $name => $expected) {
    check(LightningInvoice::decode($invoices[$name])['amount'] === $expected, 'BOLT11 amount units convert exactly without floating point');
}
foreach (['amountless', 'testnet', 'bad_p', 'no_payee', 'wrong_signer', 'duplicate_hash', 'bad_tag_length', 'bad_checksum'] as $name) {
    refuses(fn() => LightningInvoice::decode($invoices[$name]), 'unsupported, malformed or unauthenticated invoice cannot establish a fee');
}
refuses(fn() => LightningInvoice::decode(strtoupper(substr($invoices['valid'], 0, 4)).substr($invoices['valid'], 4)),
    'mixed-case Lightning invoices are rejected');
refuses(fn() => LightningInvoice::decode(str_repeat('x', 4097)), 'Lightning evidence parsing has a strict input size limit');

$lightning = new TransferClient();
$lnRaw = array_replace(withdrawal('lightning-fixture', ['invoice' => $invoices['valid'], 'tx_hash' => $invoices['payment_hash']]),
    ['currency' => 'btc', 'amount' => '0.04033153', 'network' => 'btc']);
$lightning->transport->withdrawals = [$lnRaw];
$row = await($lightning->fetch_withdrawals())[0];
$operation = Normalize::operation($row, 'withdrawal');
check($row['amount'] === '0.04033153' && $row['fee'] === ['currency' => 'BTC', 'cost' => '0']
    && $operation['fees'] === [] && $operation['network'] === 'LIGHTNING',
    'completed Lightning debit equal to verified invoice amount records zero charged fee');
check(count($lightning->transport->calls) === 1, 'verified list invoice avoids an unnecessary individual lookup');
check($operation['fee_evidence']['invoice_sha256'] === $decoded['invoice_sha256']
    && $operation['fee_evidence']['source'] === 'bitso_lightning_invoice'
    && $operation['fee_evidence']['payment_hash_source'] === 'exchange', 'financial operation retains the verified fee evidence source');
check(!str_contains(json_encode($row), $invoices['valid']) && !str_contains(json_encode($row), 'Synthetic offline fixture'),
    'raw invoice and payment description do not enter normalized financial data');

$lightning->transport->withdrawals = [array_replace($lnRaw, ['details' => ['tx_hash' => $invoices['payment_hash']]])];
$lightning->transport->detailResponse = ['success' => true, 'payload' => [$lnRaw]];
check(await($lightning->fetch_withdrawals())[0]['fee']['cost'] === '0', 'individual lookup can supply the missing verified Lightning invoice');
$lightning->transport->detailResponse = null;
$addressInvoice = $lnRaw;
$addressInvoice['details'] = ['withdrawal_address' => $invoices['valid'], 'tx_hash' => $invoices['payment_hash']];
$lightning->transport->withdrawals = [$addressInvoice];
$row = await($lightning->fetch_withdrawals())[0];
check($row['address'] === null && $row['addressTo'] === null && !str_contains(json_encode($row), $invoices['valid']),
    'a Lightning invoice is not persisted as a reusable destination address');

// A missing CCXT txid is incomplete evidence, not proof of a hash conflict.
$missingHash = $lnRaw;
unset($missingHash['details']['tx_hash']);
foreach ([null, ''] as $missing) {
    $listed = $missingHash;
    $listed['details']['tx_hash'] = $missing;
    $lightning->transport->withdrawals = [$listed];
    $lightning->transport->detailResponse = ['success' => true, 'payload' => [array_replace($lnRaw,
        ['details' => ['tx_hash' => strtoupper($invoices['payment_hash'])]])]];
    $lightning->transport->calls = [];
    $row = await($lightning->fetch_withdrawals())[0];
    check($row['fee']['cost'] === '0' && $row['txid'] === $invoices['payment_hash']
        && $row['m3_fee_evidence']['invoice_sha256'] === $decoded['invoice_sha256']
        && count($lightning->transport->calls) === 2,
        'missing or empty list hash triggers one lookup while retaining the verified list invoice');
}
$lightning->transport->withdrawals = [array_replace($lnRaw, ['details' => ['tx_hash' => $invoices['payment_hash']]])];
$lightning->transport->detailResponse = ['success' => true, 'payload' => [$missingHash]];
check(await($lightning->fetch_withdrawals())[0]['fee']['cost'] === '0',
    'a listed hash can corroborate an invoice supplied only by the individual lookup');

foreach (['tx_hash', 'payment_hash', 'details.payment_hash'] as $field) {
    $aliased = $missingHash;
    if ($field === 'details.payment_hash') { $aliased['details']['payment_hash'] = $invoices['payment_hash']; }
    else { $aliased[$field] = $invoices['payment_hash']; }
    $lightning->transport->withdrawals = [$aliased];
    $lightning->transport->detailResponse = null;
    $lightning->transport->calls = [];
    $row = await($lightning->fetch_withdrawals())[0];
    check($row['fee']['cost'] === '0' && $row['txid'] === $invoices['payment_hash']
        && count($lightning->transport->calls) === 1,
        'explicit '.$field.' corroborates the invoice when pinned CCXT omits its txid');
}

function hashFailure(TransferClient $client): array
{
    try { snapshot($client); }
    catch (LightningHashError $error) {
        return $client->failureContext($error) + SyncDiagnostics::describe($error, 'bitso');
    }
    throw new RuntimeException('Expected precise Lightning hash diagnostic');
}
$lightning->transport->withdrawals = [$missingHash];
$lightning->transport->calls = [];
$automaticOp = snapshot($lightning)['operations'][0];
check($automaticOp['fees'] === [] && $automaticOp['amount'] === '0.04033153'
    && count($lightning->transport->calls) === 3,
    'missing hashes in both responses resolve automatically after one individual withdrawal lookup');
check($automaticOp['txid'] === $invoices['payment_hash']
    && $automaticOp['fee_evidence']['payment_hash_source'] === 'invoice'
    && $automaticOp['fee_evidence']['invoice_sha256'] === $decoded['invoice_sha256'],
    'the signed invoice attached to the completed withdrawal supplies its payment hash with audit provenance');
check(!isset($automaticOp['fee_evidence']['operator_confirmation'])
    && !str_contains(json_encode($automaticOp), $invoices['valid']),
    'automatic association requires no operator mapping and excludes the raw invoice');
foreach ([['value' => 'PRIVATE_HASH_VALUE', 'reason' => 'withdrawal_payment_hash_invalid', 'state' => 'invalid_format'],
    ['value' => str_repeat('a', 64), 'reason' => 'withdrawal_payment_hash_mismatch', 'state' => 'mismatch']] as $case) {
    $badHash = $lnRaw; $badHash['details']['tx_hash'] = $case['value'];
    $lightning->transport->withdrawals = [$badHash];
    $lightning->transport->detailResponse = ['success' => true, 'payload' => [$lnRaw]];
    $lightning->transport->calls = [];
    $diagnostic = hashFailure($lightning);
    check($diagnostic['reason'] === $case['reason'] && !$diagnostic['withdrawal_lookup_attempted']
        && $diagnostic['hash_fields']['list.details.tx_hash']['state'] === $case['state']
        && count($lightning->transport->calls) === 2,
        'malformed and genuinely conflicting supplied hashes have distinct errors and cannot be overwritten');
    $serialized = json_encode($diagnostic);
    check(!str_contains($serialized, $case['value']) && !str_contains($serialized, $invoices['valid'])
        && !str_contains($serialized, $invoices['payment_hash']) && !str_contains($serialized, 'PRIVATE_')
        && !str_contains($serialized, 'lightning-fixture') && !str_contains($serialized, 'destination-address'),
        'hash failure diagnostics exclude raw hashes, invoices, credentials, IDs and addresses');
}
check($diagnostic['hash_fields']['list.details.tx_hash']['hash_ref'] !== $diagnostic['invoice_payment_hash_ref'],
    'a true mismatch has different hashed references for comparing evidence without revealing hashes');
$conflictingAliases = $lnRaw; $conflictingAliases['payment_hash'] = str_repeat('a', 64);
$lightning->transport->withdrawals = [$conflictingAliases];
$diagnostic = hashFailure($lightning);
check($diagnostic['reason'] === 'withdrawal_payment_hash_mismatch'
    && $diagnostic['hash_fields']['list.details.tx_hash']['state'] === 'match'
    && $diagnostic['hash_fields']['list.payment_hash']['state'] === 'mismatch',
    'one matching alias cannot conceal another explicitly conflicting payment hash');

$lightning->transport->withdrawals = [$missingHash];
$detail = $lnRaw; $detail['details']['tx_hash'] = str_repeat('a', 64);
$lightning->transport->detailResponse = ['success' => true, 'payload' => [$detail]];
$diagnostic = hashFailure($lightning);
check($diagnostic['reason'] === 'withdrawal_payment_hash_mismatch' && $diagnostic['withdrawal_lookup_attempted']
    && $diagnostic['hash_fields']['lookup.details.tx_hash']['state'] === 'mismatch',
    'the individual lookup must corroborate the listed invoice rather than replace conflicting evidence');
$detail = $missingHash; $detail['details']['invoice'] = $invoices['other_invoice'];
$lightning->transport->detailResponse = ['success' => true, 'payload' => [$detail]];
refuses(fn() => await($lightning->fetch_withdrawals()), 'conflicting list and individual invoices cannot be merged into zero-fee evidence');
$lightning->transport->detailResponse = ['success' => true, 'payload' => [array_replace($lnRaw, ['wid' => 'OTHER_ID'])]];
refuses(fn() => await($lightning->fetch_withdrawals()), 'hash lookup verifies withdrawal identity before combining evidence');
$lightning->transport->detailResponse = null;
$lightning->transport->detailHttpStatus = 404;
try { snapshot($lightning); throw new RuntimeException('Expected missing-hash lookup HTTP failure'); }
catch (ccxt\ExchangeNotAvailable $error) {
    check(SyncDiagnostics::describe($error, 'bitso')['http_status'] === 404
        && $lightning->failureContext($error)['withdrawal_lookup_attempted'],
        'missing-hash fallback preserves the original HTTP failure classification');
}
$lightning->transport->detailHttpStatus = 200;

$detail = $missingHash; $detail['details']['tx_hash'] = str_repeat('a', 64);
$lightning->transport->detailResponse = ['success' => true, 'payload' => [$detail]];
$events = [];
$beforeFailedSync = $db->revision($id);
$runtime = new Runtime($db, $runtimeSettings,
    fn() => new CcxtAccount('bitso', $runtimeSettings, $runtimeSettings['accounts'][0], $lightning),
    function ($level, $event, $context, $message) use (&$events): void {
        $events[] = ['event' => $event, 'context' => $context, 'message' => $message];
    });
$runtime->tick();
$state = $runtime->status()['accounts'][$id];
check($state['state'] === 'RECOVERING' && !$state['rest_ok']
    && $state['last_error']['reason'] === 'withdrawal_payment_hash_mismatch'
    && $db->revision($id) === $beforeFailedSync,
    'conflicting API hash keeps runtime authorization blocked without changing financial state');
$warnings = array_values(array_filter($events, fn($e) => $e['event'] === 'exchange_sync_failed'));
check(count($warnings) === 1 && $warnings[0]['context']['withdrawal_lookup_attempted']
    && $warnings[0]['context']['hash_fields']['lookup.details.tx_hash']['state'] === 'mismatch'
    && str_contains($warnings[0]['message'], 'different') && str_contains($warnings[0]['message'], '0.04033153 BTC'),
    'daemon warning and exchange-status expose the same precise hash diagnosis');
$runtime->close();
$lightning->transport->detailResponse = null;

foreach ([['amount' => '0.04033154'], ['amount' => '0.04033152'],
    ['details' => ['invoice' => $invoices['valid'], 'tx_hash' => str_repeat('a', 64)]],
    ['details' => ['invoice' => $invoices['valid'], 'payment_request' => $invoices['other_invoice'], 'tx_hash' => $invoices['payment_hash']]],
    ['details' => ['invoice' => $invoices['wrong_signer'], 'tx_hash' => $invoices['payment_hash']]],
    ['currency' => 'ATOM']] as $change) {
    $lightning->transport->withdrawals = [array_replace($lnRaw, $change)];
    refuses(fn() => await($lightning->fetch_withdrawals()), 'invoice amounts, payment identity, signature and BTC currency must match the withdrawal');
}
$lightning->transport->withdrawals = [array_replace($lnRaw, ['status' => 'pending'])];
check(Normalize::operation(await($lightning->fetch_withdrawals())[0], 'withdrawal') === null,
    'an invoice alone does not turn a pending payment into confirmed inventory activity');
$lightning->transport->withdrawals = [array_replace($lnRaw, ['network' => 'lightning', 'details' => ['tx_hash' => $invoices['payment_hash']]])];
refuses(fn() => await($lightning->fetch_withdrawals()), 'network name and current fee policy alone never replace transaction evidence');

$lightning->transport->withdrawals = [$missingHash];
$lightning->transport->detailResponse = null;
$lightning->transport->fundings = [array_replace(funding('ln-funding', amount: '1'), ['currency' => 'btc'])];
$lightning->balances['BTC'] = '0.95966847';
$lnBatch = snapshot($lightning);
$lnBatch['prices']['deposit:ln-funding'] = ['price' => '10000', 'source' => 'fixture', 'estimated' => true];
$lnDb = new Database(':memory:');
$lnLedger = new Ledger($lnDb);
$lnId = $lnLedger->createAccount('bitso', 'lightning-read', null, ['USD' => '1000']);
$lnLedger->registerMarket($lnId, 'BTC/USD', 'BTC', 'USD');
$lnSettings = settings()['accounts'][0]; $lnSettings['account_id'] = $lnId;
$lnReconciler = new Reconciler($lnDb);
check($lnReconciler->apply($lnId, $lnBatch, $lnSettings)['reconciled']
    && $lnLedger->expectedBalances($lnId)['BTC'] === '0.95966847', 'invoice-backed zero fee reconciles the wallet without an extra expense');
$lnRevision = $lnDb->revision($lnId);
check($lnReconciler->apply($lnId, $lnBatch, $lnSettings)['reconciled'] && $lnDb->revision($lnId) === $lnRevision,
    'replaying invoice-backed withdrawals is financially idempotent');
$metadata = json_decode($lnDb->pdo()->query("SELECT metadata_json FROM operations WHERE kind='withdrawal'")->fetchColumn(), true);
check($metadata['m3']['fee_evidence']['invoice_sha256'] === $decoded['invoice_sha256']
    && $metadata['m3']['fee_evidence']['payment_hash_source'] === 'invoice',
    'accounting persists the invoice digest and verified zero-fee source for audit');
foreach ($lnBatch['operations'] as &$op) { unset($op['fee_evidence']); } unset($op);
$lnReconciler->apply($lnId, $lnBatch, $lnSettings);
$metadata = json_decode($lnDb->pdo()->query("SELECT metadata_json FROM operations WHERE kind='withdrawal'")->fetchColumn(), true);
check($metadata['m3']['fee_evidence']['invoice_sha256'] === $decoded['invoice_sha256'],
    'later explicit exchange fee evidence does not erase the original invoice audit record');

// General behavior for unrelated accounts, withdrawal IDs and invoice amounts.
$automatic = new TransferClient();
$plainSettings = settings();
foreach ([['obsolete-reference' => 'obsolete-hash'], true, ['any' => ['value']], []] as $oldMap) {
    $legacyConfig = $plainSettings; $legacyConfig['accounts'][0]['bitso_lightning_payment_hashes'] = $oldMap;
    check(Settings::validate($legacyConfig) === $plainSettings,
        'the superseded hash map is discarded and has no effect on validated account settings');
}
$genericRows = [];
foreach (['amount_m' => '0.01', 'amount_u' => '0.00001', 'amount_whole' => '1'] as $name => $amount) {
    $generic = array_replace($missingHash, ['wid' => 'unrelated-'.$name, 'amount' => $amount,
        'details' => ['invoice' => $invoices[$name]]]);
    $genericRows[] = $generic;
    $automatic->transport->withdrawals = [$generic];
    $accountSettings = $plainSettings; $accountSettings['accounts'][0]['account_id'] = 'account-'.$name;
    $op = snapshot($automatic, $accountSettings)['operations'][0];
    check($op['id'] === 'withdrawal:unrelated-'.$name && $op['amount'] === $amount && $op['fees'] === []
        && $op['fee_evidence']['source'] === 'bitso_lightning_invoice'
        && $op['fee_evidence']['payment_hash_source'] === 'invoice',
        'unrelated accounts and withdrawal amounts reconcile invoice evidence without per-record configuration');
}
$automatic->transport->withdrawals = $genericRows;
check(count(snapshot($automatic)['operations']) === 3, 'multiple withdrawals in one account resolve automatically in the same history page');
$automatic->transport->withdrawals = [$missingHash];
$automatic->transport->detailResponse = ['success' => true, 'payload' => [array_replace($missingHash, ['details' => []])]];
check(snapshot($automatic)['operations'][0]['fee_evidence']['payment_hash_source'] === 'invoice',
    'a verified listed invoice remains associated with the same completed individual withdrawal');
$automatic->transport->withdrawals = [array_replace($missingHash, ['details' => []])];
$automatic->transport->detailResponse = ['success' => true, 'payload' => [$missingHash]];
check(snapshot($automatic)['operations'][0]['fee_evidence']['payment_hash_source'] === 'invoice',
    'an invoice supplied only by the individual withdrawal also establishes the association');
$automatic->transport->detailResponse = null;
$legacyConfig['accounts'][0]['bitso_lightning_payment_hashes'] = [substr(hash('sha256', $missingHash['wid']), 0, 16) => $invoices['payment_hash']];
refuses(fn() => snapshot($automatic, Settings::validate($legacyConfig)),
    'a legacy config entry cannot supply missing invoice or monetary evidence');
foreach (['0.04033152', '0.04033154'] as $amount) {
    $automatic->transport->withdrawals = [array_replace($missingHash, ['amount' => $amount])];
    refuses(fn() => snapshot($automatic), 'invoice-derived identity still requires exact equality of total debit and invoice amount');
}
$explicitFee = $missingHash; $explicitFee['details']['fee'] = '0.000001';
$automatic->transport->withdrawals = [$explicitFee];
$op = snapshot($automatic)['operations'][0];
check($op['amount'] === '0.04033053' && $op['fees'] === [['currency' => 'BTC', 'cost' => '0.000001']]
    && !isset($op['fee_evidence']), 'explicit API fees remain authoritative in the generic adapter');
$automatic->transport->withdrawals = [$missingHash];
$row = await($automatic->fetch_withdrawals())[0];
foreach ([['payment_hash_source' => 'operator'], ['operator_confirmation' => []],
    ['source' => 'operator_bitso_lightning_invoice']] as $change) {
    $badRow = $row; $badRow['m3_fee_evidence'] = array_replace($badRow['m3_fee_evidence'], $change);
    refuses(fn() => Normalize::operation($badRow, 'withdrawal'), 'new exchange records cannot masquerade as operator-confirmed evidence');
}

// Existing operator audit data is historical evidence, never a runtime lookup map.
$legacyDb = new Database(':memory:'); $legacyLedger = new Ledger($legacyDb);
$legacyId = $legacyLedger->createAccount('bitso', 'legacy-evidence', null, ['USD' => '1000']);
$legacyLedger->registerMarket($legacyId, 'BTC/USD', 'BTC', 'USD');
$legacySettings = $plainSettings['accounts'][0]; $legacySettings['account_id'] = $legacyId;
$automatic->transport->fundings = [array_replace(funding('automatic-funding', amount: '1'), ['currency' => 'btc'])];
$automatic->balances['BTC'] = '0.95966847';
$automaticBatch = snapshot($automatic);
$automaticBatch['prices']['deposit:automatic-funding'] = ['price' => '10000', 'source' => 'fixture', 'estimated' => true];
$oldBatch = $automaticBatch;
foreach ($oldBatch['operations'] as &$op) {
    if ($op['kind'] !== 'withdrawal') { continue; }
    $op['fee_evidence']['source'] = 'operator_bitso_lightning_invoice';
    unset($op['fee_evidence']['payment_hash_source']);
    $op['fee_evidence']['operator_confirmation'] = ['account_id' => $legacyId,
        'withdrawal_ref' => substr(hash('sha256', $missingHash['wid']), 0, 16), 'payment_hash' => $invoices['payment_hash']];
} unset($op);
$legacyReconciler = new Reconciler($legacyDb);
$legacyReconciler->apply($legacyId, $oldBatch, $legacySettings);
check($legacyReconciler->apply($legacyId, $automaticBatch, $legacySettings)['reconciled']
    && $legacyLedger->expectedBalances($legacyId)['BTC'] === '0.95966847',
    'automatic evidence updates a previously imported withdrawal without duplicating its financial effect');
$metadata = json_decode($legacyDb->pdo()->query("SELECT metadata_json FROM operations WHERE kind='withdrawal'")->fetchColumn(), true);
check($metadata['m3']['fee_evidence']['source'] === 'operator_bitso_lightning_invoice'
    && $metadata['m3']['evidence']['fee_evidence']['source'] === 'bitso_lightning_invoice'
    && $metadata['m3']['evidence']['fee_evidence']['payment_hash_source'] === 'invoice',
    'original operator audit is preserved while current reconciliation uses automatic exchange evidence');
$legacyRevision = $legacyDb->revision($legacyId);
check($legacyReconciler->apply($legacyId, $automaticBatch, $legacySettings)['reconciled']
    && $legacyDb->revision($legacyId) === $legacyRevision, 'replaying the automatic evidence is idempotent after upgrading an old audit record');
$otherId = $legacyLedger->createAccount('bitso', 'other-legacy-account', null, ['USD' => '1000']);
$otherSettings = $legacySettings; $otherSettings['account_id'] = $otherId;
refuses(fn() => $legacyReconciler->apply($otherId, $oldBatch, $otherSettings), 'legacy operator evidence still cannot be replayed into another account');
$query = $legacyDb->pdo()->prepare('SELECT COUNT(*) FROM operations WHERE account_id=?'); $query->execute([$otherId]);
check((int)$query->fetchColumn() === 0, 'cross-account legacy replay leaves no financial records');

$paged = new TransferClient();
for ($i = 205; $i > 0; --$i) { $paged->transport->fundings[] = funding('deposit-'.$i, $i); }
$batch = snapshot($paged, settings(1000));
$calls = array_values(array_filter($paged->transport->calls, fn($c) => $c['path'] === '/v3/fundings'));
check(count($batch['operations']) === 205 && count($calls) === 3, '205 deposits traverse all pages even when configured limit exceeds the Bitso maximum');
check(array_column(array_column($calls, 'params'), 'limit') === ['100', '100', '100'], 'pagination uses the actual server page limit of at most 100');
check($calls[1]['params']['marker'] === 'deposit-106' && $calls[2]['params']['marker'] === 'deposit-6', 'funding ID markers advance without timestamp pagination');

$equal = new TransferClient();
$equal->transport->fundings = [funding('a'), funding('z'), funding('m')];
check(count(snapshot($equal, settings(2))['operations']) === 3 && $equal->transport->calls[1]['params']['marker'] === 'z',
    'equal timestamps use the last server row as marker rather than lexical ID order');
$equal->transport->overlap = true;
check(count(snapshot($equal, settings(2))['operations']) === 3, 'overlapping transfer pages deduplicate confirmed identities');
$equal->transport->conflict = true;
refuses(fn() => snapshot($equal, settings(2)), 'conflicting duplicate transfer evidence cannot be silently discarded');
$equal->transport->conflict = false; $equal->transport->repeat = true;
refuses(fn() => snapshot($equal, settings(2)), 'a repeated full transfer page rejects reconciliation');
refuses(fn() => snapshot($paged, settings(100, 1)), 'transfer page budget exhaustion cannot claim complete history');

$late = new TransferClient();
$late->transport->fundings = [funding('late', 1000, '2', 'pending')];
$context = ['symbols' => ['ATOM/USD'], 'since_ms' => 5000000, 'transfer_since_ms' => 0, 'known_orders' => []];
check(snapshot($late, context: $context)['operations'] === [], 'pending funding has no confirmed accounting effect');
$late->transport->fundings[0]['status'] = 'complete';
check(count(snapshot($late, context: $context)['operations']) === 1, 'older pending transfers are rediscovered after the trade cursor advances');
$context['transfer_since_ms'] = 1000001;
check(snapshot($late, context: $context)['operations'] === [], 'transfer reads respect the configured or Hard H0 financial boundary');

foreach ([['status' => 'complete'], ['fids' => 'PRIVATE_ID'], ['wids' => 'PRIVATE_ID'], ['currency' => 'atom'], ['sort' => 'asc'], ['limit' => 1]] as $params) {
    refuses(fn() => await($client->fetch_deposits(null, null, 100, $params)), 'transfer filters cannot silently truncate account history');
}
$policy = settings(); $policy['accounts'][0]['history_params']['fetch_deposits'] = ['mode' => 'all'];
refuses(fn() => snapshot($late, $policy), 'single-page mode cannot declare Bitso transfer history complete');
$policy['accounts'][0]['history_params']['fetch_deposits'] = ['mode' => 'backward_id', 'params' => ['marker' => 'PRIVATE_ID']];
refuses(fn() => snapshot($late, $policy), 'a configured initial marker cannot skip newer transfer history');
foreach ([[], ['success' => true], ['success' => true, 'payload' => ['bad' => []]], ['success' => true, 'payload' => [['fid' => 'x', 'wid' => 'y']]]] as $response) {
    $late->transport->payloadOverride = $response;
    refuses(fn() => snapshot($late), 'malformed transfer history fails closed rather than appearing empty');
}
$late->transport->payloadOverride = null;
$late->transport->fundings = [funding('old', 100), funding('new', 200)];
refuses(fn() => snapshot($late), 'unexpected forward ordering cannot prematurely stop a backward history scan');

// An empty 404 response follows the real pinned HTTP path without a PHP warning.
$broken = new TransferClient(); $broken->transport->httpStatus = 404;
set_error_handler(static function (int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });
try {
    try { snapshot($broken); throw new RuntimeException('Expected HTTP failure'); }
    catch (ccxt\ExchangeNotAvailable $error) {
        check(SyncDiagnostics::describe($error, 'bitso')['http_status'] === 404, 'empty HTTP 404 keeps the actionable HTTP error without string-offset warnings');
    }
} finally { restore_error_handler(); }
check(BitsoReadClient::is_json_encoded_object('') === false && BitsoReadClient::is_json_encoded_object('{}') === true,
    'empty-body guard preserves valid CCXT JSON recognition');
foreach ([$client, $lookup, $lightning, $automatic, $paged, $equal, $late, $broken] as $fixture) { $fixture->close(); }
echo json_encode(['suite' => 'm3-bitso-transfers', 'status' => 'passed', 'checks' => $checks,
    'network_requests' => 0, 'exchange_orders_submitted' => 0], JSON_PRETTY_PRINT).PHP_EOL;
