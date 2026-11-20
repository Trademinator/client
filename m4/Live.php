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
        $end=$seconds===0?INF:microtime(true)+$seconds;$next=[];$feedNext=[];$failures=[];$fxNext=[];$cursors=[];$seen=[];
        $gapped=$initial['state']['paused']??[];
        $feedFactory??=static fn(string $exchange):object=>new LiveFeed($exchange);
        $emit=static function(string $level,string $name,array $data)use($console,$run):void {
            $record=EventRecord::create($level,$name,$data,$run);
            if($console instanceof ConsoleEvents) { $console->write($record); } else { $console($record); }
        };
        $apply=static function(array $event)use($store,$run,$keepRunning):array {
            if($keepRunning!==null && !$keepRunning()) { throw new RuntimeException('Paper session stopped.'); }
            $event['id']??='live-'.bin2hex(random_bytes(12));return $store->apply($run,$event);
        };
        $applyBatch=static function(array $events)use($store,$run,$keepRunning):array {
            if(!$events) { return []; }
            if($keepRunning!==null && !$keepRunning()) { throw new RuntimeException('Paper session stopped.'); }
            foreach($events as &$event) { $event['id']??='live-'.bin2hex(random_bytes(12)); }unset($event);
            return $store->applyBatch($run,$events);
        };
        try {
            $c = $store->resolve($run, static function (array $settings) use ($server, $keepRunning): array {
                $resolved = IntelligenceSetup::resolve($settings, $server);
                if ($keepRunning !== null && !$keepRunning()) { throw new RuntimeException('Paper session stopped during intelligence discovery.'); }
                return $resolved;
            });
            $execution=$feedFactory($c['execution_exchange']);$source=$c['source_exchange']===$c['execution_exchange']?$execution:$feedFactory($c['source_exchange']);
            foreach($c['markets'] as $s=>$m) {
                $execution->market($s);$source->market($s);
                if($gapped[$s]??false) { $emit('warning','paper_paused',['symbol'=>$s,'message'=>'Saved run remains paused until fresh inference succeeds.']); }
            }
            while(microtime(true)<$end && ($keepRunning===null || $keepRunning())) {
                foreach($c['markets'] as $symbol=>$m) {
                    $stage='feed';
                    try {
                        if($keepRunning!==null && !$keepRunning()) { break 2; }
                        $started=(int)floor(microtime(true)*1000);
                        if($started<($feedNext[$symbol]??0)) { continue; }
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
                        if(!($gapped[$symbol]??false) && isset($last['books'][$symbol]) && $now-$last['books'][$symbol]['time_ms']>$c['stale_ms']) {
                            $apply(['type'=>'gap','time_ms'=>$now,'symbol'=>$symbol,'from_ms'=>$last['books'][$symbol]['time_ms'],'reason'=>'restart_live_history_not_proven_complete']);
                            $gapped[$symbol]=true;$next[$symbol]=$now;
                            $emit('warning','paper_paused',['symbol'=>$symbol,'message'=>'Book history is not proven complete; awaiting fresh inference.']);
                        }
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
                            $stage='inference';
                            $spec=$m['intelligence']??throw new RuntimeException('Market needs intelligence subscription, period, family, model_id and source.');
                            $rows=$source->candles($symbol,$spec['period'],(int)($spec['warmup']??500));$closed=[];$batch=[];
                            $lastCandle=$store->run($run)['state']['candles'][$symbol]??[];$lastTime=$lastCandle?end($lastCandle)[0]:-1;
                            foreach($rows as $row) {
                                if($row[0]+$m['period_ms']>$now) { continue; }
                                $row=[(int)$row[0],...array_map([LiveFeed::class,'decimal'],array_slice($row,1,5))];$closed[]=$row;
                                if($row[0]>$lastTime) {
                                    $batch[]=['id'=>'live-'.bin2hex(random_bytes(12)),'type'=>'candle','time_ms'=>(int)floor(microtime(true)*1000),'symbol'=>$symbol,'ohlcv'=>$row];
                                    if(count($batch)===Store::MAX_BATCH_EVENTS) { $applyBatch($batch);$batch=[]; }
                                }
                            }
                            $applyBatch($batch);
                            if(!$closed) { throw new RuntimeException('No completed source candles.'); }
                            $response=$server->request($spec['subscription'],'replay/infer',['source_exchange'=>$c['source_exchange'],'model_exchange'=>$spec['model_exchange']??$c['execution_exchange'],
                                'period'=>$spec['period'],'model_id'=>$spec['model_id'],'family'=>$spec['family']??'production','intelligence'=>$spec['source']??'current',
                                'decision_at_ms'=>end($closed)[0]+$m['period_ms'],'candles'=>$closed],[],true);
                            $action=$response['signal']['action']??null;
                            if(!is_string($action) || !in_array($action,['buy','sell','hold','hodl'],true)) { throw new RuntimeException('Server inference returned an invalid action.'); }
                            $time=(int)floor(microtime(true)*1000);$next[$symbol]=$time+$m['evaluation_ms'];
                            // Receiving a book alone cannot recover an unresolved inference failure.
                            $decision=['type'=>'evaluate','time_ms'=>$time,'symbol'=>$symbol,'action'=>$action,'expires_at_ms'=>$next[$symbol],'intelligence'=>$response];
                            if($gapped[$symbol]??false) {
                                $result=$applyBatch([['type'=>'resume','time_ms'=>$time,'symbol'=>$symbol],$decision]);$gapped[$symbol]=false;
                            } else { $result=$apply($decision); }
                            $failures[$symbol]=0;$feedNext[$symbol]=0;
                            $emit('info','paper_evaluated',['symbol'=>$symbol,'signal'=>$action,...$result]);
                        }
                        // Leave the worker's last status paused while fresh books continue to be captured.
                        if(!($gapped[$symbol]??false)) { $emit('info','paper_market_event',['symbol'=>$symbol,'transport'=>$book['transport'],...$result]); }
                    } catch(RequestDeferred $deferred) {
                        if($keepRunning!==null && !$keepRunning()) { break 2; }
                        $next[$symbol]=(int)floor(microtime(true)*1000)+max(1,$deferred->retryAfterMs);
                        if(!($gapped[$symbol]??false)) { $emit('info','paper_inference_waiting',['symbol'=>$symbol,'retry_after_ms'=>$deferred->retryAfterMs]); }
                    } catch(\Throwable $error) {
                        if($keepRunning!==null && !$keepRunning()) { break 2; }
                        $now=(int)floor(microtime(true)*1000);
                        $failures[$symbol]=min(5,($failures[$symbol]??0)+1);
                        $retryMs=min(60000,5000*(2**($failures[$symbol]-1)));
                        if($error instanceof ServerRequestFailed) { $retryMs=max($retryMs,$error->retryAfterMs); }
                        $next[$symbol]=$now+$retryMs;
                        if($stage==='feed') { $feedNext[$symbol]=$next[$symbol]; }
                        if(!($gapped[$symbol]??false)) { $apply(['type'=>'gap','time_ms'=>$now,'symbol'=>$symbol,'from_ms'=>$store->run($run)['state']['books'][$symbol]['time_ms']??$now,'reason'=>'live_data_or_inference_unavailable']);$gapped[$symbol]=true; }
                        $emit('warning','paper_paused',['symbol'=>$symbol,'message'=>$error->getMessage(),'retry_after_ms'=>$retryMs]);
                    }
                }
                usleep(500000);
            }
        } finally { $execution?->close();if($source!==$execution) { $source?->close(); }flock($lock,LOCK_UN);fclose($lock); }
        return Report::build($store->run($run));
    }
}
