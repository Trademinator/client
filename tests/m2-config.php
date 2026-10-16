<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\Daemon;
use Trademinator\Client\M2\Credentials;

$count = 0;
function check(bool $pass, string $message): void { global $count; if (!$pass) { throw new RuntimeException($message); } ++$count; }
function rejects(callable $operation): bool { try { $operation(); return false; } catch (Throwable) { return true; } }
$dir = sys_get_temp_dir().'/tm2-config-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);
$environment = 'TRADEMINATOR_M2_TEST_KEY';
$previous = getenv($environment);
$secret = 'tmk_'.str_repeat('s',43);
try {
    $defaults = new Config(['data_dir'=>$dir]);
    check($defaults->serverSettings()['enabled'] === false, 'M1 configuration keeps networking disabled.');
    check($defaults->checksPerCandle === 3, 'Default checks per candle is three.');
    foreach (['http://example.com', 'https://user:secret@example.com', 'https://example.com/api', 'https://example.com?token=secret', 'https://example.com#secret', "https://example.com\n"] as $url) {
        check(rejects(fn()=>new Config(['server_url'=>$url])), 'Unsafe origin rejected.');
    }
    foreach (['checks_per_candle'=>60,'server_timeout_ms'=>0,'server_min_request_interval_ms'=>500,'server_catalog_interval_ms'=>'1000'] as $key=>$value) {
        check(rejects(fn()=>new Config([$key=>$value])), 'Invalid scheduling value rejected.');
    }
    putenv($environment.'='.$secret);
    $config = new Config(['data_dir'=>$dir,'server_url'=>'https://server.example.com/','server_api_key_env'=>$environment]);
    check($config->serverSettings()['token'] === $secret, 'Environment token reaches runtime.');
    check($config->serverUrl === 'https://server.example.com', 'Origin normalized.');
    check(!str_contains(json_encode($config->toArray()),$secret), 'Status configuration excludes secret.');
    file_put_contents($dir.'/api.key',$secret."\n"); chmod($dir.'/api.key',0600);
    check(Credentials::read($environment,$dir.'/api.key') === $secret, 'Private key file read.');
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($dir.'/api.key',0644);
        check(rejects(fn()=>Credentials::read($environment,$dir.'/api.key')), 'Public key file rejected.');
        chmod($dir.'/api.key',0600);
        symlink($dir.'/api.key',$dir.'/linked.key');
        check(rejects(fn()=>Credentials::read($environment,$dir.'/linked.key')), 'Symlink key file rejected.');
    }
    putenv($environment);
    check(rejects(fn()=>Credentials::read($environment,null)), 'Missing secret rejected without invented fallback.');
    putenv($environment.'=header'."\r\n".'Injected: value');
    check(rejects(fn()=>Credentials::read($environment,null)), 'Header injection rejected.');
    file_put_contents($dir.'/config.json',json_encode(['data_dir'=>$dir,'checks_per_candle'=>2]));
    $reload = Config::load($dir.'/config.json');
    file_put_contents($dir.'/config.json',json_encode(['data_dir'=>$dir,'checks_per_candle'=>4]));
    check($reload->reload()->checksPerCandle === 4, 'Scheduling config reload validated.');
    file_put_contents($dir.'/config.json',json_encode(['data_dir'=>$dir,'checks_per_candle'=>0]));
    check(rejects(fn()=>$reload->reload()) && $reload->checksPerCandle === 2, 'Rejected reload leaves prior config intact.');
    // Exercise live status versus the on-disk crash marker without needing an IPC socket.
    $daemon = new Daemon($defaults);
    mkdir($dir.'/runtime',0700,true);
    $property = new ReflectionProperty($daemon,'componentStatus');
    $property->setValue($daemon,['ready'=>true,'server'=>['catalog'=>['fixture'],'signal'=>['action'=>'buy']]]);
    check(isset($daemon->status()['ledger']['server']['signal']), 'Live status contains operational data.');
    (new ReflectionMethod($daemon,'writeStatus'))->invoke($daemon);
    $saved = json_decode(file_get_contents($dir.'/runtime/status.json'),true);
    check(!isset($saved['ledger']['server']), 'Operational Server data is not persisted in status.json.');
    check(!str_contains(json_encode($saved),$secret), 'Crash marker excludes credentials.');
    if (PHP_OS_FAMILY !== 'Windows') {
        mkdir($dir.'/logs',0700,true);
        symlink($dir.'/config.json',$dir.'/logs/client.jsonl');
        file_put_contents($dir.'/config.json',json_encode(['data_dir'=>$dir,'checks_per_candle'=>2]));
        $currentChecks = 2;
        $reloadDaemon = new Daemon(Config::load($dir.'/config.json'),null,null,function(Config $candidate) use (&$currentChecks): void { $currentChecks=$candidate->checksPerCandle; });
        file_put_contents($dir.'/config.json',json_encode(['data_dir'=>$dir,'checks_per_candle'=>4]));
        $result = (new ReflectionMethod($reloadDaemon,'dispatch'))->invoke($reloadDaemon,'reload',[]);
        check($result['config']['checks_per_candle'] === 4 && $currentChecks === 4 && $reloadDaemon->config()->checksPerCandle === 4,
            'Reporting failure cannot rollback config after runtime commits.');
        check($result['warning'] === 'configuration_applied_reporting_failed','Applied reload reports telemetry failure.');
    }
    echo json_encode(['suite'=>'m2-config','status'=>'passed','assertions'=>$count],JSON_PRETTY_PRINT)."\n";
} finally {
    if ($previous === false) { putenv($environment); } else { putenv($environment.'='.$previous); }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item) { if($item->isDir() && !$item->isLink()) { rmdir($item->getPathname()); } else { unlink($item->getPathname()); } }
    rmdir($dir);
}
