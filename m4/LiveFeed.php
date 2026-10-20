<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use function Trademinator\BcMath\bcconv;
use function React\Async\await;
use function React\Promise\Timer\timeout;

/** Public market data only. No exchange API keys and no order/withdrawal methods. */
final class LiveFeed
{
    private object $rest;
    private ?object $ws=null;
    public function __construct(string $exchange)
    {
        if(!preg_match('/^[a-z][a-z0-9]{1,40}$/D',$exchange)) { throw new RuntimeException('Invalid exchange.'); }
        $class='\\ccxt\\'.$exchange;if(!class_exists($class)) { throw new RuntimeException('Exchange unavailable in installed CCXT.'); }
        $this->rest=new $class(['enableRateLimit'=>true,'timeout'=>10000]);$this->rest->load_markets();
        $pro='\\ccxt\\pro\\'.$exchange;
        if(class_exists($pro)) { $this->ws=new $pro(['enableRateLimit'=>true,'timeout'=>5000,'newUpdates'=>true]); }
    }
    public static function decimal(mixed $v): string { return bcconv($v); }
    public function market(string $symbol): array
    {
        $m=$this->rest->market($symbol);
        if(($m['spot']??false)!==true || ($m['active']??true)===false) { throw new RuntimeException('Exact active spot pair unavailable on exchange.'); }
        return $m;
    }
    public function rules(string $symbol): array
    {
        return MarketRules::from($this->market($symbol), $this->rest->precisionMode, $this->rest->fees['trading'] ?? []);
    }
    public function book(string $symbol): array
    {
        if($this->ws!==null && ($this->ws->has['watchOrderBook']??false)) {
            try { $book=await(timeout($this->ws->watch_order_book($symbol,50),5));$book['transport']='websocket';return $book; }
            catch(\Throwable) { $this->close(); }
        }
        $book=$this->rest->fetch_order_book($symbol,50);$book['transport']='rest';return $book;
    }
    public function trades(string $symbol,?int $since): array
    {
        if(!($this->rest->has['fetchTrades']??false)) { return []; }
        return $this->rest->fetch_trades($symbol,$since,1000);
    }
    public function candles(string $symbol,string $period,int $limit=500): array
    {
        return $this->rest->fetch_ohlcv($symbol,$period,null,$limit);
    }
    public function conversion(string $currency,string $reporting): ?array
    {
        if($currency===$reporting) { return ['rate'=>'1','observed_at_ms'=>(int)floor(microtime(true)*1000)]; }
        foreach([[$currency.'/'.$reporting,false],[$reporting.'/'.$currency,true]] as [$pair,$inverse]) {
            if(!isset($this->rest->markets[$pair]) || !($this->rest->markets[$pair]['spot']??false)) { continue; }
            try {
                $ticker=$this->rest->fetch_ticker($pair);$price=self::decimal($ticker['last']??$ticker['close']??0);
                if(Decimal::cmp($price,'0')<=0) { continue; }
                return ['rate'=>$inverse?Decimal::div('1',$price):$price,'observed_at_ms'=>$ticker['timestamp']??(int)floor(microtime(true)*1000)];
            } catch(\Throwable) { return null; }
        }
        return null;
    }
    public function close(): void
    {
        if($this->ws!==null) { try { $this->ws->close(); }catch(\Throwable) {} $this->ws=null; }
    }
}
