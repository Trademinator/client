<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\Paths;
use Trademinator\Client\M4\Runtime;
use Trademinator\Client\M4\Worker;
use Trademinator\Client\M4\WorkerLease;

// Invoked only by this test's supervisor; never opens a network connection.
if (($argv[1] ?? '') === '--fixture') {
    $config = Config::load($argv[2]);
    $file = dirname($argv[2]).'/status.json';
    $mode = getenv('M4_SUPERVISION_FIXTURE_MODE');
    $status = ['state' => 'stopped', 'run_id' => 'automatic', 'events' => [],
        'updated_at_ms' => (int) floor(microtime(true) * 1000)];
    if ($mode === 'expired') {
        $status['stop_reason'] = 'supervisor_heartbeat_expired';
        $status['lease_age_seconds'] = WorkerLease::TIMEOUT_SECONDS;
    } elseif ($mode === 'unexpected') {
        // A zero exit code without a recognized stop reason remains a real error.
        $status['error'] = 'Fixture worker stopped unexpectedly';
    }
    Paths::atomicJson($file, $status);
    exit(0);
}

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($label); }
    $checks++;
    echo 'PASS '.$label.PHP_EOL;
}
function awaitWorker(Runtime $runtime, string $target, float $seconds = 3): bool {
    $deadline = microtime(true) + $seconds;
    do {
        $runtime->tick();
        if ($runtime->status()['state'] === $target) { return true; }
        usleep(25000);
    } while (microtime(true) < $deadline);
    return false;
}

$dir = sys_get_temp_dir().'/m4-supervision-'.bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$oldKey = getenv('M4_SUPERVISION_TEST_KEY');
$oldFixture = getenv('M4_SUPERVISION_FIXTURE_MODE');
putenv('M4_SUPERVISION_TEST_KEY=fixture-key');
$supervisor = null;
try {
    $lease = $dir.'/lease';
    check(WorkerLease::inspect($lease)['reason'] === 'supervisor_lease_removed', 'Absent worker lease is reported explicitly');
    file_put_contents($lease, '');
    touch($lease, time() - WorkerLease::TIMEOUT_SECONDS + 3);
    check(WorkerLease::inspect($lease)['valid'], 'A short supervisor delay does not expire a paper worker');
    touch($lease, time() - WorkerLease::TIMEOUT_SECONDS - 1);
    $stale = WorkerLease::inspect($lease);
    check(!$stale['valid'] && $stale['reason'] === 'supervisor_heartbeat_expired'
        && $stale['age_seconds'] >= WorkerLease::TIMEOUT_SECONDS, 'Expired worker lease is detected with its age');
    $guard = new WorkerLease($lease);
    check(!$guard->active(), 'A worker stops once its lease has expired');
    touch($lease);
    check(!$guard->active() && $guard->state()['reason'] === 'supervisor_heartbeat_expired',
        'Renewing an already-expired lease cannot silently revive the worker');
    touch($lease, time() - WorkerLease::TIMEOUT_SECONDS - 1);

    $settings = ['data_dir' => $dir, 'server_api_key_env' => 'M4_SUPERVISION_TEST_KEY',
        'paper_trading' => ['enabled' => true]];
    $snapshot = $dir.'/worker-config.json';
    Paths::atomicJson($snapshot, $settings);
    $result = Worker::run($snapshot);
    $state = json_decode((string) file_get_contents($dir.'/status.json'), true, 64, JSON_THROW_ON_ERROR);
    check($result === 0 && $state['state'] === 'stopped'
        && $state['stop_reason'] === 'supervisor_heartbeat_expired',
        'Worker publishes its lease-expiry stop reason before exiting cleanly');

    $log = [];
    putenv('M4_SUPERVISION_FIXTURE_MODE=expired');
    $supervisor = new Runtime(new Config($settings),
        static function (string $level, string $event, array $context, string $message) use (&$log): void {
            $log[] = ['level' => $level, 'event' => $event, 'context' => $context];
        }, [PHP_BINARY, __FILE__, '--fixture']);
    $supervisor->tick();
    check(awaitWorker($supervisor, 'waiting_for_markets'), 'Supervisor schedules recovery from an expired lease');
    $names = array_column($log, 'event');
    check(in_array('paper_worker_lease_expired', $names, true)
        && !in_array('paper_worker_failed', $names, true),
        'An explained lease expiry is a warning, not a worker crash');
    check(($supervisor->status()['live_order_execution'] ?? null) === false,
        'Recovery cannot activate real order execution');
    $supervisor->close();
    $supervisor = null;

    $log = [];
    putenv('M4_SUPERVISION_FIXTURE_MODE=unexpected');
    $supervisor = new Runtime(new Config($settings),
        static function (string $level, string $event, array $context, string $message) use (&$log): void {
            $log[] = ['level' => $level, 'event' => $event, 'context' => $context];
        }, [PHP_BINARY, __FILE__, '--fixture']);
    $supervisor->tick();
    $deadline = microtime(true) + 3;
    do {
        $supervisor->tick();
        if (in_array('paper_worker_failed', array_column($log, 'event'), true)) { break; }
        usleep(25000);
    } while (microtime(true) < $deadline);
    check(in_array('paper_worker_failed', array_column($log, 'event'), true),
        'An unexplained zero-code worker exit remains a failure');
    check(!in_array('paper_worker_lease_expired', array_column($log, 'event'), true),
        'Unexpected exit cannot masquerade as heartbeat recovery');
    $supervisor->close();
    $supervisor = null;

    echo json_encode(['suite' => 'm4-worker-supervision', 'checks' => $checks,
        'status' => 'passed', 'network_requests' => 0, 'exchange_orders_submitted' => 0], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    $supervisor?->close();
    if ($oldKey === false) { putenv('M4_SUPERVISION_TEST_KEY'); }
    else { putenv('M4_SUPERVISION_TEST_KEY='.$oldKey); }
    if ($oldFixture === false) { putenv('M4_SUPERVISION_FIXTURE_MODE'); }
    else { putenv('M4_SUPERVISION_FIXTURE_MODE='.$oldFixture); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); } }
    rmdir($dir);
}
