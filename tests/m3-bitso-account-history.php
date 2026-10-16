<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use Trademinator\Client\M1\{Database, Ledger};
use Trademinator\Client\M3\{Accounting, BitsoReadClient, CcxtAccount, InventoryEvidenceError, Numbers, Reconciler, Settings, SyncDiagnostics};
use function React\Async\await;
use function React\Promise\resolve;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks; echo 'PASS '.$message.PHP_EOL;
}
function refuses(callable $action, string $message, ?string $detail = null): void
{
    try { $action(); } catch (Throwable $error) {
        check($detail === null || $error->getMessage() === $detail, $message);
        return;
    }
    check(false, $message);
}

// Real CCXT implicit methods/signing/parser, with every HTTP request intercepted.
// Headers and signatures are never recorded; no sockets or credentials are used.
final class HistoryBrowser
{
    public array $trades = [];
    public array $fundings = [];
    public array $withdrawals = [];
    public array $open = [];
    public ?array $confirmation = null;
    public array $orders = [];
    public ?array $orderTrades = null;
    public ?array $tradeResponse = null;
    public array $calls = [];
    public bool $repeat = false;
    public bool $overlap = false;
    public bool $conflict = false;
    private int $openReads = 0;

    public function request($method, $url, $headers, $body): object
    {
        if ($method !== 'GET') { throw new RuntimeException('Unexpected write request.'); }
        $path = preg_replace('~^/api/~', '/', parse_url($url, PHP_URL_PATH));
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $this->calls[] = ['path' => $path, 'params' => $params];
        if ($path === '/v3/user_trades') {
            if ($this->tradeResponse !== null) { return $this->response($this->tradeResponse); }
            $start = 0;
            if (isset($params['marker']) && !$this->repeat) {
                $at = array_search($params['marker'], array_column($this->trades, 'tid'), true);
                if ($at === false) { throw new RuntimeException('Unknown fixture marker.'); }
                $start = $at + ($this->overlap ? 0 : 1);
            }
            $page = array_slice($this->trades, $start, (int)$params['limit']);
            if ($this->conflict && isset($params['marker']) && $page !== []) { $page[0]['minor'] = '999'; }
            return $this->response(['success' => true, 'payload' => $page]);
        }
        if ($path === '/v3/open_orders') {
            $rows = ++$this->openReads > 1 && $this->confirmation !== null ? $this->confirmation : $this->open;
            return $this->response(['success' => true, 'payload' => array_slice($rows, 0, (int)$params['limit'])]);
        }
        if (preg_match('~^/v3/order_trades/([^/]+)$~D', $path, $matches)) {
            $rows = $this->orderTrades ?? array_values(array_filter($this->trades, static fn ($t) => $t['oid'] === rawurldecode($matches[1])));
            return $this->response(['success' => true, 'payload' => $rows]);
        }
        if (preg_match('~^/v3/orders/([^/]+)$~D', $path, $matches)) {
            $order = $this->orders[rawurldecode($matches[1])] ?? null;
            return $this->response($order === null
                ? ['success' => false, 'error' => ['code' => '0312', 'message' => 'PRIVATE_RESPONSE']]
                : ['success' => true, 'payload' => [$order]]);
        }
        if ($path === '/v3/fundings' || $path === '/v3/withdrawals/') {
            return $this->response(['success' => true, 'payload' => $path === '/v3/fundings' ? $this->fundings : $this->withdrawals]);
        }
        throw new RuntimeException('Unexpected fixture endpoint.');
    }
    private function response(array $payload): object
    {
        return resolve(new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
    }
    public function callsFor(string $path): array
    {
        return array_values(array_filter($this->calls, static fn ($c) => $c['path'] === $path));
    }
}

final class HistoryClient extends BitsoReadClient
{
    public HistoryBrowser $transport;
    public function set_request_browser($connector)
    {
        $this->transport ??= new HistoryBrowser();
        $this->browser = $this->transport;
    }
    public function __construct()
    {
        parent::__construct(['apiKey' => 'PRIVATE_FIXTURE_KEY', 'secret' => 'PRIVATE_FIXTURE_SECRET',
            'enableRateLimit' => false, 'number' => 'strval']);
        $this->has['fetchTicker'] = false;
        $this->set_markets([['id' => 'usd_mxn', 'symbol' => 'USD/MXN', 'base' => 'USD', 'quote' => 'MXN',
            'baseId' => 'usd', 'quoteId' => 'mxn', 'spot' => true, 'type' => 'spot', 'precision' => [], 'limits' => []]]);
    }
    public function fetch_balance($params = []): PromiseInterface
    {
        return resolve(['total' => ['USD' => '0', 'BTC' => '0'], 'free' => ['USD' => '0', 'BTC' => '0'], 'used' => ['USD' => '0', 'BTC' => '0']]);
    }
}
function settings(int $limit = 100, int $pages = 1000): array
{
    return Settings::validate(['history_limit' => $limit, 'history_max_pages' => $pages, 'accounts' => [[
        'account_id' => 'fixture', 'api_key_env' => 'UNUSED', 'secret_env' => 'UNUSED', 'history_since_ms' => 0,
    ]]]);
}
function snapshot(HistoryClient $client, ?array $settings = null, int $since = 0, array $known = []): array
{
    $settings ??= settings();
    return await((new CcxtAccount('bitso', $settings, $settings['accounts'][0], $client))->snapshot([
        'symbols' => ['USD/MXN'], 'since_ms' => $since, 'known_orders' => $known,
    ]));
}
function trade(string $id = '7', int $at = 2000): array
{
    return ['tid' => $id, 'oid' => 'old-order', 'book' => 'btc_usd', 'side' => 'sell', 'maker_side' => 'sell',
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', $at), 'major' => '-0.73', 'minor' => '73', 'price' => '100',
        'major_currency' => 'btc', 'minor_currency' => 'usd', 'fees_amount' => '0', 'fees_currency' => 'usd'];
}
function openOrder(string $id = 'open-order'): array
{
    return ['oid' => $id, 'book' => 'btc_usd', 'created_at' => '1970-01-01T00:33:20Z', 'side' => 'sell',
        'type' => 'limit', 'status' => 'open', 'original_amount' => '1', 'unfilled_amount' => '1', 'price' => '100', 'original_value' => '100'];
}

$client = new HistoryClient();
$client->transport->trades = [trade()];
$client->transport->fundings = [['fid' => 'funding', 'status' => 'complete', 'created_at' => '1970-01-01T00:16:40Z',
    'currency' => 'btc', 'amount' => '0.73', 'details' => []]];
$client->transport->withdrawals = [['wid' => 'withdrawal', 'status' => 'complete', 'created_at' => '1970-01-01T00:50:00Z',
    'currency' => 'usd', 'amount' => '73', 'details' => ['fee' => '0', 'amount_to_send' => '73']]];
$batch = snapshot($client);
check($batch['complete'] && count($batch['trades']) === 1 && $batch['trades'][0]['symbol'] === 'BTC/USD', 'account-wide history retains a trade from a removed market');
check(!isset($batch['markets']['BTC/USD']), 'historical parsing does not invent an available market');
check($client->transport->callsFor('/v3/user_trades')[0]['params'] === ['limit' => '100', 'sort' => 'desc'], 'account history has no current-book or subscription filter');
check($batch['orders'][0]['status'] === 'history_only' && $batch['orders'][0]['amount'] === null, 'removed-market fill verification retains unknown original order terms');
check(count($client->transport->callsFor('/v3/order_trades/old-order')) === 1, 'removed-market history is independently confirmed by order ID');
$openCalls = $client->transport->callsFor('/v3/open_orders');
check(count($openCalls) === 2 && $openCalls[0]['params'] === ['limit' => '500'] && $openCalls[1]['params'] === ['limit' => '500'], 'initial and confirming open-order reads cover the whole account without undocumented cursors');

$db = new Database(':memory:'); $ledger = new Ledger($db); $accounting = new Accounting($db); $reconciler = new Reconciler($db);
$account = $ledger->createAccount('bitso', 'Removed market fixture');
$accounting->market($account, 'USD/MXN');
$policy = settings()['accounts'][0]; $policy['account_id'] = $account;
$missing = $batch; $missing['trades'] = []; $missing['orders'] = [];
try { $reconciler->validateInventory($account, $missing, $policy); throw new RuntimeException('Expected shortfall.'); }
catch (InventoryEvidenceError $error) {
    check($error->diagnostic['outflow_currency'] === 'USD' && $error->diagnostic['inventory_available'] === '0'
        && $error->diagnostic['inventory_shortfall'] === '73', 'omitting removed-market proceeds reproduces the zero-USD withdrawal shortfall');
}
$before = $ledger->accountSnapshot($account);
$reconciler->validateInventory($account, $batch, $policy);
check($before === $ledger->accountSnapshot($account), 'recovered history passes quantity preflight without changing live rows');
check($reconciler->apply($account, $batch, $policy)['reconciled'], 'actual earlier sale proceeds fund the later USD withdrawal');
$counts = [count($accounting->rows('SELECT id FROM orders')), count($accounting->rows('SELECT id FROM operations'))];
check($reconciler->apply($account, $batch, $policy)['reconciled'] && $counts === [count($accounting->rows('SELECT id FROM orders')), count($accounting->rows('SELECT id FROM operations'))], 'repeating the recovered account history does not duplicate money');
check(array_column($accounting->rows('SELECT exchange_operation_id FROM operations ORDER BY exchange_operation_id'), 'exchange_operation_id')
    === ['deposit:funding', 'withdrawal:withdrawal'], 'history recovery creates only the two observed transfers and no balancing adjustment');

$many = new HistoryClient();
for ($i = 205; $i > 0; --$i) { $many->transport->trades[] = trade((string)$i, 2000 + $i); }
$all = snapshot($many, settings(200));
$pages = $many->transport->callsFor('/v3/user_trades');
check(count($all['trades']) === 205 && count($pages) === 3, '205 account trades traverse every page with the documented 100-row cap');
check(array_column(array_column($pages, 'params'), 'limit') === ['100', '100', '100'], 'effective page length and requested length agree when configuration exceeds Bitso maximum');

$ties = new HistoryClient(); $ties->transport->overlap = true;
$ties->transport->trades = [trade('9223372036854775819'), trade('9223372036854775818'), trade('9223372036854775817')];
$result = snapshot($ties, settings(2));
check(count($result['trades']) === 3 && $ties->transport->callsFor('/v3/user_trades')[1]['params']['marker'] === '9223372036854775818', 'overlapping equal-time pages preserve exact markers above PHP integer range');
$ties->transport->conflict = true;
refuses(fn () => snapshot($ties, settings(2)), 'conflicting duplicate trade evidence is rejected', 'Conflicting exchange trade identity.');
$repeat = new HistoryClient(); $repeat->transport->trades = [trade('2'), trade('1')]; $repeat->transport->repeat = true;
refuses(fn () => snapshot($repeat, settings(2)), 'repeating full pages cannot declare complete account history', 'History pagination made no progress.');
refuses(fn () => snapshot($many, settings(100, 1)), 'page-budget exhaustion cannot hide older acquisitions', 'History page budget exhausted; history is incomplete.');

$boundary = new HistoryClient(); $boundary->transport->trades = [trade('3', 3000), trade('2', 2000), trade('1', 1000)];
// Separate orders prevent the independent order lookup from legitimately adding
// older fills of the same order back into the batch.
foreach ($boundary->transport->trades as &$fill) { $fill['oid'] = 'order-'.$fill['tid']; } unset($fill);
$limited = snapshot($boundary, settings(2), 2000000);
check(array_column($limited['trades'], 'id') === ['3', '2'], 'configured or Hard-H0 boundary is filtered locally with the exact boundary included');
check(count($boundary->transport->callsFor('/v3/user_trades')) === 2, 'full boundary page still fetches older evidence to establish the cutoff');

$active = new HistoryClient(); $active->transport->open = [openOrder()];
$activeBatch = snapshot($active);
check(count($activeBatch['orders']) === 1 && $activeBatch['orders'][0]['symbol'] === 'BTC/USD', 'open orders outside today\'s market catalog are still visible');
$cap = new HistoryClient();
for ($i = 0; $i < 500; ++$i) { $cap->transport->open[] = openOrder('o'.$i); }
refuses(fn () => snapshot($cap), 'saturated open-order list blocks rather than authorizing a partial snapshot', 'Bitso open-order list reached its limit; completeness cannot be confirmed.');
$appeared = new HistoryClient(); $appeared->transport->trades = [trade()]; $appeared->transport->confirmation = [openOrder('old-order')];
refuses(fn () => snapshot($appeared), 'a historical order that reappears open fails final confirmation', 'Historical order appeared in the open-order confirmation; retry synchronization.');
$known = new HistoryClient(); $known->transport->trades = [trade()];
refuses(fn () => snapshot($known, known: ['old-order' => 'BTC/USD']), 'historical evidence does not silently resolve a locally open order');

$legacy = settings(); $legacy['accounts'][0]['history_params'] = [
    'fetch_my_trades' => ['mode' => 'backward_id', 'cursor_param' => 'marker', 'params' => ['sort' => 'desc']],
    'fetch_open_orders' => ['mode' => 'backward_id', 'cursor_param' => 'marker', 'params' => ['sort' => 'desc']],
    'fetch_order_trades' => ['mode' => 'all'],
];
check(snapshot(new HistoryClient(), $legacy)['complete'], 'the previously shipped Bitso configuration remains compatible');
foreach ([['mode' => 'all'], ['mode' => 'time'], ['mode' => 'backward_id', 'params' => ['marker' => '123']],
    ['mode' => 'backward_id', 'params' => ['book' => 'btc_usd']], ['mode' => 'backward_id', 'params' => ['sort' => 'asc']]] as $config) {
    $bad = settings(); $bad['accounts'][0]['history_params']['fetch_my_trades'] = $config;
    refuses(fn () => snapshot(new HistoryClient(), $bad), 'partial or incorrectly ordered trade-history policy is rejected');
}
$bad = settings(); $bad['accounts'][0]['history_params']['fetch_open_orders'] = ['mode' => 'all', 'params' => ['source' => 'alpha']];
refuses(fn () => snapshot(new HistoryClient(), $bad), 'open-order source filters cannot conceal retail activity');
refuses(fn () => await((new HistoryClient())->fetch_order_trades('order', 'BTC/USD', null, 10)), 'order verification cannot be truncated with a limit');
refuses(fn () => await((new HistoryClient())->fetch_order_trades('order', 'BTC/USD', null, null, ['oid' => 'other'])), 'order verification cannot be redirected through params');

foreach ([['book' => 'btc_usd', 'minor_currency' => 'usdc'], ['margin_order_type' => 'CROSS_MARGIN'], ['book' => 'invalid'], ['side' => 'invalid']] as $override) {
    $bad = new HistoryClient(); $bad->transport->trades = [array_replace(trade(), $override)];
    refuses(fn () => snapshot($bad), 'conflicting currencies, margin and malformed private executions fail closed');
}
$noFee = new HistoryClient(); $noFee->transport->trades = [trade()]; unset($noFee->transport->trades[0]['fees_amount']);
refuses(fn () => snapshot($noFee), 'missing execution fee is not interpreted as zero');
$wrongOrder = new HistoryClient(); $wrongOrder->transport->trades = [trade()]; $wrongOrder->transport->orderTrades = [array_replace(trade(), ['oid' => 'other-order'])];
refuses(fn () => snapshot($wrongOrder), 'order verification cannot accept another order\'s fill');
$inconsistent = new HistoryClient(); $inconsistent->transport->trades = [trade()]; $inconsistent->transport->orderTrades = [array_replace(trade(), ['minor' => '74'])];
refuses(fn () => snapshot($inconsistent), 'account and individual order histories must agree on amounts');
$terminal = new HistoryClient(); $terminal->transport->trades = [trade()];
$terminal->transport->orders['old-order'] = array_replace(openOrder('old-order'), ['status' => 'completed',
    'original_amount' => '0.73', 'unfilled_amount' => '0', 'original_value' => '73']);
check(snapshot($terminal)['orders'][0]['status'] === 'filled', 'available terminal details for a removed market keep their authoritative status');
check($terminal->transport->callsFor('/v3/order_trades/old-order')[0]['params'] === [], 'terminal order trades use the full ID-only read');
$terminal->transport->orderTrades = [array_replace(trade(), ['minor' => '74'])];
refuses(fn () => snapshot($terminal), 'available terminal details cannot hide conflicting account and order fill amounts', 'Conflicting exchange trade identity.');
$terminal->transport->orderTrades = [trade(), array_replace(trade(), ['minor' => '74'])];
refuses(fn () => snapshot($terminal), 'conflicting identities within a full order-trade list are rejected', 'Conflicting exchange trade identity.');
$oversized = new HistoryClient(); $oversized->transport->tradeResponse = ['success' => true, 'payload' => [trade('2'), trade('1')]];
refuses(fn () => snapshot($oversized, settings(1)), 'an oversized trade response cannot bypass the documented request limit');
$malformed = new HistoryClient(); $malformed->transport->tradeResponse = ['success' => true, 'payload' => ['not-a-list' => trade()]];
refuses(fn () => snapshot($malformed), 'invalid account history payload cannot become an empty successful history');
$ascending = new HistoryClient(); $ascending->transport->trades = [trade('1', 1000), trade('2', 2000)];
refuses(fn () => snapshot($ascending), 'out-of-order history cannot prematurely satisfy a time boundary');

$exact = new HistoryClient();
$exact->transport->trades = [array_replace(trade(), ['major' => '-0.123456789012345678901234', 'minor' => '12.3456789012345678901234'])];
$precise = snapshot($exact);
check($precise['trades'][0]['amount'] === '0.123456789012345678901234' && $precise['trades'][0]['cost'] === '12.3456789012345678901234', 'delisted executions preserve all supported decimal places');
$distinct = new HistoryClient();
$distinct->transport->trades = [array_replace(trade(), ['book' => 'usdc_usd', 'major_currency' => 'usdc'])];
check(snapshot($distinct)['trades'][0]['symbol'] === 'USDC/USD', 'USD and USDC remain separate currencies without a hardcoded equivalence');

$detail = SyncDiagnostics::describe(new RuntimeException('Bitso private history currencies conflict with its order book.'), 'bitso');
check($detail['reason'] === 'reconciliation_validation' && str_contains($detail['detail'], 'currencies conflict'), 'new validation failures have explicit safe diagnostic messages');
check(!str_contains(json_encode($batch), 'PRIVATE_') && !str_contains(json_encode($detail), 'PRIVATE_'), 'normalized snapshots and diagnostics contain no raw private responses');

echo json_encode(['suite' => 'm3-bitso-account-history', 'status' => 'passed', 'checks' => $checks,
    'network_requests' => 0, 'exchange_orders_submitted' => 0], JSON_PRETTY_PRINT).PHP_EOL;
