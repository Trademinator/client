<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use React\Http\Message\Response;
use Trademinator\Client\M1\{Database, Ledger};
use Trademinator\Client\M3\{Accounting, BitsoHistoryProbe, InventoryEvidenceError, Numbers, Reconciler, Settings};
use function React\Promise\resolve;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks; echo "PASS $message\n";
}
function refuses(callable $action, string $message): void
{
    try { $action(); } catch (Throwable) { check(true, $message); return; }
    check(false, $message);
}
final class ProbeBrowser
{
    public array $pages = [];
    public array $calls = [];
    public array $counts = [];
    public function request($method, $url, $headers, $body): object
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($query, $params);
        $auth = explode(':', $headers['Authorization'] ?? '');
        $expected = hash_hmac('sha256', ($auth[1] ?? '').'GET'.$path.($query === '' ? '' : '?'.$query), 'PRIVATE_FIXTURE_SECRET');
        if ($method !== 'GET' || ($body !== null && $body !== '') || count($auth) !== 3 || $auth[0] !== 'Bitso PRIVATE_FIXTURE_KEY'
            || !hash_equals($expected, $auth[2])) { throw new RuntimeException('Invalid fixture request or signature.'); }
        $this->calls[] = ['path' => $path, 'params' => $params, 'host' => parse_url($url, PHP_URL_HOST)];
        $index = $this->counts[$path] ?? 0; $this->counts[$path] = $index + 1;
        $pages = $this->pages[$path] ?? [$path === '/rfq/v1/conversions' ? ['conversions' => []] : ['success' => true, 'payload' => []]];
        $page = $pages[min($index, count($pages) - 1)];
        return resolve(new Response($page['__http'] ?? 200, [], json_encode($page['__body'] ?? $page, JSON_THROW_ON_ERROR)));
    }
}
final class ProbeClient extends BitsoHistoryProbe
{
    public ProbeBrowser $transport;
    public function set_request_browser($connector)
    {
        $this->transport ??= new ProbeBrowser(); $this->browser = $this->transport;
    }
    public function __construct()
    {
        parent::__construct(['apiKey' => 'PRIVATE_FIXTURE_KEY', 'secret' => 'PRIVATE_FIXTURE_SECRET',
            'number' => 'strval', 'enableRateLimit' => false]);
    }
}
function page(array $rows): array { return ['success' => true, 'payload' => $rows]; }
function trade(string $id = 'PRIVATE_TRADE', string $at = '2022-01-01T10:00:32Z'): array
{
    return ['tid' => $id, 'oid' => 'PRIVATE_ORDER', 'book' => 'xrp_usdc', 'major_currency' => 'xrp', 'minor_currency' => 'usdc',
        'side' => 'sell', 'major' => '-8', 'minor' => '20.25', 'price' => '2.53125', 'fees_currency' => 'usdc', 'fees_amount' => '0',
        'created_at' => $at, 'origin_id' => 'PRIVATE_ORIGIN'];
}
function transfer(string $kind, string $id, string $at, string $currency, string $amount): array
{
    return [$kind === 'deposit' ? 'fid' : 'wid' => $id, 'created_at' => $at, 'currency' => $currency, 'asset' => $currency,
        'status' => 'complete', 'amount' => $amount, 'details' => ['tx_hash' => 'PRIVATE_HASH', 'email' => 'PRIVATE_EMAIL',
            'recipient_name' => 'PRIVATE_NAME', 'withdrawal_address' => 'PRIVATE_ADDRESS', 'invoice' => 'PRIVATE_INVOICE']];
}
function conversion(string $id = 'PRIVATE_CONVERSION', string $at = '2022-01-01T10:00:32Z', string $status = 'COMPLETED'): array
{
    return ['id' => $id, 'created_at' => $at, 'updated_at' => $at, 'source' => 'XRP', 'target' => 'USDC',
        'source_amount' => '8', 'target_amount' => '20.25', 'status' => $status, 'quote_id' => 'PRIVATE_QUOTE'];
}
function report(ProbeClient $client, int $pages = 1000, int $limit = 100, ?callable $progress = null, bool $conversionSources = false): array
{
    return $client->inspect(BitsoHistoryProbe::time('2022-01-01T10:00:00Z'), BitsoHistoryProbe::time('2022-01-01T10:10:00Z'),
        BitsoHistoryProbe::time('2022-01-01T10:00:33Z'), $pages, $limit, $progress, $conversionSources);
}

$client = new ProbeClient();
$client->transport->pages = [
    '/api/v3/user_trades' => [page([trade()])],
    '/api/v3/fundings' => [page([transfer('deposit', 'PRIVATE_FUNDING', '2022-01-01T10:00:00Z', 'xrp', '8')])],
    '/api/v3/withdrawals/' => [page([array_replace(transfer('withdrawal', 'PRIVATE_WITHDRAWAL', '2022-01-01T10:05:49Z', 'usd', '7.5'), ['asset' => 'usdc'])])],
    '/rfq/v1/conversions' => [['conversions' => [conversion()]]],
];
$progress = [];
$r = report($client, progress: static function(...$args) use (&$progress): void { $progress[] = $args; });
check($r['status'] === 'window_checked' && array_unique(array_column($r['sources'], 'coverage')) === ['window_covered'], 'all four independent API sources report their window coverage');
check(array_column($r['timeline'], 'kind') === ['deposit', 'conversion', 'trade', 'withdrawal'], 'timeline combines endpoint responses chronologically');
check($r['timeline'][2]['normalized_trade']['symbol'] === 'XRP/USDC' && $r['timeline'][2]['normalized_trade']['cost'] === '20.25', 'pinned CCXT parser retains historical conversion trade currency and exact proceeds without current markets');
check($r['timeline'][3]['raw']['currency'] === 'usd' && $r['timeline'][3]['raw']['asset'] === 'usdc' && $r['timeline'][3]['ccxt_currency'] === 'USD', 'raw wallet currency and asset are shown separately from CCXT currency');
check($r['timeline'][2]['timestamp_matches_ccxt'] && $r['timeline'][3]['timestamp_matches_ccxt']
    && $r['timeline'][3]['ccxt_at'] === $r['timeline'][3]['at'], 'raw and CCXT timestamps are both visible for chronological diagnosis');
check($r['timeline'][3]['raw']['listed_fee'] === null && $r['timeline'][3]['fee_resolution'] === 'not_performed', 'missing fee remains unknown and does not block diagnostic collection');
check($r['window_starts_before_configured_history'] && $r['timeline'][2]['before_configured_history'] && !$r['timeline'][3]['before_configured_history'], 'configured history cutoff is reported without hiding excluded events');
check($r['timeline'][1]['imported_by_current_m3'] === false && count($r['timeline']) === 4 && !isset($r['balances']), 'RFQ conversions and underlying trades remain separate evidence with no double-counted balance');
check(!str_contains(Numbers::json($r), 'PRIVATE_') && !str_contains(Numbers::json($r), dirname(__DIR__)), 'report omits credentials raw IDs recipients invoices hashes addresses and local paths');
check(count($progress) === 4 && $progress[0] === ['user_trades', 1, 0], 'progress exposes only source page and count');
check($client->transport->calls[0]['params'] === ['limit' => '100', 'sort' => 'desc']
    && $client->transport->calls[3]['host'] === 'api.bitso.com', 'account-wide v3 and RFQ requests use their documented hosts paths and signed query strings');
$client->close();

// Boundary precision is the same as the ledger; no float conversion in the tool.
$client = new ProbeClient();
$client->transport->pages['/api/v3/user_trades'] = [page([array_replace(trade(), [
    'minor' => '20.250000000000000000000001', 'fees_amount' => '0.000000000000000000000001'])])];
$r = report($client);
check($r['timeline'][0]['raw']['minor'] === '20.250000000000000000000001'
    && $r['timeline'][0]['normalized_trade']['fees'][0]['cost'] === '0.000000000000000000000001', 'diagnostic preserves exact 24-place trade proceeds and fees');
$client->close();

// Overlapping pages and equal-time records must not hide the lower boundary.
$client = new ProbeClient();
$huge = '922337203685477580812345';
$client->transport->pages['/api/v3/user_trades'] = [
    page([trade('newer', '2022-01-01T11:00:00Z'), trade($huge, '2022-01-01T10:00:00Z')]),
    page([trade($huge, '2022-01-01T10:00:00Z'), trade('boundary-2', '2022-01-01T10:00:00Z')]),
    page([trade('boundary-3', '2022-01-01T10:00:00Z'), trade('older', '2022-01-01T09:59:59Z')]),
];
$r = report($client, 3, 2);
check($r['sources']['user_trades']['pages_read'] === 3 && $r['sources']['user_trades']['duplicates'] === 1
    && $r['sources']['user_trades']['events_in_window'] === 3, 'overlapping full pages include every equal-time lower-bound record once');
check($client->transport->calls[1]['params']['marker'] === $huge && $r['sources']['user_trades']['completion'] === 'lower_bound_crossed', 'opaque large markers remain exact and scan crosses the lower bound');
$client->close();

$client = new ProbeClient();
$client->transport->pages['/rfq/v1/conversions'] = [
    ['conversions' => [conversion('later', '2022-01-01T10:02:00Z', 'PENDING')], 'next_page_token' => 'PRIVATE_CURSOR_A'],
    ['conversions' => [], 'next_page_token' => 'PRIVATE_CURSOR_B'],
    ['conversions' => [conversion('earlier', '2022-01-01T10:01:00Z')]],
];
$r = report($client, 3);
$rfqCalls = array_values(array_filter($client->transport->calls, static fn($c) => $c['path'] === '/rfq/v1/conversions'));
check(count($rfqCalls) === 3 && $r['sources']['rfq_conversions']['coverage'] === 'window_covered', 'RFQ follows opaque next tokens across short and empty filtered pages without assuming chronology');
$second = $rfqCalls[1]['params']; unset($second['page_token']);
check($second === $rfqCalls[0]['params'] && $rfqCalls[1]['params']['page_token'] === 'PRIVATE_CURSOR_A', 'RFQ preserves date filters and page size on every token request');
check($r['timeline'][0]['raw']['status'] === 'COMPLETED' && $r['timeline'][1]['raw']['status'] === 'PENDING'
    && !str_contains(Numbers::json($r), 'PRIVATE_CURSOR'), 'pending conversion is visible but never counted as a completed acquisition');
$client->close();

foreach (['repeat', 'conflict', 'forward', 'budget', 'malformed'] as $case) {
    $client = new ProbeClient();
    $rows = [trade('a', '2022-01-01T10:02:00Z'), trade('b', '2022-01-01T10:01:00Z')];
    $pages = [page($rows)]; $budget = 4;
    if ($case === 'conflict') { $pages[] = page([array_replace($rows[1], ['minor' => '21']), trade('c')]); }
    if ($case === 'forward') { $pages[] = page([trade('c', '2022-01-01T10:03:00Z')]); }
    if ($case === 'budget') { $budget = 1; }
    if ($case === 'malformed') { $pages = [['success' => true, 'payload' => ['PRIVATE_BAD' => []]]]; }
    $client->transport->pages['/api/v3/user_trades'] = $pages;
    $r = report($client, $budget, 2);
    $reason = ['repeat' => 'non_progressing_cursor', 'conflict' => 'conflicting_identity', 'forward' => 'non_chronological_page',
        'budget' => 'page_budget_exhausted', 'malformed' => 'invalid_page'][$case];
    check($r['status'] === 'partial' && $r['sources']['user_trades']['coverage'] === 'partial'
        && $r['sources']['user_trades']['error']['reason'] === $reason && $r['sources']['withdrawals']['coverage'] === 'window_covered',
        "$case history is explicit partial evidence while other sources continue");
    $client->close();
}

$client = new ProbeClient();
$client->transport->pages['/rfq/v1/conversions'] = [['__http' => 404, '__body' => ['errors' => [['message' => 'PRIVATE_FIXTURE_SECRET PRIVATE_BODY']]]]];
$r = report($client);
check($r['status'] === 'partial' && $r['sources']['rfq_conversions']['error']['http_status'] === 404
    && !str_contains(Numbers::json($r), 'PRIVATE_'), 'unavailable RFQ endpoint has an explicit sanitized error and does not masquerade as empty history');
$client->close();

$client = new ProbeClient();
$client->transport->pages['/rfq/v1/conversions'] = [
    ['conversions' => [], 'next_page_token' => 'PRIVATE_A'], ['conversions' => [], 'next_page_token' => 'PRIVATE_B'],
    ['conversions' => [], 'next_page_token' => 'PRIVATE_A'],
];
$r = report($client, 10);
check($r['sources']['rfq_conversions']['pages_read'] === 3 && $r['sources']['rfq_conversions']['error']['reason'] === 'non_progressing_cursor', 'RFQ token cycles stop without leaking opaque tokens');
$client->close();

$client = new ProbeClient();
$client->transport->pages['/rfq/v1/conversions'] = [['conversions' => [conversion('outside', '2022-01-01T10:10:00Z')]]];
$r = report($client);
check($r['sources']['rfq_conversions']['error']['reason'] === 'unexpected_filter_result' && $r['timeline'] === [], 'exclusive RFQ upper bound is enforced locally');
$client->close();

$client = new ProbeClient();
$client->transport->pages['/api/v3/user_trades'] = [page([array_replace(trade(), ['book' => 'PRIVATE_FIXTURE_KEY_usdc'])])];
$r = report($client);
check(!str_contains(strtolower(Numbers::json($r)), 'private_fixture_key'), 'credential echoed into a machine-code field is redacted even after parser case conversion');
$client->close();

$client = new ProbeClient();
for ($i = 0; $i < 11; ++$i) {
    $rows = [];
    for ($j = 0; $j < 100; ++$j) { $rows[] = trade('many-'.($i * 100 + $j)); }
    $client->transport->pages['/api/v3/user_trades'][] = page($rows);
}
$r = report($client);
check($r['sources']['user_trades']['error']['reason'] === 'event_limit_reached' && count($r['timeline']) === 1000, 'large requested windows stop with explicit output truncation instead of unbounded output');
$client->close();

check(BitsoHistoryProbe::time('2022-01-01T06:00:00-04:00') === BitsoHistoryProbe::time('2022-01-01T10:00:00Z'), 'explicit local and UTC timestamps identify the same instant');
foreach (['2022-01-01', '2022-01-01T10:00:00', '2022-02-30T10:00:00Z', 'tomorrow'] as $bad) {
    refuses(fn() => BitsoHistoryProbe::time($bad), 'rejects ambiguous or invalid window '.$bad);
}
$client = new ProbeClient();
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) { refuses(fn() => $client->sign('orders', 'private', $method), 'probe blocks '.$method.' before HTTP'); }
refuses(fn() => $client->sign('other', 'diagnostic_rfq'), 'RFQ signer is restricted to the documented read path');
check($client->transport->calls === [], 'write and malformed requests never reach the transport');
$client->close();

// Conversion source investigation: no private CSVs, ledger writes or account assumptions.
function ledgerEntry(string $id = 'PRIVATE_LEDGER', string $at = '2022-01-01T10:00:32Z'): array
{
    return ['eid' => $id, 'created_at' => $at, 'operation' => 'trade',
        'balance_updates' => [['currency' => 'xrp', 'amount' => '-8', 'address' => 'PRIVATE_ADDRESS'],
            ['currency' => 'usd', 'amount' => '20.250000000000000000000001']],
        'details' => ['tid' => 'PRIVATE_TRADE', 'oid' => 'PRIVATE_ORDER', 'email' => 'PRIVATE_EMAIL', 'invoice' => 'PRIVATE_INVOICE']];
}
$client = new ProbeClient();
$client->transport->pages['/rfq/v1/conversions'] = [
    ['conversions' => []], // The server-filtered query missed the conversion.
    ['conversions' => [conversion('after', '2022-01-02T10:00:00Z'), conversion('before', '2021-01-01T10:00:00Z')], 'next_page_token' => 'PRIVATE_A'],
    ['conversions' => [conversion(), conversion('upper-bound', '2022-01-01T10:10:00Z')], 'next_page_token' => 'PRIVATE_B'],
    ['conversions' => [], 'next_page_token' => 'PRIVATE_C'],
    ['conversions' => [conversion(), conversion('second', '2022-01-01T10:01:00Z')]],
];
$client->transport->pages['/api/v3/ledger'] = [['__http' => 404, '__body' => ['message' => 'PRIVATE_FIXTURE_SECRET PRIVATE_BODY']]];
$client->transport->pages['/api/v3/ledger/trades'] = [page([ledgerEntry()]), page([])];
$r = report($client, 4, 2, conversionSources: true);
check($r['scope'] === 'conversion_sources' && !$r['history_completeness_verified']
    && array_keys($r['sources']) === ['rfq_conversions', 'rfq_conversions_unfiltered', 'ledger', 'ledger_trades'],
    'conversion mode checks only four explicit sources without claiming account history is complete');
check($r['sources']['rfq_conversions']['events_in_window'] === 0 && $r['sources']['rfq_conversions_unfiltered']['events_in_window'] === 2,
    'unfiltered RFQ can reveal in-window evidence omitted by the server-filtered response');
check($r['sources']['rfq_conversions_unfiltered']['records_scanned'] === 6
    && $r['sources']['rfq_conversions_unfiltered']['duplicates'] === 1
    && $r['sources']['rfq_conversions_unfiltered']['pages_read'] === 4,
    'unfiltered RFQ follows all tokens across empty unordered and out-of-window pages and deduplicates IDs');
$calls = array_values(array_filter($client->transport->calls, static fn($c) => $c['path'] === '/rfq/v1/conversions'));
check(isset($calls[0]['params']['created_at_gte'], $calls[0]['params']['created_at_lt']) && $calls[1]['params'] === ['page_size' => '2']
    && $calls[2]['params'] === ['page_size' => '2', 'page_token' => 'PRIVATE_A']
    && $calls[4]['params'] === ['page_size' => '2', 'page_token' => 'PRIVATE_C'],
    'unfiltered scan starts a fresh signed query and never inherits date status or cursor filters');
check($r['sources']['rfq_conversions']['time_filter'] === 'server_and_local'
    && $r['sources']['rfq_conversions_unfiltered']['time_filter'] === 'local_only', 'report distinguishes server filtering from local window filtering');
check($r['status'] === 'partial' && $r['sources']['ledger']['error']['http_status'] === 404
    && $r['sources']['ledger_trades']['coverage'] === 'window_covered', 'ledger root 404 does not skip ledger/trades or masquerade as empty data');
$leg = array_values(array_filter($r['timeline'], static fn($e) => $e['source'] === 'ledger_trades'))[0];
check($leg['raw']['balance_updates'] === [['currency' => 'xrp', 'amount' => '-8'], ['currency' => 'usd', 'amount' => '20.250000000000000000000001']],
    'raw ledger diagnostics retain both signed currency legs with full decimal precision');
check($leg['trade_ref'] === substr(hash('sha256', 'PRIVATE_TRADE'), 0, 16)
    && $leg['order_ref'] === substr(hash('sha256', 'PRIVATE_ORDER'), 0, 16) && $leg['imported_by_current_m3'] === false,
    'ledger evidence exposes consistent hashed trade and order references without importing it');
$ledgerCalls = array_values(array_filter($client->transport->calls, static fn($c) => $c['path'] === '/api/v3/ledger/trades'));
check(count($ledgerCalls) === 2 && $ledgerCalls[0]['params'] === ['limit' => '2', 'sort' => 'desc']
    && $ledgerCalls[1]['params']['marker'] === 'PRIVATE_LEDGER', 'short legacy ledger page is followed until an empty page or lower boundary');
check(!str_contains(Numbers::json($r), 'PRIVATE_') && !isset($r['balances']), 'conversion source report omits credentials cursors raw identities and private details without balancing entries');
$client->close();

$client = new ProbeClient();
$client->transport->pages['/rfq/v1/conversions'] = [['conversions' => []],
    ['conversions' => [conversion('old', '2021-01-01T00:00:00Z')], 'next_page_token' => 'PRIVATE_NEXT']];
$r = report($client, 1, conversionSources: true);
check($r['sources']['rfq_conversions_unfiltered']['coverage'] === 'partial'
    && $r['sources']['rfq_conversions_unfiltered']['error']['reason'] === 'page_budget_exhausted',
    'unfiltered RFQ does not infer exhaustion from a short old page when a token remains');
$client->close();

foreach (['conflict', 'forward', 'malformed', 'repeat', 'fee'] as $case) {
    $client = new ProbeClient();
    $row = ledgerEntry();
    $pages = [page([$row])];
    if ($case === 'conflict') { $changed = $row; $changed['balance_updates'][1]['amount'] = '21'; $pages[] = page([$changed]); }
    if ($case === 'forward') { $pages[] = page([ledgerEntry('later', '2022-01-01T10:01:00Z')]); }
    if ($case === 'malformed') { $row['balance_updates'][1] = ['currency' => 'usd']; $pages = [page([$row])]; }
    if ($case === 'fee') { $row['operation'] = 'fee'; $row['balance_updates'] = [['currency' => 'usd', 'amount' => '-0.25']]; $pages = [page([$row]), page([])]; }
    $client->transport->pages['/api/v3/ledger/trades'] = $pages;
    $r = report($client, 3, conversionSources: true);
    if ($case === 'fee') {
        check($r['timeline'][0]['raw']['operation'] === 'fee' && $r['timeline'][0]['raw']['balance_updates'][0]['amount'] === '-0.25',
            'ledger fee remains a separate signed expense in diagnostic output');
    } else {
        $reason = ['conflict' => 'conflicting_identity', 'forward' => 'non_chronological_page',
            'malformed' => 'invalid_record', 'repeat' => 'non_progressing_cursor'][$case];
        check($r['sources']['ledger_trades']['coverage'] === 'partial' && $r['sources']['ledger_trades']['error']['reason'] === $reason,
            'legacy ledger '.$case.' cannot claim complete window coverage');
    }
    $client->close();
}

// Financial regression: normalized evidence supplied in every arrival order.
// These synthetic fills test replay, not whether a real app conversion reaches this API.
function replay(array $sequence, string $credit, string $debit, bool $early = false, bool $missing = false): array
{
    $db = new Database(':memory:'); $ledger = new Ledger($db); $math = new Accounting($db); $r = new Reconciler($db);
    $id = $ledger->createAccount('fixture', 'Chronological conversion');
    foreach (array_unique([$credit.'/MXN', $debit.'/MXN', 'XRP/'.$credit]) as $symbol) { $math->market($id, $symbol); }
    $settings = Settings::validate(['accounts' => [['account_id' => $id, 'history_since_ms' => 0,
        'api_key_env' => 'UNUSED', 'secret_env' => 'UNUSED']]])['accounts'][0];
    $op = static fn($key, $kind, $currency, $amount, $at) => ['id' => $key, 'kind' => $kind, 'currency' => $currency, 'amount' => $amount,
        'timestamp' => $at, 'fees' => [], 'txid' => null, 'address' => null, 'tag' => null, 'network' => null];
    $events = [$op('in', 'deposit', 'XRP', '8', 1000),
        ['id' => 'fill', 'order' => 'order', 'symbol' => 'XRP/'.$credit, 'side' => 'SELL', 'amount' => '8', 'price' => '2.53125',
            'cost' => '20.25', 'timestamp' => 2000, 'fees' => []],
        $op('out', 'withdrawal', $debit, '7.5', $early ? 1500 : 3000)];
    $batch = ['complete' => true, 'at_ms' => 4000, 'trades' => [], 'orders' => [], 'operations' => [], 'prices' => [],
        'balances' => [$credit => ['total' => '12.75', 'free' => '12.75', 'used' => '0']]];
    foreach ($sequence as $i) {
        if ($i !== 1) { $batch['operations'][] = $events[$i]; continue; }
        if ($missing) { continue; }
        $t = $events[$i]; $batch['trades'][] = $t;
        $batch['orders'][] = ['id' => 'order', 'symbol' => $t['symbol'], 'side' => 'SELL', 'type' => 'LIMIT', 'status' => 'filled',
            'amount' => $t['amount'], 'filled' => $t['amount'], 'price' => $t['price'], 'cost' => $t['cost'], 'timestamp' => 2000, 'client_order_id' => null];
    }
    $before = $ledger->accountSnapshot($id);
    try {
        $r->validateInventory($id, $batch, $settings);
        if ($before !== $ledger->accountSnapshot($id)) { throw new RuntimeException('Preflight changed live records.'); }
        $result = $r->apply($id, $batch, $settings);
        $out = $math->rows("SELECT * FROM operations WHERE kind='withdrawal'")[0];
        $taken = Numbers::decode($out['lot_consumptions_json']);
        return ['ok' => $result['reconciled'] && array_values($taken) === ['7.5'] && str_contains(array_key_first($taken), ':quote:fill')
            && $ledger->expectedBalances($id) === [$credit => '12.75']];
    } catch (InventoryEvidenceError $e) { return ['ok' => false, 'reason' => $e->diagnostic['reason']]; }
}
foreach (['USD', 'USDC'] as $currency) {
    foreach ([[0,1,2], [0,2,1], [1,0,2], [1,2,0], [2,0,1], [2,1,0]] as $sequence) {
        check(replay($sequence, $currency, $currency)['ok'], 'chronological '.$currency.' inventory survives arrival order '.implode(',', $sequence));
    }
}
check(replay([2,1,0], 'USD', 'USD', early: true) === ['ok' => false, 'reason' => 'inventory_shortfall'], 'later conversion cannot fund an earlier withdrawal');
check(replay([2,1,0], 'USD', 'USD', missing: true) === ['ok' => false, 'reason' => 'inventory_shortfall'], 'missing conversion remains an inventory shortfall');
check(replay([2,1,0], 'USDC', 'USD') === ['ok' => false, 'reason' => 'inventory_shortfall'], 'USDC proceeds do not silently become USD inventory');

// CLI failures must emit usable JSON without touching the configured data directory.
$temp = sys_get_temp_dir().'/m3-history-probe-'.bin2hex(random_bytes(6));
mkdir($temp, 0700);
try {
    $config = $temp.'/config.json';
    file_put_contents($config, Numbers::json(['data_dir' => $temp.'/must-not-exist', 'exchange' => ['accounts' => [[
        'account_id' => 'fixture', 'history_since_ms' => 0, 'api_key_file' => $temp.'/PRIVATE_MISSING_KEY',
        'secret_file' => $temp.'/PRIVATE_MISSING_SECRET',
    ]]]]));
    $run = static function(array $arguments): array {
        $pipes = [];
        $p = proc_open([PHP_BINARY, dirname(__DIR__).'/tools/m3-bitso-history-check.php', ...$arguments],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($p)) { throw new RuntimeException('Cannot run CLI fixture.'); }
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['exit' => proc_close($p), 'out' => $out, 'err' => $err];
    };
    $args = ['--config='.$config, '--account=fixture', '--from=2022-01-01T00:00:00Z', '--to=2022-01-02T00:00:00Z'];
    $cli = $run($args); $json = json_decode($cli['out'], true, flags: JSON_THROW_ON_ERROR);
    check($cli['exit'] === 1 && $json['status'] === 'needs_review' && $json['stage'] === 'credentials'
        && !str_contains($cli['out'], 'PRIVATE_MISSING') && !is_dir($temp.'/must-not-exist'), 'CLI missing credentials give sanitized JSON without creating or opening a database');
    $cli = $run(array_replace($args, [2 => '--from=2022-01-01T00:00:00']));
    check($cli['exit'] === 1 && json_decode($cli['out'], true)['error']['reason'] === 'invalid_window', 'CLI rejects an unspecified timezone before reading credentials');
    $cli = $run([...$args, '--max-pages=0']);
    check($cli['exit'] === 1 && json_decode($cli['out'], true)['stage'] === 'configuration', 'CLI rejects an invalid page budget before HTTP');
    $cli = $run(['--help']);
    check($cli['exit'] === 0 && str_contains($cli['out'], 'Read only:'), 'CLI help documents the read-only scope and explicit timezone');
    check(str_contains($cli['out'], '--conversion-sources') && str_contains($cli['out'], '10 pages per source'), 'CLI help documents bounded conversion source mode');
} finally { unlink($config); rmdir($temp); }

echo json_encode(['suite' => 'm3-bitso-history-probe', 'status' => 'passed', 'checks' => $checks,
    'network_requests' => 0, 'exchange_orders_submitted' => 0], JSON_PRETTY_PRINT).PHP_EOL;
