<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;

/** Recovery rebuilds a new auditable run; the original unverified run remains intact. */
final class Recovery
{
    public static function replay(Store $store,string $source,string $target,array $capture): array
    {
        $original=$store->run($source);$symbol=$capture['symbol']??'';$from=$capture['from_ms']??null;$to=$capture['to_ms']??null;
        if(($capture['complete']??false)!==true || !is_int($from) || !is_int($to) || $from<0 || $to<$from
            || !is_string($capture['provenance']??null) || $capture['provenance']==='' || empty($capture['events'])) { throw new RuntimeException('A complete source capture with explicit coverage and provenance is required.'); }
        $matched=false;foreach($original['state']['gaps'] as $gap) { if($gap['symbol']===$symbol && $gap['from_ms']===$from && $gap['to_ms']===$to) { $matched=true; } }
        if(!$matched) { throw new RuntimeException('Recovery coverage must match one recorded gap exactly.'); }
        $events=[];$previous=null;$sequence=$capture['first_sequence']??null;
        if(!is_int($sequence)) { throw new RuntimeException('Recovery needs a continuous source sequence.'); }
        foreach($capture['events'] as $event) {
            if(($event['source_sequence']??null)!==$sequence++ || ($event['symbol']??null)!==$symbol
                || !in_array($event['type']??null,['book','trade','candle'],true) || !is_int($event['time_ms']??null)
                || $event['time_ms']<$from || $event['time_ms']>$to || ($previous!==null&&$event['time_ms']<$previous)) { throw new RuntimeException('Incomplete or unordered recovery stream.'); }
            $previous=$event['time_ms'];$events[]=$event;
        }
        if(($capture['last_sequence']??null)!==$sequence-1) { throw new RuntimeException('Recovery sequence coverage mismatch.'); }
        foreach($store->events($source) as $event) {
            if(($event['symbol']??null)===$symbol) {
                if($event['type']==='gap' && ($event['from_ms']??$event['time_ms'])===$from && $event['time_ms']===$to) { continue; }
                if(in_array($event['type'],['book','trade','candle'],true) && $event['time_ms']>=$from && $event['time_ms']<=$to) { continue; }
            }
            $events[]=$event;
        }
        usort($events,static fn($a,$b)=>$a['time_ms']<=>$b['time_ms']);
        $config=$original['config'];$config['recovery']=['source_run'=>$source,'symbol'=>$symbol,'from_ms'=>$from,'to_ms'=>$to,'provenance'=>$capture['provenance'],'capture_sha256'=>hash('sha256',json_encode($capture,JSON_THROW_ON_ERROR))];
        $store->create($target,$config);
        foreach($events as $event) { $store->apply($target,$event); }
        return ['run'=>$target,'recovered_from'=>$source,'coverage'=>['from_ms'=>$from,'to_ms'=>$to,'symbol'=>$symbol,'provenance'=>$capture['provenance']],
            'capture_sha256'=>hash('sha256',json_encode($capture,JSON_THROW_ON_ERROR)),'report'=>Report::build($store->run($target))];
    }
}
