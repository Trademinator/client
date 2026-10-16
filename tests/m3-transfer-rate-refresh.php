<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Database,Ledger};
use Trademinator\Client\M3\{Accounting,CcxtAccount,Numbers,Reconciler,Runtime,Settings};
use function React\Promise\resolve;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS '.$message.PHP_EOL;
}
final class RateRest
{
    public string $id = 'fixture';
    public array $has = ['fetchLedger'=>true,'fetchOHLCV'=>true];
    public array $timeframes = ['1m'=>'1m'];
    public array $markets = [];
    public array $calls = [];
    public array $rates = ['BTC/USD'=>'100','CAD/USD'=>'0.75','EUR/USD'=>'1.2'];
    public function __construct()
    {
        foreach (['BTC/USD','CAD/USD','EUR/USD','GBP/USD'] as $symbol) {
            $this->markets[$symbol] = ['symbol'=>$symbol,'spot'=>false];
        }
    }
    public function load_markets(): object { return resolve($this->markets); }
    public function fetch_balance(): object { return resolve(['total'=>['BTC'=>'1'],'free'=>['BTC'=>'1'],'used'=>['BTC'=>'0']]); }
    public function fetch_my_trades(...$args): object { return resolve([]); }
    public function fetch_open_orders(...$args): object { return resolve([]); }
    public function fetch_ledger(...$args): object { return resolve([]); }
    public function fetch_ohlcv(string $symbol, string $period, int $since, int $limit): object
    {
        $this->calls[] = ['symbol'=>$symbol,'since'=>$since];
        $rate = $this->rates[$symbol] ?? null;
        return resolve($rate === null ? [] : [[$since,$rate,$rate,$rate,$rate,'1']]);
    }
    public function close(): void {}
}

$db = new Database(':memory:');
$ledger = new Ledger($db);
$math = new Accounting($db);
$receiver = $ledger->createAccount('fixture','Receiver');
$receiverMarket = $ledger->registerMarket($receiver,'BTC/USD','BTC','USD');
$account = ['account_id'=>$receiver,'history_since_ms'=>0,'api_key_env'=>'RATE_TEST_KEY','secret_env'=>'RATE_TEST_SECRET'];
$settings = Settings::validate(['accounts'=>[$account]]);
$account = $settings['accounts'][0];
$time = 1700000000000;
$deposit = ['id'=>'deposit:prior','kind'=>'deposit','currency'=>'BTC','amount'=>'1','fees'=>[],
    'timestamp'=>$time,'txid'=>null,'address'=>null,'tag'=>null,'network'=>'BTC'];
$basis = ['price'=>'7','quote'=>'USD','source'=>'operator_fixture','estimated'=>true];
$cad = ['price'=>'0.74','quote'=>'USD','source'=>'original_audited_rate','at_ms'=>$time,'estimated'=>true];
$initial = ['at_ms'=>$time+1,'complete'=>true,'orders'=>[],'trades'=>[],'operations'=>[$deposit],
    'balances'=>['BTC'=>['total'=>'1','free'=>'1','used'=>'0']], 'prices'=>[$deposit['id']=>$basis],
    'transfer_rates'=>[$deposit['id']=>['CAD'=>$cad]]];
check((new Reconciler($db))->apply($receiver,$initial,$account)['reconciled'], 'existing deposit has reconciled inventory and an audited price basis');
$row = $math->rows('SELECT * FROM operations WHERE account_id=?',[$receiver])[0];
$original = $row;
$sender = $ledger->createAccount('fixture','Sender');
$ledger->registerMarket($sender,'BTC/CAD','BTC','CAD');
$rest = new RateRest();
$adapter = new CcxtAccount('fixture',$settings,$account,$rest);
$runtime = new Runtime($db,$settings,fn()=>$adapter);
$now = (int)floor(microtime(true)*1000);
try {
    $runtime->tick($now);
    check($runtime->status()['accounts'][$receiver]['rest_ok'] && $rest->calls === [], 'complete stored rates need no historical calls when the deposit is absent from REST');

    // Sender history introduces a new cost denomination after receiver ingestion.
    $ledger->registerMarket($sender,'BTC/EUR','BTC','EUR');
    $runtime->command('exchange-refresh',['account'=>$receiver]);
    $runtime->tick($now+1000);
    $row = $math->rows('SELECT * FROM operations WHERE id=?',[$original['id']])[0];
    $meta = Numbers::decode($row['metadata_json'])['m3'];
    check($runtime->status()['accounts'][$receiver]['rest_ok']
        && ($meta['fx_rates']['EUR']['price'] ?? null) === '1.2', 'a later sender market refreshes missing FX for an older live deposit');
    check($rest->calls === [['symbol'=>'EUR/USD','since'=>intdiv($time,60000)*60000]], 'rate refresh requests only the missing denomination at the original deposit time');
    check($meta['fx_rates']['CAD'] === $cad, 'existing historical rates are retained exactly');
    check($row['price_basis'] === '7' && $meta['basis'] === $basis, 'rate enrichment never reprices the original deposit acquisition');
    $sameMoney = true;
    foreach (['id','account_id','market_id','exchange_operation_id','kind','currency','amount','fee_amount','fee_currency','occurred_at'] as $field) {
        $sameMoney = $sameMoney && $row[$field] === $original[$field];
    }
    check($sameMoney && $meta['evidence'] === $deposit && count($math->rows('SELECT id FROM operations WHERE account_id=?',[$receiver])) === 1,
        'FX enrichment preserves financial evidence and does not create another operation');
    $revision = $db->revision($receiver);
    $runtime->command('exchange-refresh',['account'=>$receiver]);
    $runtime->tick($now+2000);
    check(count($rest->calls) === 1 && $db->revision($receiver) === $revision,
        'duplicate synchronization neither refetches known rates nor changes financial revision');

    $ledger->registerMarket($sender,'BTC/GBP','BTC','GBP');
    $runtime->command('exchange-refresh',['account'=>$receiver]);
    $runtime->tick($now+3000);
    $meta = Numbers::decode($math->rows('SELECT metadata_json FROM operations WHERE id=?',[$original['id']])[0]['metadata_json'])['m3'];
    check(!isset($meta['fx_rates']['GBP']) && $db->revision($receiver) === $revision,
        'unavailable historical FX stays missing without changing accounting evidence');
    $rest->rates['GBP/USD'] = '1.3';
    $runtime->command('exchange-refresh',['account'=>$receiver]);
    $runtime->tick($now+4000);
    $meta = Numbers::decode($math->rows('SELECT metadata_json FROM operations WHERE id=?',[$original['id']])[0]['metadata_json'])['m3'];
    check(($meta['fx_rates']['GBP']['price'] ?? null) === '1.3' && $meta['fx_rates']['CAD'] === $cad,
        'a later successful historical lookup fills only the previously missing rate');
    check($ledger->expectedBalances($receiver) === ['BTC'=>'1'] && $db->integrityCheck()['ok'],
        'rate recovery preserves the wallet and financial foreign keys');
} finally {
    $runtime->close();
}
echo json_encode(['suite'=>'m3-transfer-rate-refresh','status'=>'passed','checks'=>$checks,'network_requests'=>0],JSON_PRETTY_PRINT).PHP_EOL;
