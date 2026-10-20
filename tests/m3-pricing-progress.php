<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Database,Ledger};
use Trademinator\Client\M3\{Accounting,CcxtAccount,Numbers,Prices,Runtime,Settings};
use React\Promise\Deferred;
use function React\Promise\resolve;
use function React\Async\await;

$checks=0;
function check(bool $ok,string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; echo 'PASS '.$message.PHP_EOL; }
class PriceRest
{
    public string $id='fixture';
    public mixed $marketsLoading=null;
    public bool $reloadingMarkets=false;
    public array $has=['fetchLedger'=>true,'fetchOHLCV'=>true];
    public array $timeframes=['1m'=>'1m'];
    public array $markets=['BTC/USD'=>['symbol'=>'BTC/USD','spot'=>false],'BTC/MXN'=>['symbol'=>'BTC/MXN','spot'=>false],'MXN/USD'=>['symbol'=>'MXN/USD','spot'=>false]];
    public array $calls=[];
    public int $loads=0;
    public int $closes=0;
    public ?Deferred $pending=null;
    public bool $missing=false;
    public bool $future=false;
    public bool $badWithdrawal=false;
    public int $time=1700000000100;
    public function load_markets(): object { ++$this->loads; return resolve($this->markets); }
    public function fetch_balance(): object { return resolve(['total'=>['BTC'=>'2'],'free'=>['BTC'=>'2'],'used'=>['BTC'=>'0']]); }
    public function fetch_my_trades(...$args): object { return resolve([]); }
    public function fetch_open_orders(...$args): object { return resolve([]); }
    public function fetch_ledger(...$args): object
    {
        $rows=[];
        foreach (['PRIVATE_DEPOSIT_ONE','PRIVATE_DEPOSIT_TWO'] as $i=>$id) {
            $rows[]=['id'=>$id,'type'=>'transaction','direction'=>'in','status'=>'ok','currency'=>'BTC','amount'=>'1','timestamp'=>$this->time+$i*100,'info'=>['address'=>'PRIVATE_ADDRESS']];
        }
        if ($this->badWithdrawal) { $rows[]=['id'=>'PRIVATE_WITHDRAWAL','type'=>'transaction','direction'=>'out','status'=>'ok','currency'=>'BTC','amount'=>'3','timestamp'=>$this->time+1000]; }
        return resolve($rows);
    }
    public function fetch_ohlcv(string $symbol,string $period,int $since,int $limit): object
    {
        $this->calls[]=['symbol'=>$symbol,'period'=>$period,'since'=>$since];
        if ($this->pending!==null) { return $this->pending->promise(); }
        return resolve($this->missing?[]:[[$since+($this->future?60000:0),'10','10','10','10','1']]);
    }
    public function close(): void { ++$this->closes; }
}
$account=['public_price_exchanges'=>[],'coingecko_ids'=>[]];
$rest=new PriceRest(); $prices=new Prices($rest,$account,1000);
$a=await($prices->estimate('BTC','USD',$rest->time)); $b=await($prices->estimate('BTC','USD',$rest->time+100));
check($a['price']==='10' && $b['price']==='10' && count($rest->calls)===1 && $rest->loads===1, 'nearby historical deposits share one exact candle lookup and market load');
check($a['at_ms']===$rest->time && $b['at_ms']===$rest->time+100, 'cached candle reuse preserves each deposit timestamp in its basis evidence');
$c=await($prices->estimate('USD','BTC',$rest->time));
check($c['price']==='0.1' && count($rest->calls)===1, 'inverse historical valuation reuses the same observed candle');
await($prices->estimate('BTC','USD',$rest->time+60000));
check(count($rest->calls)===2, 'a different candle interval fetches distinct historical evidence');
$prices->close(); check($rest->closes===0, 'closing price work leaves the shared exchange transport open');

$rest=new PriceRest(); $rest->markets=['BTC/USD'=>['symbol'=>'BTC/USD'],'USDT/USD'=>['symbol'=>'USDT/USD']]; $rest->missing=true;
$prices=new Prices($rest,$account,1000);
check(await($prices->estimate('BTC','USD',$rest->time))===null, 'missing historical data remains unknown');
await($prices->estimate('BTC','USD',$rest->time));
check(count($rest->calls)===1, 'one snapshot caches unavailable candles and does not fetch a useless second bridge leg');
$prices->close(); $rest->missing=false; $prices=new Prices($rest,$account,1000);
check(await($prices->estimate('BTC','USD',$rest->time))!==null, 'a later snapshot retries previously unavailable pricing evidence'); $prices->close();
$rest=new PriceRest(); $rest->markets=['BTC/USD'=>['symbol'=>'BTC/USD']]; $rest->future=true; $prices=new Prices($rest,$account,1000);
check(await($prices->estimate('BTC','USD',$rest->time))===null, 'future candles cannot replace missing historical candles'); $prices->close();

class PriceFallback extends PriceRest
{
    public static int $instances=0;
    public static ?self $last=null;
    public function __construct(array $options) { ++self::$instances; self::$last=$this; }
}
class_alias(PriceFallback::class,'ccxt\\async\\m3fixtureprices');
$rest=new PriceRest(); $rest->has['fetchOHLCV']=false;
$prices=new Prices($rest,['public_price_exchanges'=>['m3fixtureprices'],'coingecko_ids'=>[]],1000);
await($prices->estimate('BTC','USD',$rest->time)); await($prices->estimate('BTC','USD',$rest->time+60000));
check(PriceFallback::$instances===1 && PriceFallback::$last->loads===1, 'fallback exchange clients and market catalogs are reused across deposits');
$prices->close(); check(PriceFallback::$last->closes===1 && $rest->closes===0, 'price cleanup closes owned fallback clients exactly once');

$cancelled=false; $rest=new PriceRest(); $rest->markets=['BTC/USD'=>['symbol'=>'BTC/USD']];
$rest->pending=new Deferred(function () use (&$cancelled): void { $cancelled=true; }); $prices=new Prices($rest,$account,100);
$start=microtime(true); $result=await($prices->estimate('BTC','USD',$rest->time));
check($result===null && $cancelled && microtime(true)-$start<2, 'a stalled public request times out and cancels without inventing a basis');
check($prices->progress()['requests_started']===$prices->progress()['requests_finished'], 'timeout completes the visible request counters'); $prices->close();

function runtimeFixture(PriceRest $rest, array &$events): array
{
    $db=new Database(':memory:'); $ledger=new Ledger($db); $id=$ledger->createAccount('fixture','Price progress');
    $ledger->registerMarket($id,'BTC/USD','BTC','USD'); $ledger->registerMarket($id,'BTC/MXN','BTC','MXN');
    $settings=Settings::validate(['timeout_ms'=>60000,'accounts'=>[['account_id'=>$id,'history_since_ms'=>0,'api_key_env'=>'UNUSED','secret_env'=>'UNUSED']]]);
    $adapter=new CcxtAccount('fixture',$settings,$settings['accounts'][0],$rest);
    $runtime=new Runtime($db,$settings,fn()=>$adapter,function($level,$event,$context,$message) use (&$events): void {$events[]=compact('event','context','message');});
    return [$db,$ledger,$runtime,$id];
}
$rest=new PriceRest(); $rest->badWithdrawal=true; $events=[]; [$db,$ledger,$runtime,$id]=runtimeFixture($rest,$events);
$before=$ledger->accountSnapshot($id); $runtime->tick(); $state=$runtime->status()['accounts'][$id];
check($state['state']==='RECOVERING' && $state['last_error']['stage']==='inventory_validation' && $state['last_error']['reason']==='inventory_shortfall', 'runtime reports the inventory failure before starting deposit pricing');
check($rest->calls===[] && $state['last_error']['inventory_shortfall']==='1', 'an unfunded history makes zero public candle requests and reports its shortage');
check($before===$ledger->accountSnapshot($id) && !$state['rest_ok'], 'failed preview changes no live financial rows or readiness');
check($state['inventory_preview']['status']==='failed' && $state['inventory_preview']['stage']==='rebuild'
    && str_ends_with($state['inventory_preview']['path'],'.sqlite') && $state['inventory_preview']['finished_at_ms']!==null,
    'exchange status exposes the latest inventory preview path and failure stage');
check(!str_contains(json_encode([$state,$events]),'PRIVATE_'), 'runtime outflow events and status redact raw operation identifiers and exchange details'); $runtime->close();

$rest=new PriceRest(); $rest->pending=new Deferred(); $events=[]; [$db,$ledger,$runtime,$id]=runtimeFixture($rest,$events); $now=(int)floor(microtime(true)*1000);
$runtime->tick($now); $state=$runtime->status()['accounts'][$id];
check($state['sync']['stage']==='deposit_pricing' && $state['sync']['pricing']['deposits_total']===2 && $state['sync']['pricing']['deposits_finished']===0, 'status shows the total deposits and completed pricing work');
check($state['inventory_preview']['status']==='passed' && !$state['rest_ok'], 'passing inventory preview does not imply successful REST reconciliation');
check($state['sync']['request']['operation']==='fetch_ohlcv' && $state['sync']['request']['symbol']==='BTC/USD' && $state['sync']['request']['provider']==='fixture' && $state['sync']['request']['in_flight'], 'status names the current historical provider symbol and request');
check($state['sync']['requests_started']===2 && $state['sync']['requests_finished']===1 && !$state['rest_ok'], 'pending pricing request is counted separately and does not imply reconciliation');
$runtime->tick($now+30001); $progress=array_values(array_filter($events,static fn($e)=>$e['event']==='exchange_sync_progress'));
check(count($progress)===1 && str_contains($progress[0]['message'],'fetch_ohlcv BTC/USD'), 'normal info events identify long-running pricing requests');
check(!str_contains(json_encode([$state,$events]),'PRIVATE_'), 'pricing progress includes neither raw deposit IDs nor private response data');
$since=intdiv($rest->time,60000)*60000; $rest->pending->resolve([[$since,'10','10','10','10','1']]);
$state=$runtime->status()['accounts'][$id];
check($state['rest_ok'] && !$state['sync']['in_progress'] && count($rest->calls)===1, 'cached pricing completes both deposits and reconciles the snapshot');
check(count((new Accounting($db))->rows('SELECT id FROM operations'))===2 && $ledger->expectedBalances($id)===['BTC'=>'2'], 'pricing creates no extra financial operations');
check(array_column($rest->calls,'symbol')===['BTC/USD'], 'a single account never requests FX for transfers between its own markets'); $runtime->close();

$rest=new PriceRest(); $rest->pending=new Deferred(); $events=[]; [$db,$ledger,$runtime,$id]=runtimeFixture($rest,$events);
$runtime->tick(); $runtime->close();
check(!$runtime->status()['accounts'][$id]['sync']['in_progress'] && $rest->closes===1, 'shutdown clears pending price work and closes exchange transport once');
check((new Accounting($db))->rows('SELECT id FROM operations')===[], 'shutdown during pricing cannot ingest a late snapshot');
echo json_encode(['suite'=>'m3-pricing-progress','status'=>'passed','checks'=>$checks,'network_requests'=>0,'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT).PHP_EOL;
