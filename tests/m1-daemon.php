<?php
declare(strict_types=1);

require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\Control;
use Trademinator\Client\M1\Daemon;
use Trademinator\Client\M1\Paths;

if (($argv[1] ?? '') === '--daemon-child') {
    $config = Config::load($argv[2]);
    exit((new Daemon($config, null, fn(): array => ['fixture' => true]))->run(30));
}

$passed = 0; $failed = 0; $skipped = 0;
$temporary = rtrim(sys_get_temp_dir(), '/\\').'/tm1-d-'.bin2hex(random_bytes(5));
mkdir($temporary, 0700, true);
$configFile = $temporary.'/config.json';
$configValues = ['data_dir' => $temporary.'/state', 'tick_ms' => 100, 'log_max_bytes' => 4096, 'log_keep' => 2];
Paths::atomicJson($configFile, $configValues);
$config = Config::load($configFile);
$paths = new Paths($config);
$process = null;

function check(bool $condition, string $name): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo 'PASS '.$name.PHP_EOL; }
    else { $failed++; echo 'FAIL '.$name.PHP_EOL; }
}
function rejects(callable $operation): bool { try { $operation(); return false; } catch (Throwable) { return true; } }
function launch(string $configFile, string $temporary) {
    $process = proc_open([PHP_BINARY, __FILE__, '--daemon-child', $configFile],
        [0 => ['pipe', 'r'], 1 => ['file', $temporary.'/child.out', 'a'], 2 => ['file', $temporary.'/child.err', 'a']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot launch daemon test child.'); }
    fclose($pipes[0]); return $process;
}
function waitForControl($process, Config $config): ?array {
    $deadline = microtime(true) + 15;
    do {
        try { return Control::request($config, 'status'); } catch (Throwable) {}
        if (!proc_get_status($process)['running']) { return null; }
        usleep(20000);
    } while (microtime(true) < $deadline);
    return null;
}
function waitForExit($process): bool {
    $deadline = microtime(true) + 5;
    do { if (!proc_get_status($process)['running']) { return true; } usleep(20000); } while (microtime(true) < $deadline);
    return false;
}
function removeTree(string $path): void {
    if (is_dir($path) && !is_link($path)) { foreach (scandir($path) ?: [] as $name) { if ($name !== '.' && $name !== '..') { removeTree($path.'/'.$name); } } rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { unlink($path); }
}

try {
    check($config->tickMs === 100 && $config->dataDir === $temporary.'/state', 'configuration reads and resolves');
    check(rejects(fn() => new Config(['tick_ms' => '100'])), 'configuration rejects wrong types');
    check(rejects(fn() => new Config(['tick_ms' => null])), 'configuration rejects explicit null');
    check(rejects(fn() => new Config(['data_dir' => PHP_OS_FAMILY === 'Windows' ? 'C:/' : '/'])), 'filesystem roots cannot be state directories');
    check(rejects(fn() => new Config(['ipc_port' => 17840])), 'TCP configuration is rejected');
    check(rejects(fn() => new Config(['backup_keep' => 9])), 'fixed backup retention cannot be configured');
    Paths::atomicJson($configFile, ['data_dir' => $temporary.'/other']);
    check(rejects(fn() => $config->reload()), 'live data directory cannot change');
    Paths::atomicJson($configFile, $configValues);
    $paths->ensure();
    $lock = $paths->acquireLock();
    check(rejects(fn() => $paths->acquireLock()), 'exclusive state lock rejects concurrent owner');
    flock($lock, LOCK_UN); fclose($lock);
    $lock = $paths->acquireLock();
    check(is_resource($lock), 'released lock can be reacquired');
    flock($lock, LOCK_UN); fclose($lock);
    if (PHP_OS_FAMILY !== 'Windows') { check((fileperms($paths->dataDir) & 0777) === 0700, 'private state permissions'); }
    $logger = new Daemon($config);
    for ($i = 0; $i < 80; $i++) { $logger->log('info', 'rotation_fixture', ['i' => $i, 'payload' => str_repeat('x', 120)]); }
    $logs = glob($paths->logDir.'/client.jsonl*') ?: [];
    check(count($logs) === 3, 'structured logs rotate with bounded retention');
    $validLogs = true;
    foreach ($logs as $log) { foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) { $row = json_decode($line, true); $validLogs = $validLogs && is_array($row) && isset($row['event'], $row['level'], $row['run_id']); } }
    check($validLogs, 'every retained log record is structured JSON');
    $broken = new Daemon($config, static function (Daemon $daemon, Config $config): void { throw new RuntimeException('intentional fixture initialization failure'); });
    check($broken->run() === 1 && $broken->status()['state'] === 'failed', 'initialization failure is persisted and fails closed');
    $recovery = new Daemon($config, static function (Daemon $daemon, Config $config): void { throw new RuntimeException('intentional fixture recovery stop'); });
    check($recovery->run() === 1 && $recovery->status()['recovered_after_unclean_shutdown'] === true, 'unclean prior state detected after lock recovery');

    $process = launch($configFile, $temporary);
    $status = waitForControl($process, $config);
    if ($status === null) {
        $error = (string) file_get_contents($temporary.'/child.err');
        $permissionBlocked = str_contains($error, 'Operation not permitted') || str_contains($error, 'Permission denied');
        if ($permissionBlocked && in_array('--allow-unavailable-ipc', $argv, true)) {
            $skipped++; echo 'SKIP native IPC lifecycle: environment denied socket/pipe access; rerun without --allow-unavailable-ipc on target.'.PHP_EOL;
        } else { check(false, 'native IPC daemon must start: '.trim($error)); }
    } else {
        check($status['state'] === 'running' && $status['trading_enabled'] === false, 'native IPC status is live and trading disabled');
        check($status['ipc_transport'] === (PHP_OS_FAMILY === 'Windows' ? 'windows_named_pipe' : 'unix_socket'), 'native IPC transport is platform correct');
        $duplicate = launch($configFile, $temporary);
        check(waitForExit($duplicate), 'duplicate daemon exits under the held lock');
        proc_close($duplicate);
        check(Control::request($config, 'status')['run_id'] === $status['run_id'], 'duplicate does not replace the active endpoint');
        Paths::atomicJson($configFile, $configValues + ['log_level' => 'debug']);
        $reloaded = Control::request($config, 'reload');
        check($reloaded['config_generation'] === 2 && $reloaded['config']['log_level'] === 'debug', 'valid configuration reload is applied');
        file_put_contents($configFile, '{broken');
        check(rejects(fn() => Control::request($config, 'reload')), 'invalid reload is rejected by the running daemon');
        $after = Control::request($config, 'status');
        check($after['config_generation'] === 2 && $after['config']['log_level'] === 'debug', 'invalid reload preserves the active configuration');
        Paths::atomicJson($configFile, $configValues);
        Control::request($config, 'stop');
        check(waitForExit($process), 'native IPC stop exits gracefully');
        proc_close($process); $process = null;
        $stopped = json_decode((string) file_get_contents($paths->statusFile), true);
        check($stopped['state'] === 'stopped' && !file_exists($paths->controlFile), 'graceful stop persists status and removes endpoint metadata');
        $process = launch($configFile, $temporary);
        $restarted = waitForControl($process, $config);
        check($restarted !== null && $restarted['run_id'] !== $status['run_id'], 'daemon restart obtains a fresh process and endpoint');
        if ($restarted !== null) {
            proc_terminate($process, 9); waitForExit($process); proc_close($process); $process = null;
            $process = launch($configFile, $temporary);
            $recovered = waitForControl($process, $config);
            check($recovered !== null && $recovered['recovered_after_unclean_shutdown'] === true, 'abrupt termination recovers exclusive lock and stale endpoint');
            if ($recovered !== null) { Control::request($config, 'stop'); waitForExit($process); }
        }
    }
} catch (Throwable $error) { check(false, 'test exception: '.$error->getMessage()); }
finally {
    if (is_resource($process)) { if (proc_get_status($process)['running']) { proc_terminate($process, 9); } proc_close($process); }
    removeTree($temporary);
}
echo json_encode(['suite' => 'm1-daemon', 'passed' => $passed, 'failed' => $failed, 'skipped' => $skipped], JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed === 0 ? 0 : 1);
