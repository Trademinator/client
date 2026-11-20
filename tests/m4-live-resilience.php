<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M4\{Live,RequestDeferred,Server,ServerRequestFailed,Settings,Store};

$checks=0;
function check(bool $ok,string $name):void { global $checks;if(!$ok) { throw new RuntimeException($name); }$checks++;echo 'PASS '.$name.PHP_EOL; }
$dir=sys_get_temp_dir().'/m4-live-resilience-'.bin2hex(random_bytes(6));
putenv('M4_RESILIENCE_KEY=fixture-key');
$config=new Config(['data_dir'=>$dir,'server_url'=>'https://example.invalid','server_api_key_env'=>'M4_RESILIENCE_KEY']);
$store=new Store($dir.'/paper');
$settings=Settings::validate(['account'=>'fixture','source_exchange'=>'bitso','execution_exchange'=>'bitso','reporting_currency'=>'USD',
    'balances'=>['USD'=>'10000','XRP'=>'0'],'opening_lots'=>[],'submission_delay_ms'=>0,'cancellation_delay_ms'=>0,'stale_ms'=>30000,
    'markets'=>['XRP/USD'=>['tick_size'=>'0.00001','amount_step'=>'0.000001','minimum_amount'=>'0.03','minimum_cost'=>'0.5',
        'maker_fee'=>'0.003','taker_fee'=>'0.0036','risk_factor'=>'0.3','period_ms'=>900000,'evaluation_ms'=>300000,
        'intelligence'=>['subscription'=>'00000000-0000-4000-8000-000000000001','period'=>'15m',
            'model_id'=>'00000000-0000-4000-8000-000000000002','warmup'=>500]]]]);
function feed(bool $invalid=false,int $candles=3):object {
    return new class($invalid,$candles) {
        public int $books=0;
        public function __construct(private bool $invalid,private int $count) {}
        public function market(string $symbol):array { return ['spot'=>true]; }
        public function book(string $symbol):array {
            $this->books++;
            return ['timestamp'=>(int)floor(microtime(true)*1000),'transport'=>'rest',
                'bids'=>$this->invalid?[]:[['1','100']],'asks'=>[['1.01','100']]];
        }
        public function trades(string $symbol,?int $since):array { return []; }
        public function candles(string $symbol,string $period,int $limit):array {
            $now=intdiv((int)floor(microtime(true)*1000),900000)*900000;$rows=[];
            for($i=$this->count;$i>0;$i--) { $rows[]=[$now-$i*900000,'1','1','1','1','10']; }
            return $rows;
        }
        public function close():void {}
    };
}
function tradeFeed(int $count):object {
    return new class(feed(),$count) {
        public array $requests=[];
        private ?array $rows=null;
        public function __construct(private object $base,private int $count) {}
        public function market(string $symbol):array { return $this->base->market($symbol); }
        public function book(string $symbol):array { return $this->base->book($symbol); }
        public function candles(string $symbol,string $period,int $limit):array { return $this->base->candles($symbol,$period,$limit); }
        public function close():void { $this->base->close(); }
        public function trades(string $symbol,?int $since):array {
            $this->requests[]=$since;
            if($this->rows===null) {
                $this->rows=[];
                for($i=1;$i<=$this->count;$i++) {
                    $this->rows[]=['id'=>'source-'.$i,'timestamp'=>$since,'price'=>1.005,'amount'=>0.01,'side'=>'sell'];
                    if($i===20){$this->rows[]=$this->rows[0];}
                }
                $this->rows[]=$this->rows[0];
            }
            return $this->rows;
        }
    };
}
function eventTypes(Store $store,string $run):array { return array_count_values(array_column(iterator_to_array($store->events($run)),'type')); }

$store->create('unavailable',$settings);$calls=0;$feed=feed();$logs=[];
$server=new Server($config,static function()use(&$calls):array { $calls++;throw new ServerRequestFailed(500); });
$report=Live::run($store,'unavailable',$server,2,static function(array $e)use(&$logs):void { $logs[]=$e; },static fn()=>$feed);
check($calls===1,'An unavailable inference is attempted once during its backoff');
check(($report['live_observations']['XRP/USD']['books']??0)>=2,'Book capture continues during inference backoff');
check(count($report['gaps'])===1 && (eventTypes($store,'unavailable')['resume']??0)===0,'Persistent inference failure produces one gap without false resumes');
check(($store->run('unavailable')['state']['paused']['XRP/USD']??false) && $report['fills']===0,'Unresolved inference stays paused and cannot fill');
check(count(array_filter($logs,static fn($e)=>$e['event']==='paper_market_event'))===0,'Paused book capture does not publish a running market status');

$recoveredCalls=0;$server=new Server($config,static function()use(&$recoveredCalls):array { $recoveredCalls++;return ['signal'=>['action'=>'hodl']]; });
$recovered=Live::run($store,'unavailable',$server,1,static function(array $e):void {},static fn()=>feed());
$events=iterator_to_array($store->events('unavailable'));
$resume=array_values(array_filter($events,static fn($e)=>$e['type']==='resume'));
check($recoveredCalls===1 && count($resume)===1 && !($store->run('unavailable')['state']['paused']['XRP/USD']??true),'Restart preserves pause until a successful inference resumes the market once');
check(count($recovered['gaps'])===1,'Recovery preserves the original gap evidence');
check(count(array_filter($events,static fn($e)=>$e['type']==='evaluate' && ($e['intelligence']['signal']['action']??null)==='hodl'))===1,'The recovered decision is recorded with its inference response');
check($store->replay('unavailable','unavailable-replay')['matched'],'Failed and recovered sessions replay to the exact same state');

$store->create('deferred',$settings);$calls=0;
$server=new Server($config,static function()use(&$calls):array { if(++$calls===1) { throw new RequestDeferred(100); }return ['signal'=>['action'=>'hodl']]; });
$deferred=Live::run($store,'deferred',$server,2,static function(array $e):void {},static fn()=>feed());
check($calls===2 && $deferred['gaps']===[],'Local request scheduling defers inference without inventing a data gap');
check(($deferred['live_observations']['XRP/USD']['books']??0)>=2,'Local scheduling does not block book updates');

$store->create('throttled',$settings);$calls=0;$logs=[];
$server=new Server($config,static function()use(&$calls):array { $calls++;throw new ServerRequestFailed(429,90000); });
$throttled=Live::run($store,'throttled',$server,1,static function(array $e)use(&$logs):void { $logs[]=$e; },static fn()=>feed());
$pauses=array_values(array_filter($logs,static fn($e)=>$e['event']==='paper_paused'));
check($calls===1 && count($pauses)===1 && $pauses[0]['context']['retry_after_ms']===90000,'Retry-After can extend the bounded local backoff');
check(count($throttled['gaps'])===1 && (eventTypes($store,'throttled')['resume']??0)===0,'HTTP 429 keeps the market paused while books are accepted');

$store->create('invalid-book',$settings);$calls=0;$feed=feed(true);
$server=new Server($config,static function()use(&$calls):array { $calls++;return ['signal'=>['action'=>'buy']]; });
$invalid=Live::run($store,'invalid-book',$server,1,static function(array $e):void {},static fn()=>$feed);
check($calls===0 && $feed->books===1,'Invalid books back off before inference instead of spinning');
check(count($invalid['gaps'])===1 && (eventTypes($store,'invalid-book')['resume']??0)===0 && $invalid['live_observations']===[],'An invalid book cannot falsely recover a gap or count as observed liquidity');

$store->create('warmup',$settings);$calls=0;
$server=new Server($config,static function($sub,$resource,$body)use(&$calls):array { $calls++;check(count($body['candles'])===500,'All 500 warmup candles reach inference');return ['signal'=>['action'=>'hodl']]; });
$warmup=Live::run($store,'warmup',$server,1,static function(array $e):void {},static fn()=>feed(false,500));
check($calls===1 && eventTypes($store,'warmup')['candle']===500,'Batched warmup preserves all individual candle events');
check($store->replay('warmup','warmup-replay')['matched'],'Batched live warmup replays identically');

$store->create('malformed-inference',$settings);
$server=new Server($config,static fn():array=>['signal'=>[]]);
$malformed=Live::run($store,'malformed-inference',$server,1,static function(array $e):void {},static fn()=>feed());
check(count($malformed['gaps'])===1 && ($store->run('malformed-inference')['state']['paused']['XRP/USD']??false),'Malformed inference cannot recover or become an implicit hold');

$store->create('stale-deferred',$settings);$old=(int)floor(microtime(true)*1000)-31000;
$store->apply('stale-deferred',['id'=>'previous-book','type'=>'book','time_ms'=>$old,'symbol'=>'XRP/USD','bids'=>[['1','100']],'asks'=>[['1.01','100']]]);
$logs=[];$server=new Server($config,static function():array { throw new RequestDeferred(60000); });
$stale=Live::run($store,'stale-deferred',$server,1,static function(array $e)use(&$logs):void { $logs[]=$e; },static fn()=>feed());
check(count($stale['gaps'])===1 && count(array_filter($logs,static fn($e)=>$e['event']==='paper_paused'))===1 && (eventTypes($store,'stale-deferred')['resume']??0)===0,'Stale book recovery deferred by pacing publishes paused status and stays paused');

$store->create('shutdown-recovery',$settings);$now=(int)floor(microtime(true)*1000);
$store->apply('shutdown-recovery',['id'=>'unresolved','type'=>'gap','time_ms'=>$now,'symbol'=>'XRP/USD']);
$active=true;$server=new Server($config,static function()use(&$active):array { $active=false;return ['signal'=>['action'=>'buy']]; });
$keepRunning=static function()use(&$active):bool { return $active; };
$stopped=Live::run($store,'shutdown-recovery',$server,1,static function(array $e):void {},static fn()=>feed(),$keepRunning);
check(($store->run('shutdown-recovery')['state']['paused']['XRP/USD']??false) && (eventTypes($store,'shutdown-recovery')['resume']??0)===0 && $stopped['orders']===0,'Lease loss before recovery commit leaves both resume and decision unapplied');

$store->create('trade-burst',$settings);$active=true;$feed=tradeFeed(125);$stateWrites=0;
$fixtureDb=(new ReflectionProperty(Store::class,'db'))->getValue($store);
$fixtureDb->sqliteCreateFunction('fixture_state_write',static function()use(&$stateWrites):int { return ++$stateWrites; },0);
$fixtureDb->exec("CREATE TRIGGER fixture_trade_writes AFTER UPDATE OF state ON runs WHEN NEW.id='trade-burst' BEGIN SELECT fixture_state_write(); END");
$server=new Server($config,static fn():array=>['signal'=>['action'=>'hodl']]);
$finish=static function(array $event)use(&$active):void { if($event['event']==='paper_market_event'){$active=false;} };
$burst=Live::run($store,'trade-burst',$server,2,$finish,static fn()=>$feed,static function()use(&$active):bool{return $active;});
$fixtureDb->exec('DROP TRIGGER fixture_trade_writes');
$tradeEvents=array_values(array_filter(iterator_to_array($store->events('trade-burst')),static fn($event)=>$event['type']==='trade'));
$sourceIds=array_map(static fn($i)=>'source-'.$i,range(1,125));
check(array_column($tradeEvents,'source_id')===$sourceIds && count(array_unique(array_column($tradeEvents,'id')))===125,
    'A trade burst preserves all 125 ordered source records and deduplicates within and between batches');
check($stateWrites<=10 && $burst['gaps']===[],'A trade burst uses bounded grouped commits instead of one durable state write per trade');
check($store->replay('trade-burst','trade-burst-replay')['matched'],'Batched live trades replay to the exact persisted state');

$store->create('trade-retry',$settings);$active=true;$feed=tradeFeed(125);$rejectOnce=true;
$fixtureDb->sqliteCreateFunction('fixture_reject_trade',static function()use(&$rejectOnce):int {
    if($rejectOnce){$rejectOnce=false;throw new RuntimeException('Fixture trade commit failed.');}return 0;
},0);
$fixtureDb->exec("CREATE TRIGGER fixture_trade_failure BEFORE INSERT ON events WHEN NEW.run_id='trade-retry' AND instr(NEW.payload,'\"source_id\":\"source-30\"')>0 BEGIN SELECT fixture_reject_trade(); END");
$retried=Live::run($store,'trade-retry',$server,7,$finish,static fn()=>$feed,static function()use(&$active):bool{return $active;});
$fixtureDb->exec('DROP TRIGGER fixture_trade_failure');
$retriedEvents=array_values(array_filter(iterator_to_array($store->events('trade-retry')),static fn($event)=>$event['type']==='trade'));
check(!$rejectOnce && count($feed->requests)===2 && $feed->requests[0]===$feed->requests[1],
    'A failed first trade batch retries from its original capture boundary');
check(array_column($retriedEvents,'source_id')===$sourceIds && count(array_unique(array_column($retriedEvents,'id')))===125,
    'Failed batch persistence cannot advance deduplication or lose rolled-back source trades');
check(count($retried['gaps'])===1 && (eventTypes($store,'trade-retry')['resume']??0)===1,
    'Trade persistence recovery retains its gap and resumes only after successful fresh inference');
check($store->replay('trade-retry','trade-retry-replay')['matched'],'Failed and retried trade capture preserves deterministic replay');

echo json_encode(['milestone'=>'M4 live resilience','checks'=>$checks,'status'=>'passed','network_requests'=>0,'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT).PHP_EOL;
