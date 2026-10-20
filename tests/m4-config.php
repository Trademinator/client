<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\Daemon;
use Trademinator\Client\M4\PaperConfig;
use Trademinator\Client\M4\Runtime;
use Trademinator\Client\M4\Store;
use Trademinator\Client\M4\Worker;

if (in_array($argv[1] ?? '', ['--worker', '--slow-worker'], true)) {
    $slow = $argv[1] === '--slow-worker';
    $feed = new class($slow) {
        public function __construct(private bool $slow) {}
        public function market(string $symbol): array { return ['spot' => true]; }
        public function book(string $symbol): array {
            if ($this->slow) { usleep(3000000); }
            return ['timestamp' => (int) floor(microtime(true) * 1000), 'transport' => 'fixture', 'bids' => [['9', '10']], 'asks' => [['10', '10']]];
        }
        public function trades(string $symbol, ?int $since): array { return []; }
        public function candles(string $symbol, string $period, int $limit): array {
            $at = intdiv((int) floor(microtime(true) * 1000), 900000) * 900000;
            return [[$at - 2700000, '10', '10', '10', '10', '10'], [$at - 1800000, '10', '10', '10', '10', '10'], [$at - 900000, '10', '10', '10', '10', '10']];
        }
        public function close(): void {}
    };
    exit(Worker::run($argv[2], fn() => $feed, static fn() => ['signal' => ['action' => 'buy'], 'provenance' => ['fixture' => true]]));
}

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; echo 'PASS '.$label.PHP_EOL;
}
function rejects(callable $operation, string $text = ''): bool {
    try { $operation(); return false; } catch (Throwable $error) { return $text === '' || str_contains($error->getMessage(), $text); }
}
function command(array $arguments): array {
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/client.php', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), json_decode($output, true), $error];
}
function poll(Runtime $runtime, callable $condition): bool {
    $deadline = microtime(true) + 5;
    do { $runtime->tick(); if ($condition()) { return true; } usleep(20000); } while (microtime(true) < $deadline);
    return false;
}
$dir = sys_get_temp_dir().'/tm4-unified-'.bin2hex(random_bytes(6)); mkdir($dir, 0700, true);
$runtime = $duplicate = $slow = null;
$previous = getenv('TM4_UNIFIED_KEY'); putenv('TM4_UNIFIED_KEY=fixture-only-server-key');
try {
    $example = json_decode(file_get_contents(dirname(__DIR__).'/config.example.json'), true, 32, JSON_THROW_ON_ERROR);
    $config = new Config($example, $dir);
    check($config->paperTrading['enabled'] && !isset($config->paperTrading['markets']) && count($config->exchange['accounts']) === 1, 'Unified template enables automatic discovery with credential references only');
    check(!(new Config())->paperTrading['enabled'], 'Existing configurations without a paper section keep their behavior');
    check(!(new Config(['paper_trading' => ['enabled' => false]]))->paperTrading['enabled'], 'A minimal disabled paper section needs no models or credentials');
    check(rejects(fn() => new Config(['paper_trading' => null]), 'must be an object'), 'Reject null paper section');
    check(rejects(fn() => new Config(['paper_trading' => ['enabled' => 'false']]), 'true or false'), 'Reject nonboolean paper switch');
    check(rejects(fn() => new Config(['paper_trading' => ['enabled' => false, 'typo' => 1]]), 'Unknown'), 'Reject unknown paper switches');
    $paper = ['enabled'=>true,'run_id'=>'trial-01',...json_decode(file_get_contents(dirname(__DIR__).'/config.m4.example.json'),true)];
    $paper['markets']['ATOM/USD']['intelligence'] = ['subscription'=>'REPLACE_WITH_SUBSCRIPTION_UUID','model_id'=>'REPLACE_WITH_MODEL_UUID','period'=>'15m'];
    check(rejects(fn() => new Config(['paper_trading' => $paper]), 'real UUID'), 'Enabling unfilled template fails with an actionable UUID error');
    $paper['markets']['ATOM/USD']['intelligence']['subscription'] = '00000000-0000-4000-8000-000000000001';
    $paper['markets']['ATOM/USD']['intelligence']['model_id'] = '00000000-0000-4000-8000-000000000002';
    $paper['submission_delay_ms'] = 0;
    $values = ['data_dir' => $dir.'/state', 'server_url' => 'https://example.invalid', 'server_api_key_env' => 'TM4_UNIFIED_KEY', 'paper_trading' => $paper];
    $file = $dir.'/config.json'; file_put_contents($file, json_encode($values));
    $config = Config::load($file);
    check($config->paperTrading['enabled'] && $config->paperTrading['run_id'] === 'trial-01', 'Full paper configuration loads in the ordinary Client parser');
    $implicit = $paper; unset($implicit['enabled']);
    check((new Config(['paper_trading' => $implicit]))->paperTrading['enabled'], 'A complete paper section enables paper when its switch is omitted');
    check(!str_contains(json_encode($config->toArray()), 'fixture-only-server-key'), 'Unified config serialization contains credential references only');
    file_put_contents($file, json_encode($values + ['log_level' => 'debug']));
    check($config->reload()->logLevel === 'debug', 'Log settings reload without changing paper state');
    $changed = $values; $changed['paper_trading']['enabled'] = false; file_put_contents($file, json_encode($changed));
    check(rejects(fn() => $config->reload(), 'stop and restart'), 'Paper enable changes require explicit daemon restart');
    file_put_contents($file, json_encode($values));

    [$exit, $result] = command(['paper-create', '--config='.$file]);
    check($exit === 0 && $result['run'] === 'trial-01', 'paper-create uses the unified settings and default run ID');
    [$exit, $report] = command(['paper-report', '--config='.$file]);
    check($exit === 0 && $report['fills'] === 0, 'paper-report needs only the same config file');
    [$exit] = command(['paper-create', '--config='.$file]);
    check($exit !== 0, 'Explicit paper-create still refuses an existing run');
    $store = new Store($config->dataDir.'/paper');
    $settings = PaperConfig::settings($config->paperTrading);
    check($store->ensure('trial-01', array_reverse($settings, true)) === 'resumed', 'Daemon resumes matching settings regardless of JSON key order');
    $before = $store->run('trial-01'); $changed = $settings; $changed['balances']['USD'] = '2000';
    check(rejects(fn() => $store->ensure('trial-01', $changed), 'new paper_trading.run_id') && $store->run('trial-01') === $before, 'Changed settings cannot reset saved funds');
    check($store->ensure('trial-02', $changed) === 'created' && $store->run('trial-01') === $before, 'A different run ID creates isolated starting funds');
    $lock = $store->acquireLiveLock('trial-01');
    check(rejects(fn() => $store->acquireLiveLock('trial-01'), 'already running'), 'Concurrent live sessions cannot drive the same run');
    flock($lock, LOCK_UN); fclose($lock);

    $events = [];
    $log = static function (string $level, string $name, array $context, string $message) use (&$events): void { $events[] = $name; };
    $disabled = new Runtime(new Config(['data_dir' => $dir.'/disabled']), $log); $disabled->tick();
    check($disabled->status()['state'] === 'disabled' && !is_dir($dir.'/disabled'), 'Disabled paper creates no worker or storage and reads no Server key');
    $runtime = new Runtime($config, $log, [PHP_BINARY, __FILE__, '--worker']);
    check(poll($runtime, fn() => $runtime->status()['state'] === 'running'), 'Daemon supervisor starts a live paper worker');
    check(count($store->run('trial-01')['state']['fills']) === 1, 'Worker performs inference and commits a paper fill through the real pipeline');
    check(in_array('paper_resumed', $events, true) && in_array('paper_evaluated', $events, true), 'Paper lifecycle and decisions reach the daemon event sink');
    $duplicate = new Runtime($config, $log, [PHP_BINARY, __FILE__, '--worker']);
    check(poll($duplicate, fn() => $duplicate->status()['state'] === 'failed'), 'A second worker reports the run lock failure');
    check(str_contains($duplicate->status()['error'] ?? '', 'already running'), 'Status exposes an actionable worker failure');
    $duplicate->close();
    $runtime->close(); $saved = $store->run('trial-01'); usleep(100000);
    check($runtime->status()['state'] === 'stopped' && $store->run('trial-01') === $saved, 'Stopping the supervisor stops writes and retains saved state');
    $runtime = new Runtime($config, $log, [PHP_BINARY, __FILE__, '--worker']);
    check(poll($runtime, fn() => $runtime->status()['state'] === 'running'), 'A restarted daemon resumes the same paper run');
    check(count($store->run('trial-01')['state']['fills']) >= count($saved['state']['fills']), 'Restart preserves previously executed paper fills');
    $workerDir = (new ReflectionProperty($runtime, 'directory'))->getValue($runtime);
    $workerProcess = (new ReflectionProperty($runtime, 'process'))->getValue($runtime);
    touch($workerDir.'/lease', time() - 30);
    $deadline = microtime(true) + 3;
    while (proc_get_status($workerProcess)['running'] && microtime(true) < $deadline) { usleep(20000); }
    check(!proc_get_status($workerProcess)['running'], 'Worker exits when its parent heartbeat expires');
    $afterLease = $store->run('trial-01'); usleep(100000);
    check($store->run('trial-01') === $afterLease, 'Expired parent lease leaves no orphan paper writer');
    $runtime->close();
    $slow = new Runtime($config, $log, [PHP_BINARY, __FILE__, '--slow-worker']);
    $start = microtime(true); $slow->tick();
    check(microtime(true) - $start < 1, 'Slow network worker does not block the daemon tick');
    usleep(100000); $start = microtime(true); $slow->close();
    check(microtime(true) - $start < 2, 'Daemon stop is bounded while a network call is in flight');

    // Shutdown hooks execute even if native control sockets are unavailable.
    $shutdown = false;
    $daemon = new Daemon(new Config(['data_dir' => $dir.'/daemon']), static function (): void { throw new RuntimeException('fixture startup failure'); });
    $daemon->setShutdownHandler(static function () use (&$shutdown): void { $shutdown = true; });
    check($daemon->run(1) === 1 && $shutdown, 'Daemon failure closes managed paper resources before recording stopped state');
    check((new Daemon($config))->status()['mode'] === 'paper_trading', 'Daemon status identifies paper mode');
    check(!is_file($config->dataDir.'/client.sqlite'), 'Paper worker never opens or modifies the real account ledger');
    echo json_encode(['suite' => 'm4-unified-config', 'status' => 'passed', 'checks' => $checks, 'network_requests' => 0, 'exchange_orders_submitted' => 0], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    $runtime?->close(); $duplicate?->close(); $slow?->close();
    if ($previous === false) { putenv('TM4_UNIFIED_KEY'); } else { putenv('TM4_UNIFIED_KEY='.$previous); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
    rmdir($dir);
}
