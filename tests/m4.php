<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
use Trademinator\Client\M4\{Engine,Settings,Store,Replay,Report,Decimal as D};

$checks=0;
function check(bool $ok,string $name): void { global $checks;if(!$ok) { throw new RuntimeException($name); }$checks++;echo 'PASS '.$name.PHP_EOL; }
function eq(string $a,string $b,string $name): void { check(D::cmp($a,$b)===0,$name.' ('.$a.')'); }
function config(array $extra=[]): array {
    return Settings::validate(array_replace_recursive(['account'=>'test','source_exchange'=>'binance','execution_exchange'=>'bitso','reporting_currency'=>'USD','balances'=>['USD'=>'1000','BTC'=>'0'],
        'opening_lots'=>[],'submission_delay_ms'=>100,'cancellation_delay_ms'=>100,'stale_ms'=>100000,
        'markets'=>['BTC/USD'=>['tick_size'=>'1','amount_step'=>'0.01','minimum_amount'=>'0.01','minimum_cost'=>'1','maker_fee'=>'0.01','taker_fee'=>'0.02','risk_factor'=>'0.25','volume_participation'=>'0.1','period_ms'=>1000,'evaluation_ms'=>1000]]],$extra));
}
function event(Engine $e,string $type,int $time,array $data=[]): void { $e->apply(['id'=>'e'.($e->state['sequence']+1),'type'=>$type,'time_ms'=>$time,'symbol'=>'BTC/USD',...$data]); }
function book(Engine $e,int $time,string $ask='100',string $depth='1',string $bid='98'): void { event($e,'book',$time,['bids'=>[[$bid,'0']],'asks'=>[[$ask,$depth]]]); }
function candles(Engine $e): void { foreach([110,105,100] as $i=>$p) { event($e,'candle',($i+1)*1000,['ohlcv'=>[$i*1000,(string)$p,(string)$p,(string)$p,(string)$p,'10']]); } }
$e=new Engine(config());candles($e);book($e,3000);event($e,'evaluate',3000,['action'=>'buy','q3bar'=>'2','expires_at_ms'=>4000]);
check(count($e->state['orders'])===2,'Two Order Rule creates Main and cheaper Backup');
$orders=array_values($e->state['orders']);eq($orders[0]['quantity'],'2','Main sizing');eq($orders[1]['price'],'95','Backup price speed projection');
check(count($e->state['fills'])===0,'Submission delay prevents premature fills');
check(D::cmp($e->free('USD'),'0')>=0,'Both reservations funded');
event($e,'clock',3100);check(count($e->state['fills'])===1,'Accepted Main consumes visible depth');eq($e->state['orders']['order-1']['remaining'],'1','Main partial remainder');
book($e,3200);check(count($e->state['fills'])===1,'Identical book does not replenish consumed liquidity');
event($e,'trade',3300,['price'=>'95','quantity'=>'0.5','side'=>'sell']);eq($e->state['orders']['order-1']['remaining'],'0.5','Trade partially fills Main first');eq($e->state['orders']['order-2']['filled'],'0','Backup waits for fully filled Main');
event($e,'trade',3400,['price'=>'95','quantity'=>'1','side'=>'sell']);check($e->state['orders']['order-1']['status']==='filled','Main completes first');eq($e->state['orders']['order-2']['filled'],'0.5','Unused event volume reaches Backup after Main');
check(count($e->state['fills'])===4,'Shared trade liquidity is not reused');
event($e,'evaluate',3500,['action'=>'hold','expires_at_ms'=>4500]);check($e->state['orders']['order-2']['status']==='cancelling','Cancellation is pending');
event($e,'trade',3550,['price'=>'95','quantity'=>'0.1','side'=>'sell']);eq($e->state['orders']['order-2']['filled'],'0.6','Fills allowed while cancel pending');
event($e,'clock',3600);check($e->state['orders']['order-2']['status']==='cancelled','Cancellation acknowledges and releases reserve');
$balance=$e->state['balances']['USD'];eq($e->free('USD'),$balance,'Cancelled remainder reservation released');
$profitBuyReport=Report::build(['config'=>$e->config,'state'=>$e->state]);eq($profitBuyReport['realized_net_profit'],'0','BUY creates no earnings');
book($e,3700,'121','10','120');event($e,'evaluate',3700,['action'=>'sell','expires_at_ms'=>4700]);event($e,'clock',3800);
check(count(array_filter($e->state['fills'],fn($f)=>$f['side']==='sell'))===0,'Zero bid depth cannot fill sells');
book($e,3900,'121','10','120');event($e,'trade',3950,['price'=>'120','quantity'=>'10','side'=>'buy']);
$report=Report::build(['config'=>$e->config,'state'=>$e->state]);eq($report['realized_net_profit'],'48.31','SELL realizes independently calculated fee-adjusted lot profit');
$allocated='0';foreach($e->state['fills'] as $f) { foreach($f['allocations'] as $a) { $allocated=D::add($allocated,$a['quantity']); } }
eq($allocated,'2.6','SELL allocations consume exact acquired inventory');
check(!array_key_exists('unrealized_profit',$report),'Report excludes unrealized profit');
$e2=new Engine(config(['balances'=>['USD'=>'0','BTC'=>'2'],'opening_lots'=>[['symbol'=>'BTC/USD','quantity'=>'2','unit_cost'=>'100']]]));book($e2,1,'100','10','98');event($e2,'evaluate',1,['action'=>'sell','expires_at_ms'=>1001]);check(count($e2->state['orders'])===0,'Unprofitable SELL is rejected');
$e3=new Engine(config(['balances'=>['USD'=>'1'],'submission_delay_ms'=>0]));candles($e3);book($e3,3000);event($e3,'evaluate',3000,['action'=>'buy','q3bar'=>'2','expires_at_ms'=>4000]);check(count($e3->state['orders'])===0,'No Backup when Main minimum fails');
$e4=new Engine(config(['markets'=>['BTC/USD'=>['fee_asset'=>'base']]]));candles($e4);book($e4,3000,'100','10');event($e4,'evaluate',3000,['action'=>'buy','q3bar'=>'1','expires_at_ms'=>4000]);event($e4,'clock',3100);eq($e4->state['balances']['BTC'],'0.98','Base BUY fee reduces acquired quantity');eq($e4->state['balances']['USD'],'900','Base BUY fee not charged again in quote');
event($e4,'gap',3200,['reason'=>'missing_stream']);$fills=count($e4->state['fills']);event($e4,'trade',3300,['price'=>'90','quantity'=>'10','side'=>'sell']);check(count($e4->state['fills'])===$fills,'Gap pauses fills');
event($e4,'resume',3400);event($e4,'trade',3400,['price'=>'90','quantity'=>'10','side'=>'sell']);check(count($e4->state['fills'])===$fills,'Resume requires a fresh book');
$dir=sys_get_temp_dir().'/m4-test-'.bin2hex(random_bytes(6));$store=new Store($dir);$store->create('original',config());
$events=[['id'=>'a','type'=>'book','time_ms'=>1000,'symbol'=>'BTC/USD','bids'=>[['98','0']],'asks'=>[['100','2']]],['id'=>'b','type'=>'evaluate','time_ms'=>1000,'symbol'=>'BTC/USD','action'=>'buy','q3bar'=>'1','expires_at_ms'=>2000],['id'=>'c','type'=>'clock','time_ms'=>1100]];
foreach($events as $x) { $store->apply('original',$x); }$before=$store->run('original')['state'];
check(($store->apply('original',$events[0])['duplicate']??false),'Exact event retry is idempotent');
try { $store->apply('original',[...$events[0],'time_ms'=>2000]);check(false,'Conflict expected'); }catch(RuntimeException) { check($store->run('original')['state']===$before,'Conflicting event ID rolls back'); }
try { $store->apply('original',['id'=>'bad','type'=>'trade','time_ms'=>1200,'symbol'=>'BTC/USD','price'=>'-1','quantity'=>'1','side'=>'sell']);check(false,'Invalid expected'); }catch(InvalidArgumentException) { check($store->run('original')['state']===$before,'Malformed event changes neither ledger nor clock'); }
$store=new Store($dir);check($store->run('original')['state']===$before,'Restart resumes exact persisted state');
check($store->replay('original','replayed')['matched'],'Captured event replay exactly matches state and accounting');
$store->create('reset',$store->run('original')['config']);check($store->run('reset')['state']['sequence']===0,'Reset starts separate run');
$sweep=Replay::sweep($store,'original','comparison',['fast'=>['submission_delay_ms'=>0],'slow'=>['submission_delay_ms'=>500]]);check($sweep['fast']['fills']===1 && $sweep['slow']['fills']===0,'Named comparison runs isolate different delay parameters');
$dist=Report::distribution([1,2,3]);check($dist['count']===3 && $dist['mean']===2 && abs($dist['stddev']-1)<1e-12,'Distribution has independently known mean and sample deviation');
check(count($dist['normal_curve'])===61 && array_sum(array_column($dist['histogram'],'count'))===3,'Histogram conserves samples and normal curve is present');
check(Report::distribution([1,1])['normal_curve']===[],'Constant samples do not divide by zero');
$store->create('ohlcv',config());$rows=[[0,'100','105','95','100','10'],[1000,'100','105','95','100','10'],[3000,'100','105','95','100','10']];
Replay::candles($store,'ohlcv','BTC/USD',$rows,fn()=>['action'=>'hold']);$r=Report::build($store->run('ohlcv'));check(!$r['verified'] && count($r['gaps'])===1,'OHLC replay reports missing candles');
check(isset($r['assumptions']['ohlcv']),'OHLC replay retains execution assumptions');

$fxEngine=new Engine(config(['reporting_currency'=>'CAD','balances'=>['USD'=>'0','BTC'=>'1'],'opening_lots'=>[['symbol'=>'BTC/USD','quantity'=>'1','unit_cost'=>'80']],'submission_delay_ms'=>0]));
event($fxEngine,'fx',1,['currency'=>'USD','rate'=>'1.25','observed_at_ms'=>1]);
$fxEngine->apply(['id'=>'fx-book','type'=>'book','time_ms'=>1,'symbol'=>'BTC/USD','bids'=>[['100','10']],'asks'=>[['101','10']]]);
event($fxEngine,'evaluate',1,['action'=>'sell','expires_at_ms'=>1001]);$fxReport=Report::build(['state'=>$fxEngine->state,'config'=>$fxEngine->config]);
eq($fxReport['realized_net_profit'],'22.5','Reporting currency converts realized net profit at fill-time rate');
eq($fxEngine->state['fills'][0]['realized_profit'],'18','Original realized profit preserved');
check($fxEngine->state['fills'][0]['conversion']['observed_at_ms']===1,'Conversion timestamp preserved');
$missingFx=new Engine(config(['reporting_currency'=>'CAD','balances'=>['USD'=>'0','BTC'=>'1'],'opening_lots'=>[['symbol'=>'BTC/USD','quantity'=>'1','unit_cost'=>'80']],'submission_delay_ms'=>0]));
$missingFx->apply(['id'=>'missing-book','type'=>'book','time_ms'=>1,'symbol'=>'BTC/USD','bids'=>[['100','10']],'asks'=>[['101','10']]]);event($missingFx,'evaluate',1,['action'=>'sell','expires_at_ms'=>1001]);
$missingReport=Report::build(['state'=>$missingFx->state,'config'=>$missingFx->config]);check($missingReport['realized_net_profit']===null && $missingReport['valuation_coverage']['converted_sales']===0,'Missing FX does not invent consolidated profit');
$store->create('gap-run',config(['submission_delay_ms'=>0]));
$store->apply('gap-run',['id'=>'start','type'=>'book','time_ms'=>1000,'symbol'=>'BTC/USD','bids'=>[['98','0']],'asks'=>[['100','0']]]);
$store->apply('gap-run',['id'=>'decision','type'=>'evaluate','time_ms'=>1000,'symbol'=>'BTC/USD','action'=>'buy','q3bar'=>'1','expires_at_ms'=>3000]);
$store->apply('gap-run',['id'=>'gap','type'=>'gap','time_ms'=>2000,'from_ms'=>1100,'symbol'=>'BTC/USD']);
$capture=['complete'=>true,'symbol'=>'BTC/USD','from_ms'=>1100,'to_ms'=>2000,'provenance'=>'sequence-verified fixture capture','first_sequence'=>20,'last_sequence'=>20,
    'events'=>[['id'=>'recovered-trade','source_sequence'=>20,'type'=>'trade','time_ms'=>1200,'symbol'=>'BTC/USD','price'=>'100','quantity'=>'1','side'=>'sell']]];
$result=\Trademinator\Client\M4\Recovery::replay($store,'gap-run','recovered',$capture);
check($result['report']['verified'] && $result['report']['fills']===1,'Complete gap recovery replays eligible fills into a new run');
check(count($store->run('gap-run')['state']['gaps'])===1,'Recovery preserves original run evidence');
try { \Trademinator\Client\M4\Recovery::replay($store,'gap-run','invalid-recovery',[...$capture,'last_sequence'=>21]);check(false,'Missing sequence expected'); }catch(RuntimeException) { check(true,'Gap recovery rejects missing source sequence'); }

require_once dirname(__DIR__).'/vendor/autoload.php';
$liveConfig=config(['source_exchange'=>'bitso','submission_delay_ms'=>0,'markets'=>['BTC/USD'=>['period_ms'=>60000,'evaluation_ms'=>20000,
    'intelligence'=>['subscription'=>'00000000-0000-4000-8000-000000000001','period'=>'1m','model_id'=>'00000000-0000-4000-8000-000000000002']]]]);
$store->create('live-fixture',$liveConfig);
putenv('M4_TEST_KEY=fixture-key');
$clientConfig=new \Trademinator\Client\M1\Config(['data_dir'=>$dir,'server_url'=>'https://example.invalid','server_api_key_env'=>'M4_TEST_KEY']);
$server=new \Trademinator\Client\M4\Server($clientConfig,static fn()=>['signal'=>['action'=>'buy'],'provenance'=>['fixture'=>true]]);
$feed=new class {
    public function market(string $s):array {return ['spot'=>true];}
    public function book(string $s):array {return ['timestamp'=>(int)floor(microtime(true)*1000),'transport'=>'rest','bids'=>[['98','10']],'asks'=>[['100','10']]];}
    public function trades(string $s,?int $since):array {return [];}
    public function candles(string $s,string $p,int $limit):array {$t=intdiv((int)floor(microtime(true)*1000),60000)*60000;return [[$t-180000,'100','100','100','100','10'],[$t-120000,'100','100','100','100','10'],[$t-60000,'100','100','100','100','10']];}
    public function close():void {}
};
$liveReport=\Trademinator\Client\M4\Live::run($store,'live-fixture',$server,1,new \Trademinator\Client\M1\ConsoleEvents('json','never',false),fn()=>$feed);
check($liveReport['fills']===1 && $liveReport['gaps']===[],'Live transport and inference pipeline drives persisted paper fills');
check(!$liveReport['live_24h_observed'],'Short live fixture cannot satisfy 24-hour acceptance');
check($store->replay('live-fixture','live-replay')['matched'],'Captured live pipeline replays identically without network');
$configFile=$dir.'/config.json';file_put_contents($configFile,json_encode(config()));
$process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/client.php','paper-create','--data-dir='.$dir,'--paper-config='.$configFile,'--run=cli'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0 && json_decode($out,true)['status']==='created','CLI paper command routes independently of daemon');

$timed=new Engine(config(['submission_delay_ms'=>0]));book($timed,1000,'100','0');event($timed,'evaluate',1000,['action'=>'buy','q3bar'=>'1','expires_at_ms'=>2000]);
event($timed,'clock',2000);check($timed->state['orders']['order-1']['status']==='cancelling','Evaluation deadline requests cancellation even when next signal is unavailable');
event($timed,'clock',2100);check($timed->state['orders']['order-1']['status']==='cancelled','Missing next signal cannot leave residual orders open indefinitely');
echo json_encode(['milestone'=>'M4','checks'=>$checks,'status'=>'passed','exchange_orders_submitted'=>0],JSON_PRETTY_PRINT).PHP_EOL;
