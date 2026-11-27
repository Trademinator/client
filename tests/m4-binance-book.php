<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\Client\M4\{BinanceOrderBook,BinancePro,LiveFeed};

$checks=0;
function check(bool $ok,string $name):void {
    global $checks;
    if(!$ok) { throw new RuntimeException($name); }
    $checks++;echo 'PASS '.$name.PHP_EOL;
}
function snapshot(int $depth):array {
    $book=['symbol'=>'ETH/USDT','nonce'=>7,'timestamp'=>1700000000000,'asks'=>[],'bids'=>[]];
    for($i=0;$i<$depth+2;$i++) {
        $book['asks'][]=[101+$i,1];$book['bids'][]=[99-$i,1];
    }
    return $book;
}
function expectedAfterDeltas(array $snapshot,string $side,int $depth):array {
    return [...array_slice($snapshot[$side],1,$depth-1),[$snapshot[$side][$depth][0],7]];
}
function applyDeltas(\ccxt\pro\OrderBook $book,array $snapshot,int $depth):void {
    foreach(['asks','bids'] as $side) {
        $book[$side]->storeArray([$snapshot[$side][0][0],0]);
        $book[$side]->storeArray([$snapshot[$side][$depth][0],7]);
    }
    $book->limit();
}

// Reset, trim the snapshot, delete the best level, then update a trimmed price.
// In upstream 4.5.57 this creates an amount-only row at asks/bids[depth-1].
foreach([20,50] as $depth) {
    $snapshot=snapshot($depth);$book=new BinanceOrderBook([],$depth);
    $asksAlias=$book['asks'];$bidsAlias=$book['bids'];$book->cache=[['u'=>8]];
    $book->reset($snapshot);
    check($book['asks']===$asksAlias && $book['bids']===$bidsAlias && $book->cache===[['u'=>8]],
        'Depth '.$depth.' reset preserves side aliases and the buffered delta cache');
    check($book['symbol']==='ETH/USDT' && $book['nonce']===7 && $book['timestamp']===1700000000000
        && $book['datetime']==='2023-11-14T22:13:20.000Z',
        'Depth '.$depth.' reset preserves upstream snapshot metadata');
    foreach(['asks','bids'] as $side) {
        check($book[$side]->getArrayCopy()===$snapshot[$side]
            && count($book[$side]->index)===$depth+2,
            'Depth '.$depth.' '.$side.' reset retains every real row with one index entry each');
    }
    $book->limit();
    foreach(['asks','bids'] as $side) {
        check($book[$side]->getArrayCopy()===array_slice($snapshot[$side],0,$depth)
            && count($book[$side]->index)===$depth,
            'Depth '.$depth.' '.$side.' limiting leaves no phantom indexed prices');
    }
    applyDeltas($book,$snapshot,$depth);
    foreach(['asks','bids'] as $side) {
        $expected=expectedAfterDeltas($snapshot,$side,$depth);
        $expectedIndex=array_map(static fn($row)=>$side==='bids'?-$row[0]:$row[0],$expected);
        check($book[$side]->getArrayCopy()===$expected && $book[$side]->index===$expectedIndex,
            'Depth '.$depth.' '.$side.' delete and update retain the correct price and amount at the tail');
    }
}

$book=new BinanceOrderBook([],3);$book->cache=[['u'=>8]];
$book->reset(snapshot(3));$book->reset([]);
check($book['asks']->getArrayCopy()===[] && $book['bids']->getArrayCopy()===[]
    && $book['asks']->index===[] && $book['bids']->index===[] && $book->cache===[['u'=>8]]
    && $book['symbol']===null && $book['nonce']===null && $book['timestamp']===null && $book['datetime']===null,
    'An empty reset clears rows, indexes and snapshot metadata while preserving the delta cache');
foreach([3,4,3] as $size) {
    $snapshot=snapshot($size);$book->reset($snapshot);
    check($book['asks']->getArrayCopy()===$snapshot['asks'] && $book['bids']->getArrayCopy()===$snapshot['bids']
        && count($book['asks']->index)===$size+2 && count($book['bids']->index)===$size+2,
        'Repeated reset with '.$size.'-depth fixture leaves no stale rows or index entries');
}
$before=json_encode($book,JSON_THROW_ON_ERROR);$book->update([...snapshot(4),'nonce'=>6]);
check(json_encode($book,JSON_THROW_ON_ERROR)===$before,'Inherited update still ignores a snapshot with an older nonce');
$book->update([...snapshot(4),'nonce'=>8]);
check($book['nonce']===8 && count($book['asks']->index)===6 && count($book['bids']->index)===6,
    'Inherited update dispatches through the compatible reset for a newer snapshot');

$snapshot=snapshot(3);$view=new BinanceOrderBook([],3);
$view['asks']->n=2;$view['bids']->n=2;$view->reset($snapshot);
foreach(['asks','bids'] as $side) {
    check(count($view[$side])===2 && count($view[$side]->jsonSerialize())===2
        && count($view[$side]->getArrayCopy())===5 && count($view[$side]->index)===5,
        'An n-limited '.$side.' view keeps the complete underlying price index after reset');
    $view[$side]->n=PHP_INT_MAX;
}
$view->limit();applyDeltas($view,$snapshot,3);
check($view['asks']->getArrayCopy()===expectedAfterDeltas($snapshot,'asks',3)
    && $view['bids']->getArrayCopy()===expectedAfterDeltas($snapshot,'bids',3),
    'Restoring the full view preserves correct depth limiting and subsequent deltas');

// Neither the factory nor the private container normalizer needs an exchange connection.
$exchange=(new ReflectionClass(BinancePro::class))->newInstanceWithoutConstructor();
$factoryBook=$exchange->order_book([],3);
check($factoryBook instanceof BinanceOrderBook && $factoryBook['asks']->depth===3 && $factoryBook['bids']->depth===3,
    'Binance Pro creates the compatible book with the requested depth');
$factoryBook->reset($snapshot);$factoryBook->limit();applyDeltas($factoryBook,$snapshot,3);
$normalizer=new ReflectionMethod(LiveFeed::class,'bookArray');
$normalized=$normalizer->invoke(null,$factoryBook);
check($normalized['asks']===expectedAfterDeltas($snapshot,'asks',3)
    && $normalized['bids']===expectedAfterDeltas($snapshot,'bids',3)
    && $normalized['symbol']===$snapshot['symbol'] && $normalized['nonce']===$snapshot['nonce'],
    'LiveFeed normalizes the compatible real CCXT book without changing prices, amounts or metadata');
$askPrices=array_map(static fn($row)=>(float)LiveFeed::decimal($row[0]),$normalized['asks']);
$bidPrices=array_map(static fn($row)=>(float)LiveFeed::decimal($row[0]),$normalized['bids']);
check($askPrices===[102.0,103.0,104.0] && $bidPrices===[98.0,97.0,96.0],
    'Normalized prices pass the actual LiveFeed decimal conversion without missing values');
$rejected=false;
try { LiveFeed::decimal(null,'exchange=binance symbol=ETH/USDT source=book field=asks[0].price transport=websocket'); }
catch(RuntimeException $error) { $rejected=str_contains($error->getMessage(),'field=asks[0].price') && str_contains($error->getMessage(),'type=null'); }
check($rejected,'A genuinely missing source price remains rejected with field diagnostics');

echo json_encode(['suite'=>'m4-binance-book','checks'=>$checks,'status'=>'passed',
    'network_requests'=>0,'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
