<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M4\{Engine, Settings, Store};

function contentionConfig(): array
{
    return Settings::validate([
        'account'=>'contention-fixture', 'source_exchange'=>'bitso', 'execution_exchange'=>'bitso',
        'reporting_currency'=>'USD', 'balances'=>['USD'=>'10000','XRP'=>'0'],
        'markets'=>['XRP/USD'=>['tick_size'=>'0.01','amount_step'=>'0.01','minimum_amount'=>'0.01',
            'minimum_cost'=>'1','period_ms'=>1000,'evaluation_ms'=>1000]],
    ]);
}
function contentionEvent(string $id, int $number): array
{
    return ['id'=>$id.'-'.$number,'type'=>'trade','time_ms'=>$number+2,'source_time_ms'=>$number+2,
        'symbol'=>'XRP/USD','price'=>'2','quantity'=>'0.01','side'=>'sell'];
}
if (($argv[1]??'')==='--slow-commit') {
    $directory=$argv[2];$role=$argv[3];$error=null;$elapsed=0.0;
    try {
        $store=new Store($directory);$db=(new ReflectionProperty(Store::class,'db'))->getValue($store);
        $db->sqliteCreateFunction('fixture_slow_commit',static function()use($directory):int {
            file_put_contents($directory.'/slow-held','held');usleep(6000000);return 0;
        },0);
        file_put_contents($directory.'/ready-'.$role,'ready');$deadline=microtime(true)+15;
        while(!is_file($directory.'/slow-start')||($role==='slow-waiter'&&!is_file($directory.'/slow-held'))) {
            if(microtime(true)>=$deadline){throw new RuntimeException('Slow-commit barrier timed out.');}usleep(5000);
        }
        $event=['id'=>'captured-'.$role,'type'=>'clock','time_ms'=>1];$before=hrtime(true);
        $store->apply($role,$event);$elapsed=(hrtime(true)-$before)/1000000000;
    } catch(Throwable $failure) {$error=$failure->getMessage();}
    echo json_encode(['role'=>$role,'write_seconds'=>round($elapsed,6),'error'=>$error],JSON_THROW_ON_ERROR).PHP_EOL;
    exit($error===null?0:1);
}
function contentionCollect(array &$workers, float $deadline): array
{
    $results=[];
    while($workers) {
        foreach($workers as $role=>&$worker) {
            $worker['stdout'].=stream_get_contents($worker['out']);$worker['stderr'].=stream_get_contents($worker['err']);
            $status=proc_get_status($worker['process']);
            if(!$status['running']) {
                $worker['stdout'].=stream_get_contents($worker['out']);$worker['stderr'].=stream_get_contents($worker['err']);
                fclose($worker['out']);fclose($worker['err']);$closed=proc_close($worker['process']);
                $result=json_decode(trim($worker['stdout']),true,512,JSON_THROW_ON_ERROR);
                $result['exit_code']=$status['exitcode']>=0?$status['exitcode']:$closed;$result['stderr']=$worker['stderr'];
                $results[$role]=$result;unset($workers[$role]);
            }
        }unset($worker);
        if($workers&&microtime(true)>=$deadline){throw new RuntimeException('A writer exceeded the bounded contention test deadline.');}
        if($workers){usleep(10000);}
    }
    return $results;
}
function contentionStart(string $mode,string $directory,string $role): array
{
    $process=proc_open([PHP_BINARY,'-d','memory_limit=256M',__FILE__,$mode,$directory,$role],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)){throw new RuntimeException('Cannot start contention writer.');}
    fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    return ['process'=>$process,'out'=>$pipes[1],'err'=>$pipes[2],'stdout'=>'','stderr'=>''];
}
if (($argv[1]??'')==='--writer') {
    $directory=$argv[2];$role=$argv[3];$seconds=12;$count=0;$maximum=0.0;$buckets=[0,0,0,0];$error=null;
    try {
        $store=new Store($directory);file_put_contents($directory.'/ready-'.$role,'ready');
        $deadline=microtime(true)+15;
        while(!is_file($directory.'/start')) {
            if(microtime(true)>=$deadline){throw new RuntimeException('Contention barrier timed out.');}usleep(5000);
        }
        $started=hrtime(true);$end=$started+$seconds*1000000000;
        while(hrtime(true)<$end) {
            $before=hrtime(true);$number=$count+1;
            if($role==='lifecycle') {
                // Discovery/report processes also open the schema while live writers are active.
                $metadata=new Store($directory);$id='metadata-'.$number;
                $metadata->ensure($id,contentionConfig());
                $metadata->resolve($id,static fn(array $config):array=>$config);
                $metadata->create('created-'.$number,contentionConfig());
                unset($metadata);
            } else {
                $store->apply($role,contentionEvent($role,$number));
            }
            $duration=(hrtime(true)-$before)/1000000000;$maximum=max($maximum,$duration);$count++;
            $bucket=(int)floor((hrtime(true)-$started)/3000000000);
            if($bucket<4){$buckets[$bucket]++;}
            if($role==='periodic'){usleep(20000);}elseif($role==='lifecycle'){usleep(100000);}
        }
    } catch(Throwable $failure) {$error=$failure->getMessage();}
    echo json_encode(['role'=>$role,'commits'=>$count,'progress_by_3_seconds'=>$buckets,
        'max_write_seconds'=>round($maximum,6),'error'=>$error],JSON_THROW_ON_ERROR).PHP_EOL;
    exit($error===null?0:1);
}

$directory=sys_get_temp_dir().'/m4-contention-'.bin2hex(random_bytes(6));$workers=[];$results=[];$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {
    if(!$ok){throw new RuntimeException($message);}echo 'PASS '.$message.PHP_EOL;$checks++;
};
try {
    $store=new Store($directory);$config=contentionConfig();
    foreach(['slow-holder','slow-waiter'] as $role){$store->create($role,$config);}
    $fixtureDb=(new ReflectionProperty(Store::class,'db'))->getValue($store);
    $fixtureDb->sqliteCreateFunction('fixture_slow_commit',static fn():int=>0,0);
    $fixtureDb->exec("CREATE TRIGGER fixture_slow_insert BEFORE INSERT ON events WHEN NEW.run_id='slow-holder' BEGIN SELECT fixture_slow_commit(); END");
    foreach(['slow-holder','slow-waiter'] as $role){$workers[$role]=contentionStart('--slow-commit',$directory,$role);}
    $deadline=microtime(true)+15;
    while(count(glob($directory.'/ready-slow-*'))!==2) {
        if(microtime(true)>=$deadline){throw new RuntimeException('Slow writers did not reach their barrier.');}usleep(5000);
    }
    file_put_contents($directory.'/slow-start','start');$slowResults=contentionCollect($workers,microtime(true)+15);
    echo json_encode(['slow_commit_results'=>$slowResults],JSON_THROW_ON_ERROR).PHP_EOL;
    foreach($slowResults as $role=>$result) {
        $check($result['exit_code']===0&&$result['error']===null&&$result['stderr']==='',
            $role.' commits despite another cooperative transaction exceeding SQLite busy_timeout');
        $event=['id'=>'captured-'.$role,'type'=>'clock','time_ms'=>1];
        $check(iterator_to_array($store->events($role))===[$event]&&$store->run($role)['state']['sequence']===1,
            $role.' commits its originally captured event exactly once');
    }
    $fixtureDb->exec('DROP TRIGGER fixture_slow_insert');
    foreach(['hot-a','hot-b','periodic'] as $role) {
        $store->create($role,$config);
        $store->apply($role,['id'=>'seed-book','type'=>'book','time_ms'=>1,'symbol'=>'XRP/USD',
            'bids'=>[['1.99','1000']],'asks'=>[['2','1000']]]);
        if($role==='periodic') {
            $store->apply($role,['id'=>'seed-intelligence','type'=>'evaluate','time_ms'=>2,'symbol'=>'XRP/USD',
                'action'=>'hold','expires_at_ms'=>1002,'intelligence'=>['fixture_provenance'=>str_repeat('x',100000)]]);
        }
    }
    $check(strlen(json_encode($store->run('periodic')['state'],JSON_THROW_ON_ERROR))>100000,
        'Periodic writer has a retained state larger than 100 KB');
    foreach(['hot-a','hot-b','periodic','lifecycle'] as $role) {
        $workers[$role]=contentionStart('--writer',$directory,$role);
    }
    $deadline=microtime(true)+15;
    while(count(glob($directory.'/ready-hot-*'))+count(glob($directory.'/ready-periodic'))+count(glob($directory.'/ready-lifecycle'))!==4) {
        if(microtime(true)>=$deadline){throw new RuntimeException('Writers did not reach the contention barrier.');}usleep(5000);
    }
    file_put_contents($directory.'/start','start');$deadline=microtime(true)+22;
    $results=contentionCollect($workers,$deadline);
    echo json_encode(['writer_results'=>$results],JSON_THROW_ON_ERROR).PHP_EOL;
    foreach($results as $role=>$result) {
        $check($result['exit_code']===0&&$result['error']===null&&$result['stderr']==='',
            $role.' completes continuous contention without SQLite failures');
        $check(min($result['progress_by_3_seconds'])>0,
            $role.' makes progress in every three-second window while hot writers remain active');
    }
    foreach(['hot-a','hot-b','periodic'] as $role) {
        $saved=$store->run($role);$engine=new Engine($saved['config']);$events=[];
        foreach($store->events($role) as $event){$events[]=$event;$engine->apply($event);}
        $check($engine->state===$saved['state'],$role.' exactly replays to its persisted state');
        $expected=$results[$role]['commits']+($role==='periodic'?2:1);
        $check(count($events)===$expected&&$saved['state']['sequence']===$expected,
            $role.' preserves every event once with contiguous sequence numbers');
        $last=end($events);$check($store->apply($role,$last)===['duplicate'=>true],
            $role.' retry preserves the original event ID and remains idempotent');
    }
    for($i=1;$i<=$results['lifecycle']['commits'];$i++) {
        $saved=$store->run('metadata-'.$i);
        if(($saved['resolved_config']??null)!==$config||!$store->exists('created-'.$i)) {
            throw new RuntimeException('Concurrent ensure/create/resolve metadata was lost.');
        }
    }
    $check(true,'Concurrent schema opening, ensure, create and resolve preserve all metadata');
    $db=new PDO('sqlite:'.$directory.'/paper.sqlite');
    $check($db->query('PRAGMA integrity_check')->fetchColumn()==='ok','Database remains intact after sustained asymmetric contention');
    echo json_encode(['suite'=>'m4-store-contention','checks'=>$checks,'network_requests'=>0,'live_orders_submitted'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach($workers as $worker) {
        if(is_resource($worker['process'])){proc_terminate($worker['process']);}
        foreach(['out','err'] as $key){if(is_resource($worker[$key])){fclose($worker[$key]);}}
        if(is_resource($worker['process'])){proc_close($worker['process']);}
    }
    if(is_dir($directory)) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir()&&!$file->isLink()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}
        rmdir($directory);
    }
}
