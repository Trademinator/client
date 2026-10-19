<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M4\Decimal as D;

final class Replay
{
    public static function import(Store $store,string $run,string $file): int
    {
        $h=fopen($file,'rb');if($h===false) { throw new RuntimeException('Cannot read events.'); }$count=0;
        try { while(($line=fgets($h,16777217))!==false) {
            if(strlen($line)>16777216) { throw new RuntimeException('Event line too large.'); }
            if(trim($line)==='') { continue; }$store->apply($run,json_decode($line,true,512,JSON_THROW_ON_ERROR));$count++;
        } } finally { fclose($h); } return $count;
    }
    /** Closed-candle inference is emitted only AFTER the synthetic intrabar path. */
    public static function candles(Store $store,string $run,string $symbol,iterable $candles,callable $infer,string $path='low_first',int $warmup=500): int
    {
        if(!in_array($path,['low_first','high_first'],true)) { throw new RuntimeException('Unknown OHLC path.'); }
        $config=$store->run($run)['config'];$m=$config['markets'][$symbol]??throw new RuntimeException('Unknown market.');$previous=null;$window=[];$count=0;
        foreach($candles as $c) {
            if(!is_array($c) || count($c)!==6 || !is_int($c[0])) { throw new RuntimeException('Invalid OHLCV row.'); }
            $t=$c[0];$end=$t+$m['period_ms'];
            if($previous!==null && $t!==$previous) {
                if($t<$previous) { throw new RuntimeException('Overlapping candles.'); }
                $store->apply($run,['id'=>'gap-'.$symbol.'-'.$t,'type'=>'gap','symbol'=>$symbol,'time_ms'=>$t,'from_ms'=>$previous,'reason'=>'missing_historical_candles']);
                $store->apply($run,['id'=>'resume-'.$symbol.'-'.$t,'type'=>'resume','symbol'=>$symbol,'time_ms'=>$t]);$window=[];
            }
            $prices=$path==='low_first'?[$c[1],$c[3],$c[2],$c[4]]:[$c[1],$c[2],$c[3],$c[4]];
            $budget=D::div(D::mul(D::value($c[5]),$m['volume_participation']),'12');
            foreach($prices as $i=>$price) {
                $at=$t+(int)floor(($m['period_ms']-1)*$i/3);$price=D::value($price,true);
                $bid=D::max($m['tick_size'],D::sub($price,$m['tick_size']));$ask=D::add($price,$m['tick_size']);
                $store->apply($run,['id'=>'book-'.$symbol.'-'.$t.'-'.$i,'type'=>'book','time_ms'=>$at,'symbol'=>$symbol,'bids'=>[[$bid,$budget]],'asks'=>[[$ask,$budget]],'approximation'=>'OHLC '.$path.'; one tick half spread; volume participation divided across path and sides']);
                // Each direction owns half of the remaining trade budget; volume cannot be reused.
                foreach(['buy','sell'] as $side) { $store->apply($run,['id'=>'trade-'.$symbol.'-'.$t.'-'.$i.'-'.$side,'type'=>'trade','time_ms'=>$at,'symbol'=>$symbol,'price'=>$price,'quantity'=>D::div($budget,'2'),'side'=>$side,'approximate'=>true]); }
            }
            $store->apply($run,['id'=>'candle-'.$symbol.'-'.$t,'type'=>'candle','time_ms'=>$end,'symbol'=>$symbol,'ohlcv'=>$c]);
            $window[]=$c;if(count($window)>$warmup) { array_shift($window); }
            $signal=$infer($window,$end);
            $store->apply($run,['id'=>'evaluation-'.$symbol.'-'.$t,'type'=>'evaluate','symbol'=>$symbol,'time_ms'=>$end,'action'=>$signal['action']??'hold','expires_at_ms'=>$end+$m['evaluation_ms'],'intelligence'=>$signal]);
            $previous=$end;$count++;
        } return $count;
    }
    public static function sweep(Store $store,string $source,string $prefix,array $sets): array
    {
        if(!$sets || count($sets)>32) { throw new RuntimeException('Supply 1–32 named parameter sets.'); }
        $base=$store->run($source);$result=[];
        foreach($sets as $name=>$overrides) {
            Store::id((string)$name);$config=array_replace_recursive($base['config'],$overrides);
            foreach(['balances','opening_lots','source_exchange','execution_exchange','reporting_currency','account'] as $k) { if(($config[$k]??null)!==($base['config'][$k]??null)) { throw new RuntimeException('Sweeps must share starting funds and exchange identities.'); } }
            $id=$prefix.'-'.$name;$store->create($id,$config);
            foreach($store->events($source) as $event) { $store->apply($id,$event); }
            $result[$name]=Report::build($store->run($id));
        } return $result;
    }
}
