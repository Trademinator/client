<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\Client\M4\{Decimal,Engine,LiveFeed,NdaxRest,NdaxPro,Settings};

$checks=0;
function check(bool $ok,string $message): void {
    global $checks;
    if(!$ok) { throw new RuntimeException($message); }
    $checks++;
}
function sameNumber(mixed $actual,mixed $expected): bool {
    return Decimal::cmp(LiveFeed::decimal($actual),LiveFeed::decimal($expected))===0;
}
function engineFor(string $symbol): Engine {
    [$base,$quote]=explode('/',$symbol);
    return new Engine(Settings::validate(['account'=>'ndax-book-fixture','source_exchange'=>'ndax','execution_exchange'=>'ndax',
        'reporting_currency'=>$quote,'balances'=>[$quote=>'10000',$base=>'0'],'opening_lots'=>[],
        'markets'=>[$symbol=>['tick_size'=>'0.0001','amount_step'=>'0.000001','minimum_amount'=>'0.000001',
            'minimum_cost'=>'1','maker_fee'=>'0','taker_fee'=>'0','risk_factor'=>'0.25','volume_participation'=>'0.01',
            'period_ms'=>3600000,'evaluation_ms'=>1200000]]]));
}
function captureBook(Engine $engine,array $book): void {
    $levels=static fn(array $rows):array=>array_map(static fn(array $row):array=>[LiveFeed::decimal($row[0]),LiveFeed::decimal($row[1])],$rows);
    $time=($book['timestamp']??0)+1;
    $engine->apply(['id'=>'ndax-recorded-book','type'=>'book','time_ms'=>$time,'observed_at_ms'=>$book['timestamp']??$time,
        'symbol'=>$book['symbol'],'bids'=>$levels($book['bids']),'asks'=>$levels($book['asks']),'transport'=>'rest']);
}
function rejected(callable $action,string $message): void {
    try { $action(); }
    catch(Throwable) { check(true,$message);return; }
    check(false,$message);
}

$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/ndax-public-snapshots.json'),true,512,JSON_THROW_ON_ERROR);
$rest=new NdaxRest();$pro=new NdaxPro();
check(array_column($fixture['snapshots'],'symbol')===['BTC/CAD','XRP/CAD'],'Recorded fixtures cover both failing NDAX markets.');
$client=new class extends \ccxt\pro\Client {
    public $book=null;
    public function __construct() {}
    public function resolve($result,$message_hash) { $this->book=$result;return $result; }
};
foreach($fixture['snapshots'] as $snapshot) {
    $symbol=$snapshot['symbol'];$expected=$snapshot['expected'];$rows=$snapshot['rows'];
    $books=[];
    foreach(['REST'=>$rest,'Pro'=>$pro] as $name=>$exchange) {
        $book=$exchange->parse_order_book($rows,$symbol);$books[$name]=$book;
        check(count($book['bids'])===$expected['bids'] && count($book['asks'])===$expected['asks'],$name.' retains all recorded sides for '.$symbol);
        check(sameNumber($book['bids'][0][0],$expected['best_bid']) && sameNumber($book['asks'][0][0],$expected['best_ask']),$name.' retains recorded best prices for '.$symbol);
        check($book['symbol']===$symbol && $book['timestamp']===$expected['timestamp'] && $book['nonce']===$expected['nonce'],$name.' preserves snapshot identity and metadata for '.$symbol);
        $reversed=$exchange->parse_order_book(array_reverse($rows),$symbol);
        check($reversed['timestamp']===$book['timestamp'] && $reversed['nonce']===$book['nonce'],$name.' takes maximum metadata independent of row order for '.$symbol);
        $descending=true;$ascending=true;
        foreach($reversed['bids'] as $i=>$level) { if($i>0 && $reversed['bids'][$i-1][0]<$level[0]) { $descending=false; } }
        foreach($reversed['asks'] as $i=>$level) { if($i>0 && $reversed['asks'][$i-1][0]>$level[0]) { $ascending=false; } }
        check($descending && $ascending,$name.' sorts reversed snapshot sides correctly for '.$symbol);
        $engine=engineFor($symbol);captureBook($engine,$book);
        check(($engine->state['live_observations'][$symbol]['books']??0)===1,$name.' snapshot passes existing Engine book validation for '.$symbol);
        check($engine->state['orders']===[] && $engine->state['fills']===[],$name.' book validation submits no orders or artificial trades for '.$symbol);
        $empty=$exchange->parse_order_book([],$symbol);
        rejected(fn()=>captureBook(engineFor($symbol),$empty),$name.' empty snapshot is still rejected for '.$symbol);
        $oneSide=$exchange->parse_order_book(array_values(array_filter($rows,static fn($r)=>$r[9]===0)),$symbol);
        rejected(fn()=>captureBook(engineFor($symbol),$oneSide),$name.' one-sided snapshot is still rejected for '.$symbol);
        foreach(['locked'=>1,'crossed'=>0.9] as $kind=>$factor) {
            $invalid=$rows;
            foreach($invalid as &$row) { if($row[9]===1) { $row[6]=$expected['best_bid']*$factor; } }unset($row);
            $parsed=$exchange->parse_order_book($invalid,$symbol);
            rejected(fn()=>captureBook(engineFor($symbol),$parsed),$name.' '.$kind.' snapshot is still rejected for '.$symbol);
        }
        $invalid=$rows;$invalid[0][8]=-1;
        $parsed=$exchange->parse_order_book($invalid,$symbol);
        rejected(fn()=>captureBook(engineFor($symbol),$parsed),$name.' negative quantity is still rejected for '.$symbol);
        $invalid=$rows;$invalid[0][9]=3;
        rejected(fn()=>$exchange->parse_order_book($invalid,$symbol),$name.' unknown side is rejected instead of becoming an ask for '.$symbol);
    }
    check($books['REST']===$books['Pro'],'REST and Pro parse the same recorded snapshot identically for '.$symbol);
    // Exercise CCXT's actual inherited subscription initializer, without a socket.
    $pro->handle_order_book_subscription($client,['o'=>$rows],['symbol'=>$symbol,'limit'=>50,'messageHash'=>'fixture:'.$symbol]);
    $initialized=$client->book->limit();
    check(count($initialized['bids'])===$expected['bids'] && count($initialized['asks'])===$expected['asks'],'Pro subscription initializer retains both recorded sides for '.$symbol);
    check(sameNumber($initialized['bids'][0][0],$expected['best_bid']) && sameNumber($initialized['asks'][0][0],$expected['best_ask']),'Pro initialized order book has the recorded best prices for '.$symbol);
    check($initialized['timestamp']===$expected['timestamp'] && $initialized['nonce']===$expected['nonce'],'Pro initialized order book preserves snapshot metadata for '.$symbol);
    $ws=new class($client->book) {
        public array $has=['watchOrderBook'=>true];
        public function __construct(private object $book) {}
        public function watch_order_book(string $symbol,int $limit) { return \React\Promise\resolve($this->book); }
        public function close(): void {}
    };
    $fallback=new class {
        public int $calls=0;
        public function fetch_order_book(string $symbol,int $limit): array { $this->calls++;throw new RuntimeException('Unexpected REST fallback.'); }
    };
    $reflection=new ReflectionClass(LiveFeed::class);$feed=$reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('ws')->setValue($feed,$ws);$reflection->getProperty('rest')->setValue($feed,$fallback);
    $normalized=$feed->book($symbol);
    check(is_array($normalized['bids']) && is_array($normalized['asks']) && $normalized['transport']==='websocket' && $fallback->calls===0,'LiveFeed accepts the real Pro book container without REST fallback for '.$symbol);
    check(count($normalized['bids'])===$expected['bids'] && count($normalized['asks'])===$expected['asks']
        && sameNumber($normalized['bids'][0][0],$expected['best_bid']) && sameNumber($normalized['asks'][0][0],$expected['best_ask']),'LiveFeed container normalization preserves the recorded levels for '.$symbol);
    $engine=engineFor($symbol);captureBook($engine,$normalized);
    check(($engine->state['live_observations'][$symbol]['books']??0)===1 && $engine->state['orders']===[] && $engine->state['fills']===[],'Pro initialized snapshot passes the real Engine without creating trades for '.$symbol);
    $feed->close();
    $restFeed=$reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('ws')->setValue($restFeed,null);
    $reflection->getProperty('rest')->setValue($restFeed,new class($books['REST']) {
        public function __construct(private array $book) {}
        public function fetch_order_book(string $symbol,int $limit): array { return $this->book; }
    });
    check($restFeed->book($symbol)===[...$books['REST'],'transport'=>'rest'],'LiveFeed preserves normal REST array books for '.$symbol);
}
echo json_encode(['suite'=>'m4-ndax-public-book','status'=>'passed','checks'=>$checks,'network_requests'=>0,
    'exchange_orders_submitted'=>0,'artificial_trades'=>0],JSON_PRETTY_PRINT).PHP_EOL;
