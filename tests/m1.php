<?php
declare(strict_types=1);

// Run suites in separate processes: no fixture state or test helpers are shared.
$allowed = ['ledger', 'hardpoints', 'daemon', 'simulation'];
$selected = $allowed;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo "Usage: php tests/m1.php [--suite=ledger,hardpoints,daemon,simulation]\n";
        exit(0);
    }
    if (!str_starts_with($argument, '--suite=')) {
        fwrite(STDERR, "Unknown argument: {$argument}\n"); exit(2);
    }
    $selected = explode(',', substr($argument, 8));
}
if ($selected === [] || array_diff($selected, $allowed) !== []) {
    fwrite(STDERR, "Invalid suite. Available: ".implode(', ', $allowed)."\n"); exit(2);
}
foreach (['pdo_sqlite', 'bcmath'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "M1 tests require PHP extension {$extension}.\n"); exit(2);
    }
}
$results = [];
foreach (array_unique($selected) as $suite) {
    $file = __DIR__.'/m1-'.$suite.'.php';
    if (!is_file($file)) {
        fwrite(STDERR, "Missing suite: {$file}\n"); exit(2);
    }
    echo "Running M1 {$suite} suite\n";
    $start = microtime(true);
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', $file], [STDIN, STDOUT, STDERR], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to start {$suite} suite.\n"); exit(2);
    }
    $exitCode = proc_close($process);
    $results[$suite] = ['status' => $exitCode === 0 ? 'passed' : 'failed', 'exit_code' => $exitCode,
        'seconds' => round(microtime(true) - $start, 3)];
}
$failed = array_filter($results, static fn(array $result): bool => $result['exit_code'] !== 0);
echo json_encode(['milestone' => 'M1', 'status' => $failed === [] ? 'passed' : 'failed', 'suites' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
exit($failed === [] ? 0 : 1);
