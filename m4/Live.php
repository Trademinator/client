<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M1\ConsoleEvents;
use Trademinator\Client\M1\EventRecord;

final class Live
{
    public static function run(Store $store,string $run,Server $server,int $seconds,ConsoleEvents|callable $console,?callable $feedFactory=null,?callable $keepRunning=null): array
    {
        $initial=$store->run($run);$c=$initial['config'];PaperConfig::live($c);
        $lock=$store->acquireLiveLock($run);$execution=null;$source=null;
        $end=$seconds===0?INF:microtime(true)+$seconds;$next=[];$fxNext=[];$cursors=[];$seen=[];$gapped=[];
        $feedFactory??=static fn(string $exchange):object=>new LiveFeed($exchange);
        $emit=static function(string $level,string $name,array $data)use($console,$run):void {
            $record=EventRecord::create($level,$name,$data,$run);
            if($console instanceof ConsoleEvents) { $console->write($record); } else { $console($record); }
        };
        $apply=static function(array $event)use($store,$run,$keepRunning):array {
            if($keepRunning!==null && !$keepRunning()) { throw new RuntimeException('Paper session stopped.'); }
            $event['id']??='live-'.bin2hex(random_bytes(12));return $store->apply($run,$event);
        };
        try {
            $c = $store->resolve($run, static function (array $settings) use ($server, $keepRunning): array {
                $resolved = IntelligenceSetup::resolve($settings, $server);
                if ($keepRunning !== null && !$keepRunning()) { throw new RuntimeException('Paper session stopped during intelligence discovery.'); }
                return $resolved;
            });
            $execution=$feedFactory($c['execution_exchange']);$source=$c['source_exchange']===$c['execution_exchange']?$execution:$feedFactory($c['source_exchange']);
            foreach($c['markets'] as $s=>$m) { $execution->market($s);$source->market($s); }
            while(microtime(true)<$end && ($keepRunning===null || $keepRunning())) {
                foreach($c['markets'] as $symbol=>$m) {
                    try {
                        if($keepRunning!==null && !$keepRunning()) { break 2; }
                        $started=(int)floor(microtime(true)*1000);
                        if($started>=($next[$symbol]??0)) {
                            $apply(['type'=>'evaluate','time_ms'=>$started,'symbol'=>$symbol,'action'=>'hold','expires_at_ms'=>$started+$m['evaluation_ms'],'intelligence'=>['reason'=>'evaluation_cancel_before_refresh']]);
                        }
                        $quote=explode('/',$symbol)[1];
                        if($quote!==$c['reporting_currency'] && $started>=($fxNext[$quote]??0)) {
                            $fx=$execution->conversion($quote,$c['reporting_currency'])??$source->conversion($quote,$c['reporting_currency']);
                            if($fx!==null) { $apply(['type'=>'fx','time_ms'=>(int)floor(microtime(true)*1000),'currency'=>$quote,...$fx]); }
                            $fxNext[$quote]=$started+60000;
                        }
                        $book=$source->book($symbol);$now=(int)floor(microtime(true)*1000);
                        $last=$store->run($run)['state'];
                        if(isset($last['books'][$symbol]) && $now-$last['books'][$symbol]['time_ms']>$c['stale_ms']) {
                            $apply(['type'=>'gap','time_ms'=>$now,'symbol'=>$symbol,'from_ms'=>$last['books'][$symbol]['time_ms'],'reason'=>'restart_live_history_not_proven_complete']);$gapped[$symbol]=true;
                        }
                        if($gapped[$symbol]??false) { $apply(['type'=>'resume','time_ms'=>$now,'symbol'=>$symbol]);$gapped[$symbol]=false; }
                        $levels=static fn(array $rows):array=>array_map(static fn(array $row):array=>[LiveFeed::decimal($row[0]),LiveFeed::decimal($row[1])],$rows);
                        $result=$apply(['type'=>'book','time_ms'=>$now,'observed_at_ms'=>$book['timestamp']??$now,'symbol'=>$symbol,
                            'bids'=>$levels(array_slice($book['bids'],0,50)),'asks'=>$levels(array_slice($book['asks'],0,50)),'transport'=>$book['transport']]);
                        $trades=$source->trades($symbol,$cursors[$symbol]??$now);
                        usort($trades,static fn($a,$b)=>($a['timestamp']??0)<=>($b['timestamp']??0));
                        foreach($trades as $trade) {
                            if(!is_int($trade['timestamp']??null) || $trade['timestamp']<($cursors[$symbol]??$now) || !in_array($trade['side']??null,['buy','sell'],true)) { continue; }
                            $id=(string)($trade['id']??hash('sha256',json_encode($trade)));
                            if(isset($seen[$symbol][$id])) { continue; }
                            $seen[$symbol][$id]=true;$cursors[$symbol]=$trade['timestamp'];
                            // Late REST history cannot execute an order accepted after the trade happened.
                            $apply(['type'=>'trade','time_ms'=>(int)floor(microtime(true)*1000),'source_time_ms'=>$trade['timestamp'],'symbol'=>$symbol,
                                'price'=>LiveFeed::decimal($trade['price']),'quantity'=>LiveFeed::decimal($trade['amount']),'side'=>$trade['side'],'source_id'=>$id]);
                        }
                        if(count($seen[$symbol]??[])>5000) { $seen[$symbol]=array_slice($seen[$symbol],-2500,null,true); }
                        if($now>=($next[$symbol]??0)) {
                            $spec=$m['intelligence']??throw new RuntimeException('Market needs intelligence subscription, period, family, model_id and source.');
                            $rows=$source->candles($symbol,$spec['period'],(int)($spec['warmup']??500));$closed=[];
                            $lastCandle=$store->run($run)['state']['candles'][$symbol]??[];$lastTime=$lastCandle?end($lastCandle)[0]:-1;
                            foreach($rows as $row) {
                                if($row[0]+$m['period_ms']>$now) { continue; }
                                $row=[(int)$row[0],...array_map([LiveFeed::class,'decimal'],array_slice($row,1,5))];$closed[]=$row;
                                if($row[0]>$lastTime) { $apply(['type'=>'candle','time_ms'=>(int)floor(microtime(true)*1000),'symbol'=>$symbol,'ohlcv'=>$row]); }
                            }
                            if(!$closed) { throw new RuntimeException('No completed source candles.'); }
                            $response=$server->request($spec['subscription'],'replay/infer',['source_exchange'=>$c['source_exchange'],'model_exchange'=>$spec['model_exchange']??$c['execution_exchange'],
                                'period'=>$spec['period'],'model_id'=>$spec['model_id'],'family'=>$spec['family']??'production','intelligence'=>$spec['source']??'current',
                                'decision_at_ms'=>end($closed)[0]+$m['period_ms'],'candles'=>$closed]);
                            $time=(int)floor(microtime(true)*1000);$next[$symbol]=$time+$m['evaluation_ms'];
                            $result=$apply(['type'=>'evaluate','time_ms'=>$time,'symbol'=>$symbol,'action'=>$response['signal']['action'],'expires_at_ms'=>$next[$symbol],'intelligence'=>$response]);
                            $emit('info','paper_evaluated',['symbol'=>$symbol,'signal'=>$response['signal']['action'],...$result]);
                        }
                        $emit('info','paper_market_event',['symbol'=>$symbol,'transport'=>$book['transport'],...$result]);
                    } catch(\Throwable $error) {
                        if($keepRunning!==null && !$keepRunning()) { break 2; }
                        $now=(int)floor(microtime(true)*1000);
                        if(!($gapped[$symbol]??false)) { $apply(['type'=>'gap','time_ms'=>$now,'symbol'=>$symbol,'from_ms'=>$store->run($run)['state']['books'][$symbol]['time_ms']??$now,'reason'=>'live_data_or_inference_unavailable']);$gapped[$symbol]=true; }
                        $emit('warning','paper_paused',['symbol'=>$symbol,'message'=>$error->getMessage()]);
                    }
                }
                usleep(500000);
            }
        } finally { $execution?->close();if($source!==$execution) { $source?->close(); }flock($lock,LOCK_UN);fclose($lock); }
        return Report::build($store->run($run));
    }
}
