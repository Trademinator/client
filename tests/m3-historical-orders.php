<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Backup, Database, Ledger};
use Trademinator\Client\M3\{Accounting, CcxtAccount, HardPoints, Numbers, Reconciler, Settings};

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
function refuses(callable $fn, string $message): void
{
    try { $fn(); } catch (Throwable) { check(true, $message); return; }
    check(false, $message);
}

final class HistoricalRest
{
    public array $has = ['fetchLedger' => true, 'fetchOrderTrades' => true];
    public array $accountTrades;
    public array $orderTrades;
    public ?Throwable $lookupError = null;
    public ?Throwable $tradesError = null;
    public ?array $authoritative = null;
    public bool $reappears = false;
    public int $openCalls = 0;
    public array $tradeArguments = [];
    public function __construct()
    {
        $this->orderTrades = [self::fill('t1', '1', '10', 1000), self::fill('t2', '1', '20', 2000)];
        $this->accountTrades = [$this->orderTrades[1]]; // A narrower user-history window.
    }
    public static function fill(string $id, string $amount, string $price, int $timestamp): array
    {
        return ['id' => $id, 'order' => 'old-buy', 'symbol' => 'BTC/USD', 'side' => 'buy',
            'timestamp' => $timestamp, 'amount' => $amount, 'price' => $price,
            'cost' => Numbers::decimal(bcmul($amount, $price, 24)), 'fee' => ['currency' => 'USD', 'cost' => '0.1']];
    }
    public function load_markets(): object { return resolve(['BTC/USD' => ['symbol' => 'BTC/USD', 'spot' => true]]); }
    public function fetch_balance(): object
    {
        return resolve(['total' => ['USD' => '969.8', 'BTC' => '2'], 'free' => ['USD' => '969.8', 'BTC' => '2'], 'used' => ['USD' => '0', 'BTC' => '0']]);
    }
    public function fetch_my_trades($symbol, $since, $limit, $params): object { return resolve($this->accountTrades); }
    public function fetch_open_orders($symbol, $since, $limit, $params): object
    {
        ++$this->openCalls;
        return resolve($this->reappears && $this->openCalls > 1 ? [['id' => 'old-buy']] : []);
    }
    public function fetch_order($id, $symbol): object
    {
        return $this->authoritative !== null ? resolve($this->authoritative) : reject($this->lookupError ?? new ccxt\ExchangeError('bitso {"success":false,"error":{"code":"0312","message":"Incorrect OID"}}'));
    }
    public function fetch_order_trades($id, $symbol, $since, $limit, $params): object
    {
        $this->tradeArguments[] = [$id, $symbol, $since, $limit, $params];
        return $this->tradesError === null ? resolve($this->orderTrades) : reject($this->tradesError);
    }
    public function fetch_ledger($currency, $since, $limit, $params): object { return resolve([]); }
    public function close(): void {}
}

$settings = Settings::validate(['accounts' => [['account_id' => 'test', 'api_key_env' => 'UNUSED', 'secret_env' => 'UNUSED', 'history_since_ms' => 0,
    'history_params' => ['fetch_my_trades' => ['mode' => 'backward_id']]]]]);
$account = $settings['accounts'][0];
$context = ['symbols' => ['BTC/USD'], 'since_ms' => 0, 'known_orders' => []];
$snapshot = static fn(HistoricalRest $rest, string $exchange = 'bitso', ?array $ctx = null) => await((new CcxtAccount($exchange, $settings, $account, $rest))->snapshot($ctx ?? $context));
$rest = new HistoricalRest();
$batch = $snapshot($rest);
check($batch['history_only_orders'] === 1 && count($batch['trades']) === 2, 'expired order lookup retains full verified executions');
check($batch['orders'][0]['status'] === 'history_only' && $batch['orders'][0]['type'] === 'UNKNOWN' && $batch['orders'][0]['amount'] === null, 'historical status, order type and original quantity are never invented');
check($rest->tradeArguments[0] === ['old-buy', 'BTC/USD', null, null, []], 'order-trades request cannot be truncated or redirected by pagination settings');
check($rest->openCalls === 2, 'historical orders require a fresh complete open-order confirmation');

$root = sys_get_temp_dir().'/tm-history-'.bin2hex(random_bytes(6));
Backup::ensureDirectory($root);
try {
    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    $id = $ledger->createAccount('bitso', 'history', null, ['USD' => '1000']);
    $market = $ledger->registerMarket($id, 'BTC/USD', 'BTC', 'USD');
    $account['account_id'] = $id;
    $reconciler = new Reconciler($db);
    check($reconciler->apply($id, $batch, $account)['reconciled'], 'verified historical fills reconcile actual wallets');
    $row = $db->pdo()->query('SELECT * FROM orders')->fetch();
    check($row['quantity'] === null && $row['remaining_quantity'] === null && $row['status'] === 'history_only', 'database retains unknown original and unfilled quantities as NULL');
    $meta = Numbers::decode($row['metadata_json']);
    check(count($meta['m3']['fills']) === 2 && $meta['m3']['history_evidence']['terminal_status'] === null, 'fill IDs, prices, fees and unknown lifecycle provenance persist');
    $lots = $ledger->marketLots($market);
    check(count($lots) === 2 && $lots[0]['unit_cost'] === '10.1' && $lots[1]['unit_cost'] === '20.1', 'individual acquisition costs include their actual quote fees');
    $revision = $db->revision($id);
    $reconciler->apply($id, $batch, $account);
    check($db->revision($id) === $revision, 'historical replay is financially idempotent');
    refuses(fn() => $db->pdo()->exec("UPDATE orders SET quantity='2'"), 'schema forbids pretending the executed total is the original order quantity');
    $hard = new HardPoints($db, $root);
    $candidate = $hard->capture($id);
    check($candidate['eligible'], 'verified historical inventory can form a candidate without a fictitious open order');
    $fresh = $batch; $fresh['at_ms'] = $candidate['cutoff_ms'] + 1;
    check($hard->verifyPending($id, $fresh)['verification'] === 'verified', 'H1 independently verifies historical financial evidence');

    // An unresolved Client intent is independent of imported manual execution evidence.
    $reconciler->uncertain(['account_id' => $id, 'market_id' => $market, 'client_order_id' => 'pending-client', 'side' => 'BUY', 'quantity' => '1', 'price' => '9']);
    $reconciler->apply($id, $batch, $account);
    check($ledger->openOrdersCount($id, $market) === 1, 'historical import does not clear an uncertain Client submission');

    // Later actual order details replace unknown terms, including a cancelled remainder.
    $known = $batch;
    $known['orders'][0] = array_replace($known['orders'][0], ['status' => 'cancelled', 'type' => 'LIMIT', 'amount' => '5', 'price' => '20']);
    unset($known['orders'][0]['history_evidence']);
    $reconciler->apply($id, $known, $account);
    $row = $db->pdo()->query("SELECT * FROM orders WHERE exchange_order_id='old-buy'")->fetch();
    check($row['status'] === 'cancelled' && $row['quantity'] === '5' && $row['remaining_quantity'] === '3', 'later authoritative cancellation restores real original quantity and remainder');
    $reconciler->apply($id, $batch, $account);
    $row = $db->pdo()->query("SELECT * FROM orders WHERE exchange_order_id='old-buy'")->fetch();
    check($row['status'] === 'cancelled' && $row['quantity'] === '5', 'later history-only lookup cannot erase known order details');

    foreach (['permission', 'other_exchange', 'known_open', 'empty', 'wrong_order', 'wrong_symbol', 'mixed_side', 'missing_fill', 'recent', 'reappears', 'trades_failure'] as $scenario) {
        $fixture = new HistoricalRest(); $exchange = 'bitso'; $ctx = $context;
        switch ($scenario) {
            case 'permission': $fixture->lookupError = new ccxt\PermissionDenied('do not fall back'); break;
            case 'other_exchange': $exchange = 'fixture'; break;
            case 'known_open': $ctx['known_orders'] = ['old-buy' => 'BTC/USD']; break;
            case 'empty': $fixture->orderTrades = []; break;
            case 'wrong_order': $fixture->orderTrades[0]['order'] = 'another-owner-order'; break;
            case 'wrong_symbol': $fixture->orderTrades[0]['symbol'] = 'ATOM/USD'; break;
            case 'mixed_side': $fixture->orderTrades[0]['side'] = 'sell'; break;
            case 'missing_fill': $fixture->orderTrades = [$fixture->orderTrades[0]]; break;
            case 'recent': $fixture->orderTrades[0]['timestamp'] = (int)floor(microtime(true) * 1000); break;
            case 'reappears': $fixture->reappears = true; break;
            case 'trades_failure': $fixture->tradesError = new ccxt\RequestTimeout('private failure'); break;
        }
        refuses(fn() => $snapshot($fixture, $exchange, $ctx), 'historical fallback fails closed: '.$scenario);
    }
    $emptyLookup = new HistoricalRest(); $emptyLookup->lookupError = new ccxt\OrderNotFound('not found');
    check($snapshot($emptyLookup)['history_only_orders'] === 1, 'empty Bitso order response uses the same verified fallback');
    $many = new HistoricalRest();
    $many->orderTrades = array_map(static fn($i) => HistoricalRest::fill('many-'.$i, '1', '10', $i * 1000), range(1, 120));
    $many->accountTrades = [$many->orderTrades[119]];
    check(count($snapshot($many)['trades']) === 120, 'more than 100 executions from one order are retained');
    check($db->integrityCheck()['ok'], 'historical updates preserve database and foreign-key integrity');
} finally {
    unset($hard, $reconciler, $ledger, $db);
    Backup::removeTree($root);
}

echo json_encode(['suite' => 'm3-historical-orders', 'status' => 'passed', 'checks' => $checks, 'network_requests' => 0], JSON_PRETTY_PRINT).PHP_EOL;
