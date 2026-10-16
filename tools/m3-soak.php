<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';
use Trademinator\Client\M1\{Config,Paths,Database,Ledger,HardPoints,EventRecord};
use Trademinator\Client\M3\Runtime;
$options=getopt('', ['config:','data-dir:','seconds:']);
$seconds=$options['seconds']??'86400';
if(!is_string($seconds)||!ctype_digit($seconds)||(int)$seconds<1||(int)$seconds>604800){fwrite(STDERR,"seconds must be 1..604800\n");exit(2);}
$config=Config::load($options['config']??null,$options['data-dir']??null);
if($config->exchange['accounts']===[]){fwrite(STDERR,"Configure at least one existing local exchange account.\n");exit(2);}
$paths=new Paths($config);$lock=$paths->acquireLock();$runtime=null;$stats=['successful_reconciliations'=>0,'failures'=>0,'discrepancies'=>0,'auth_blocks'=>0];$started=microtime(true);$run=bin2hex(random_bytes(12));$events=$config->dataDir.'/m3-soak-'.$run.'.jsonl';$handle=fopen($events,'x');
if($handle===false){throw new RuntimeException('Cannot create soak event evidence.');}chmod($events,0600);
try{
 $db=new Database($config->dataDir.'/client.sqlite');(new HardPoints($db,$config->dataDir))->recover();
 $runtime=new Runtime($db,$config->exchange,null,static function(string $level,string $event,array $context,string $message)use(&$stats,$handle,$run):void{
  if($event==='exchange_sync_completed'){if($context['reconciled']){$stats['successful_reconciliations']++;}else{$stats['discrepancies']++;}}
  if($event==='exchange_sync_failed'){$stats['failures']++;}if($event==='exchange_auth_blocked'){$stats['auth_blocks']++;}
  fwrite($handle,json_encode(EventRecord::create($level,$event,$context,$run,$message),JSON_THROW_ON_ERROR)."\n");fflush($handle);
 },new Trademinator\Client\M3\HardPoints($db,$config->dataDir),$config->dataDir);
 $stop=false;if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);foreach([SIGINT,SIGTERM] as $signal){pcntl_signal($signal,static function(int $signal)use(&$stop):void{$stop=true;});}}
 while(!$stop&&microtime(true)-$started<(int)$seconds){$runtime->tick();usleep($config->tickMs*1000);}
 $status=$runtime->status();$allReconciled=true;$ledger=new Ledger($db);
 foreach($config->exchange['accounts'] as $account){$allReconciled=$allReconciled&&$ledger->accountState($account['account_id'])['reconciled'];}
 $duration=microtime(true)-$started;$complete=$duration>=(int)$seconds;
 $passed=$complete&&$allReconciled&&$stats['successful_reconciliations']>0&&$stats['discrepancies']===0&&$stats['auth_blocks']===0;
 $report=['milestone'=>'M3','mode'=>'read_only_live','status'=>$passed?'passed':'needs_review','run_id'=>$run,'requested_seconds'=>(int)$seconds,'elapsed_seconds'=>round($duration,3),'complete'=>$complete,'stats'=>$stats,'all_accounts_reconciled'=>$allReconciled,'integrity'=>$db->integrityCheck(),'peak_memory_bytes'=>memory_get_peak_usage(true),'exchange_orders_submitted'=>0,'withdrawals_initiated'=>0,'events_file'=>$events,'events_sha256'=>hash_file('sha256',$events)];
 Paths::atomicJson($config->dataDir.'/m3-soak-'.$run.'.json',$report);echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
 $exitCode=$passed?0:1;
}finally{$runtime?->close();fclose($handle);flock($lock,LOCK_UN);fclose($lock);}

exit($exitCode ?? 1);
