<?php
declare(strict_types=1);

// Isolate test clocks, credentials and fixture state between suites.
$allowed = ['storage', 'api', 'scheduler', 'runtime', 'config'];
$selected = $allowed;
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--suite=')) { fwrite(STDERR, "Usage: php tests/m2.php [--suite=storage,api,scheduler,runtime,config]\n"); exit(2); }
    $selected = explode(',', substr($argument, 8));
}
if ($selected === [] || array_diff($selected, $allowed) !== []) { fwrite(STDERR, "Unknown M2 suite.\n"); exit(2); }
$results = [];
foreach (array_unique($selected) as $suite) {
    echo "Running M2 {$suite} suite\n";
    $start = microtime(true);
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/m2-'.$suite.'.php'], [STDIN, STDOUT, STDERR], $pipes, dirname(__DIR__));
    if (!is_resource($process)) { fwrite(STDERR, "Cannot start M2 suite.\n"); exit(2); }
    $code = proc_close($process);
    $results[$suite] = ['status'=>$code === 0 ? 'passed' : 'failed','exit_code'=>$code,'seconds'=>round(microtime(true)-$start,3)];
}
$failed = array_filter($results, static fn(array $r): bool => $r['exit_code'] !== 0);
echo json_encode(['milestone'=>'M2','status'=>$failed === [] ? 'passed' : 'failed','suites'=>$results], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($failed === [] ? 0 : 1);
