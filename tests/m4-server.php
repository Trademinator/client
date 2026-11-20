<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M4\RequestDeferred;
use Trademinator\Client\M4\Server;
use Trademinator\Client\M4\ServerRequestFailed;
use Trademinator\Client\M4\ServerRequestGate;

if(($argv[1]??'')==='reserve') {
    $gate=new ServerRequestGate($argv[2],$argv[3]);
    fread(STDIN,1);
    echo json_encode($gate->reserve(true,(int)$argv[4]),JSON_THROW_ON_ERROR);
    exit(0);
}

$checks=0;
function check(bool $ok,string $message): void {
    global $checks;
    if(!$ok) { throw new RuntimeException($message); }
    $checks++;
}
$dir=sys_get_temp_dir().'/trademinator-m4-server-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
try {
    $identity=hash('sha256','fixture-shared-key');
    $a=new ServerRequestGate($dir.'/runtime',$identity);
    $b=new ServerRequestGate($dir.'/runtime',$identity);
    check($a->reserve(true,100000)===0,'First inference reserves its slot.');
    check($b->reserve(true,100000)===2100,'Another worker sees shared inference pacing.');
    check($b->reserve(false,100000)===600,'Read requests also respect shared general pacing.');
    check($b->reserve(false,100600)===0,'Read requests can proceed between inference slots.');
    check($a->reserve(true,102100)===0,'A deferred attempt did not reserve or extend its slot.');
    $a->cooldown(60000,102100);
    check($b->reserve(false,103100)===59000,'Retry-After cooldown reaches another worker.');
    check($b->reserve(true,104100)===58000,'Repeated deferral does not restart the cooldown.');
    $b->cooldown(1000,104100);
    check($a->reserve(false,105100)===57000,'A shorter cooldown cannot shorten an existing deadline.');
    check($b->reserve(true,162100)===0,'The original cooldown eventually expires.');
    $other=new ServerRequestGate($dir.'/runtime',hash('sha256','another-credential'));
    check($other->reserve(true,100000)===0,'Distinct credential identities have independent M4 gates.');

    $now=1700000000000;
    check(ServerRequestFailed::retryDelayMs('7',$now)===7000,'Retry-After delta seconds are honored.');
    check(ServerRequestFailed::retryDelayMs(gmdate(DATE_RFC7231,intdiv($now+90000,1000)),$now)===90000,'Retry-After HTTP dates are honored.');
    check(ServerRequestFailed::retryDelayMs(null,$now)===60000,'Missing Retry-After gets a conservative retry delay.');
    check(ServerRequestFailed::retryDelayMs('not a date',$now)===60000,'Malformed Retry-After cannot cause hot retries.');
    check(ServerRequestFailed::retryDelayMs('0',$now)===1000,'Zero retry delay still avoids an immediate retry loop.');
    foreach([0,429,500,503,599] as $status) { check((new ServerRequestFailed($status))->isTransient(),'Transient HTTP status '.$status.' is classified.'); }
    foreach([400,401,403,404,422] as $status) { check(!(new ServerRequestFailed($status))->isTransient(),'Permanent HTTP status '.$status.' is not classified as transient.'); }

    putenv('M4_SERVER_TEST_KEY=fixture-server-key');
    $config=new Config(['data_dir'=>$dir.'/server','server_url'=>'https://example.invalid','server_api_key_env'=>'M4_SERVER_TEST_KEY']);
    $calls=[];
    $fixture=new Server($config,static function(...$args)use(&$calls):array { $calls[]=$args;return ['api_version'=>1]; });
    check($fixture->request('','markets',null,[],true)===['api_version'=>1] && count($calls)===1 && count($calls[0])===4,'Existing injected transport keeps its four arguments and no-network behavior.');

    // A known shared cooldown must stop request() before any cURL call.
    $gate=new ServerRequestGate($config->dataDir.'/runtime',hash('sha256',$config->serverUrl."\0fixture-server-key"));
    $gate->cooldown(60000);
    $real=new Server($config);
    try { $real->request('','markets');check(false,'Shared cooldown must defer discovery.'); }
    catch(RequestDeferred $e) { check($e->retryAfterMs>55000 && $e->retryAfterMs<=60000,'Discovery observes cooldown without sleeping through it.'); }
    try { $real->request('00000000-0000-4000-8000-000000000001','replay/infer',[],[],true);check(false,'Shared cooldown must defer live inference.'); }
    catch(RequestDeferred $e) { check($e->retryAfterMs>55000 && $e->retryAfterMs<=60000,'Live inference deferral happens before a network request.'); }

    $shared=hash('sha256','concurrent-workers');$children=[];
    for($i=0;$i<2;$i++) {
        $p=proc_open([PHP_BINARY,__FILE__,'reserve',$dir.'/runtime',$shared,'200000'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p)) { throw new RuntimeException('Cannot start pacing concurrency fixture.'); }
        $children[]=[$p,$pipes];
    }
    foreach($children as [$p,$pipes]) { fwrite($pipes[0],"g");fclose($pipes[0]); }
    $delays=[];
    foreach($children as [$p,$pipes]) {
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($p)!==0) { throw new RuntimeException('Pacing worker failed: '.$err); }
        $delays[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }
    sort($delays);
    check($delays===[0,2100],'Concurrent processes cannot both reserve the same inference slot.');
    foreach(glob($dir.'/runtime/*.json') as $path) {
        if(PHP_OS_FAMILY!=='Windows') { check((fileperms($path)&0077)===0,'Pacing state is private.'); }
        check(!str_contains(file_get_contents($path),'fixture-shared-key'),'Pacing state contains no credential.');
    }
    echo json_encode(['suite'=>'m4-server-pacing','status'=>'passed','checks'=>$checks,'network_requests'=>0,'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    putenv('M4_SERVER_TEST_KEY');
    $paths=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($paths as $path) { $path->isDir()?rmdir($path->getPathname()):unlink($path->getPathname()); }
    rmdir($dir);
}
