<?php
declare(strict_types=1);

require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Backup;

function simulationCommand(array $arguments): array
{
    $process = proc_open(array_merge([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__).'/tools/m1-simulate.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) { throw new RuntimeException('Could not launch simulator.'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$root = sys_get_temp_dir().'/trademinator-m1-simulation-'.bin2hex(random_bytes(6));
$checks = 0;
try {
    [$exit, $stdout, $stderr] = simulationCommand(['--data-dir='.$root, '--orders=120', '--scenario=all']);
    if ($exit !== 0) { throw new RuntimeException('Simulator failed: '.$stdout.' '.$stderr); }
    $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    foreach (['status' => 'passed', 'mode' => 'synthetic', 'network_requests' => 0, 'exchange_orders_submitted' => 0] as $key => $value) {
        if (($report[$key] ?? null) !== $value) { throw new RuntimeException('Unexpected report field: '.$key); }
        $checks++;
    }
    foreach (['completed_orders' => 120, 'order_observations' => 240, 'round_trips' => 60, 'partial_allocations' => 120] as $key => $value) {
        if (($report['workload'][$key] ?? null) !== $value) { throw new RuntimeException('Unexpected workload count: '.$key); }
        $checks++;
    }
    if (bccomp($report['workload']['expected_final_usd'], '1001186.8', 24) !== 0) { throw new RuntimeException('Independent wallet arithmetic failed.'); }
    $checks++;
    $saved = json_decode((string) file_get_contents($root.'/simulation-report.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($saved !== $report) { throw new RuntimeException('Saved report differs from command output.'); }
    $checks++;
    $hash = hash_file('sha256', $root.'/load/client.sqlite');
    [$exit, $stdout] = simulationCommand(['--data-dir='.$root, '--orders=120']);
    if ($exit === 0 || hash_file('sha256', $root.'/load/client.sqlite') !== $hash) {
        throw new RuntimeException('An existing destination was accepted or changed.');
    }
    $checks++;
    [$exit] = simulationCommand(['--orders=120']);
    if ($exit !== 2) { throw new RuntimeException('Missing explicit destination was accepted.'); }
    $checks++;
    [$exit] = simulationCommand(['--data-dir='.$root.'-odd', '--orders=121']);
    if ($exit !== 2 || file_exists($root.'-odd')) { throw new RuntimeException('Invalid order count touched a directory.'); }
    $checks++;
    echo json_encode(['suite' => 'm1-simulation', 'status' => 'passed', 'checks' => $checks,
        'fixture_checks' => count($report['checks'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\nFixture directory: {$root}\n");
    exit(1);
}
Backup::removeTree($root);
