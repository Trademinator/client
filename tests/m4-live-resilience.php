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

// Rejected observation timestamps must preserve forensic context without changing ledger safety.
foreach([
    'future'=>(int)floor(microtime(true)*1000)+60000,
    'string'=>(string)((int)floor(microtime(true)*1000)),
    'negative'=>-1,
    'array'=>['unexpected'],
] as $case=>$timestamp) {
    $run='timestamp-'.$case;$store->create($run,$settings);
    $timedFeed=new class(feed(),$timestamp) {
        public function __construct(private object $base,private mixed $timestamp) {}
        public function market(string $symbol):array { return $this->base->market($symbol); }
        public function book(string $symbol):array { return [...$this->base->book($symbol),'timestamp'=>$this->timestamp,'transport'=>'websocket']; }
        public function trades(string $symbol,?int $since):array { return $this->base->trades($symbol,$since); }
        public function candles(string $symbol,string $period,int $limit):array { return $this->base->candles($symbol,$period,$limit); }
        public function close():void { $this->base->close(); }
    };
    $logs=[];$server=new Server($config,static fn():array=>throw new RuntimeException('Invalid books cannot reach inference.'));
    $report=Live::run($store,$run,$server,1,static function(array $e)use(&$logs):void { $logs[]=$e; },static fn()=>$timedFeed);
    $pauses=array_values(array_filter($logs,static fn($e)=>$e['event']==='paper_paused'));
    $diagnostic=$pauses[0]['context']??[];
    check(count($pauses)===1 && ($diagnostic['message']??null)==='Invalid book observation time.'
        && ($diagnostic['retry_after_ms']??null)===5000,'Invalid timestamp '.$case.' retains the original pause and backoff');
    check(($diagnostic['source_timestamp_type']??null)===get_debug_type($timestamp)
        && ($diagnostic['source_timestamp_ms']??null)===(is_scalar($timestamp)?$timestamp:null)
        && ($diagnostic['transport']??null)==='websocket'
        && is_int($diagnostic['local_event_time_ms']??null)
        && ($diagnostic['source_minus_local_ms']??null)===(is_int($timestamp)?$timestamp-$diagnostic['local_event_time_ms']:null),
        'Invalid timestamp '.$case.' records source value, type, local time and signed skew');
    check(count($report['gaps'])===1 && !$report['verified'] && $report['live_observations']===[]
        && $report['orders']===0 && $report['fills']===0,'Invalid timestamp '.$case.' cannot authorize paper activity');
    check($store->replay($run,$run.'-replay')['matched'],'Invalid timestamp '.$case.' retains deterministic ledger replay');
}

$store->create('timestamp-valid',$settings);$logs=[];
$server=new Server($config,static fn():array=>['signal'=>['action'=>'hodl']]);
$valid=Live::run($store,'timestamp-valid',$server,1,static function(array $e)use(&$logs):void { $logs[]=$e; },static fn()=>feed());
check($valid['verified'] && $valid['gaps']===[] && ($valid['live_observations']['XRP/USD']['books']??0)>0
    && count(array_filter($logs,static fn($e)=>$e['event']==='paper_paused'))===0,
    'Valid book timestamps are accepted without diagnostic fields or pauses');

// Captured NDAX jitter includes +1 through +34 ms on REST and +50 ms on WebSocket.
// Accept at most 100 ms into the future, retaining the unmodified source time.
// Values above the bound still fail closed, with exact replay and ledger rollback.
$capturedAt=1795519556090;
$limit=\Trademinator\Client\M4\BookTimestamp::MAX_FUTURE_JITTER_MS;
$ndaxSettings=Settings::validate([...$settings,'source_exchange'=>'ndax','execution_exchange'=>'ndax']);
foreach(['websocket'=>[4,25,34,50,100],'rest'=>[1,13,25,26,34,50,100]] as $transport=>$deltas) {
    foreach($deltas as $delta) {
        $normalized=\Trademinator\Client\M4\BookTimestamp::normalize(
            'ndax',['timestamp'=>$capturedAt+$delta,'transport'=>$transport],$capturedAt);
        check($normalized===['observed_at_ms'=>$capturedAt,'source_observed_at_ms'=>$capturedAt+$delta,'timestamp_adjustment_ms'=>$delta],
            'NDAX '.$transport.' +'.$delta.' ms jitter retains the unmodified source timestamp within the existing bound');
        $run='ndax-'.$transport.'-jitter-'.$delta;$store->create($run,$ndaxSettings);
        $bookEvent=['id'=>'ndax-accepted-book','type'=>'book','time_ms'=>$capturedAt,'symbol'=>'XRP/USD',
            'bids'=>[['1','100']],'asks'=>[['1.01','100']],'transport'=>$transport,...$normalized];
        $store->apply($run,$bookEvent);
        $savedBook=iterator_to_array($store->events($run));
        check(count($savedBook)===1 && $savedBook[0]['source_observed_at_ms']===$capturedAt+$delta
            && $savedBook[0]['observed_at_ms']===$capturedAt
            && $savedBook[0]['timestamp_adjustment_ms']===$delta && $savedBook[0]['transport']===$transport,
            'NDAX '.$transport.' +'.$delta.' ms correction preserves both timestamps, adjustment and transport in the ledger');
        $state=$store->run($run)['state'];
        check($state['gaps']===[] && ($state['live_observations']['XRP/USD']['books']??null)===1,
            'NDAX '.$transport.' +'.$delta.' ms correction passes Engine validation without fabricating a gap');
        check($store->replay($run,$run.'-replay')['matched'],
            'NDAX '.$transport.' +'.$delta.' ms correction replays to the exact persisted state');
    }
}
foreach([
    'beyond_tolerance'=>['ndax','websocket',$capturedAt+$limit+1],
    'rest_beyond_tolerance'=>['ndax','rest',$capturedAt+$limit+1],
    'unknown_transport'=>['ndax','unknown',$capturedAt+4],
    'null_transport'=>['ndax',null,$capturedAt+4],
    'other_exchange'=>['bitso','websocket',$capturedAt+4],
    'other_exchange_rest'=>['bitso','rest',$capturedAt+4],
    'malformed_string'=>['ndax','websocket',(string)($capturedAt+4)],
    'rest_malformed_string'=>['ndax','rest',(string)($capturedAt+4)],
    'rest_malformed_array'=>['ndax','rest',[$capturedAt+4]],
    'rest_malformed_float'=>['ndax','rest',(float)($capturedAt+4)],
    'negative'=>['ndax','websocket',-1],
    'historical'=>['ndax','websocket',$capturedAt-4],
] as $case=>[$exchange,$transport,$timestamp]) {
    check(\Trademinator\Client\M4\BookTimestamp::normalize($exchange,['timestamp'=>$timestamp,'transport'=>$transport],$capturedAt)
        ===['observed_at_ms'=>$timestamp],'NDAX correction leaves '.$case.' untouched for strict Engine validation');
}
check(\Trademinator\Client\M4\BookTimestamp::normalize('ndax',['timestamp'=>$capturedAt+4],$capturedAt)
    ===['observed_at_ms'=>$capturedAt+4],'NDAX correction leaves an absent transport untouched for strict Engine validation');

foreach(['websocket','rest'] as $transport) {
    $run='ndax-'.$transport.'-jitter-'.$limit;
    $before=$store->run($run)['state'];$beforeEvents=iterator_to_array($store->events($run));
    $rejectedAt=$capturedAt+1;
    $badBook=['id'=>'ndax-rejected-book','type'=>'book','time_ms'=>$rejectedAt,'symbol'=>'XRP/USD',
        'bids'=>[['1','100']],'asks'=>[['1.01','100']],'transport'=>$transport,
        ...\Trademinator\Client\M4\BookTimestamp::normalize(
            'ndax',['timestamp'=>$rejectedAt+$limit+1,'transport'=>$transport],$rejectedAt)];
    $rejected=false;
    try { $store->apply($run,$badBook); }
    catch(RuntimeException $error) { $rejected=$error->getMessage()==='Invalid book observation time.'; }
    check($rejected && $store->run($run)['state']===$before
        && iterator_to_array($store->events($run))===$beforeEvents,
        'NDAX '.$transport.' +'.($limit+1).' ms timestamp fails closed and rolls back state and ledger');
}

// A malformed numeric field must name its source and field, while retaining
// the existing fail-closed gap, pause, and backoff behavior (no real networks).
$numericSettings=Settings::validate([...$settings,'source_exchange'=>'binance','execution_exchange'=>'binance']);
foreach([
    'book-price'=>['book','bids[0].price'],
    'book-amount'=>['book','asks[0].amount'],
    'trade-price'=>['trade','price'],
    'trade-amount'=>['trade','amount'],
    'candle-volume'=>['candle','volume'],
] as $case=>[$source,$field]) {
    $run='numeric-'.$case;$store->create($run,$numericSettings);
    $badFeed=new class(feed(),$case) {
        public function __construct(private object $base,private string $case) {}
        public function market(string $symbol):array { return $this->base->market($symbol); }
        public function book(string $symbol):array {
            $book=$this->base->book($symbol);
            if($this->case==='book-price') { $book['bids'][0][0]=null; }
            if($this->case==='book-amount') { $book['asks'][0][1]=null; }
            return $book;
        }
        public function trades(string $symbol,?int $since):array {
            if($this->case==='trade-price' || $this->case==='trade-amount') {
                return [['id'=>'null-value','timestamp'=>$since,'side'=>'buy',
                    'price'=>$this->case==='trade-price'?null:'1','amount'=>$this->case==='trade-amount'?null:'1']];
            }
            return [];
        }
        public function candles(string $symbol,string $period,int $limit):array {
            $rows=$this->base->candles($symbol,$period,$limit);
            if($this->case==='candle-volume') { $rows[0][5]=null; }
            return $rows;
        }
        public function close():void { $this->base->close(); }
    };
    $logs=[];$server=new Server($config,static fn():array=>throw new RuntimeException('Bad numeric data reached inference.'));
    $report=Live::run($store,$run,$server,1,static function(array $e)use(&$logs):void { $logs[]=$e; },static fn()=>$badFeed);
    $pauses=array_values(array_filter($logs,static fn($e)=>$e['event']==='paper_paused'));
    $message=$pauses[0]['context']['message']??'';
    check(count($pauses)===1 && str_contains($message,'Invalid public numeric field')
        && str_contains($message,'exchange=binance') && str_contains($message,'symbol=XRP/USD')
        && str_contains($message,'source='.$source) && str_contains($message,'field='.$field)
        && str_contains($message,'type=null') && str_contains($message,'transport=rest')
        && ($pauses[0]['context']['retry_after_ms']??null)===5000,
        'Null '.$case.' reports its exact source and field with existing backoff');
    check(count($report['gaps'])===1 && !$report['verified'] && $report['orders']===0 && $report['fills']===0,
        'Null '.$case.' does not authorize a paper order or erase its gap');
    check($store->replay($run,$run.'-replay')['matched'],
        'Null '.$case.' retains deterministic paper ledger replay');
}
check(\Trademinator\Client\M4\Decimal::cmp(\Trademinator\Client\M4\LiveFeed::decimal('1.25','fixture valid value'),'1.25')===0,
    'Valid decimal data retains its numeric conversion');

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
