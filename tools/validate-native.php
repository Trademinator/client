<?php

declare(strict_types=1);

// This gate invokes a real compiler and executable. Missing tools never pass.
$root = dirname(__DIR__);
chdir($root);
$out = $root.'/build/validation';
if (!is_dir($out)) { mkdir($out, 0700, true); }
$compiler = getenv('TPC_BIN') ?: 'tpc';
// Composer's PHP launcher must use the same PHP CLI as this validator.
$compilerCommand = str_ends_with(strtolower($compiler), '.php') ? [PHP_BINARY, $compiler] : [$compiler];
$report = ['schema' => 1, 'platform' => PHP_OS_FAMILY, 'php' => PHP_VERSION,
    'checked_at' => gmdate('c'), 'status' => 'blocked', 'checks' => [],
    'note' => 'Runtime embedding, shared-math AOT, and client AOT are separate gates. Inspect compiler logs for fallback.'];

function execute(array $command, string $log, ?string $workingDirectory = null): int
{
    $launchWarning = null;
    set_error_handler(static function (int $severity, string $message) use (&$launchWarning): bool {
        $launchWarning = $message;
        return true;
    });
    try {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes,
            $workingDirectory, null, ['bypass_shell' => true]);
    } finally {
        restore_error_handler();
    }
    if (!is_resource($process)) {
        $details = $launchWarning ?? 'No PHP warning was reported.';
        file_put_contents($log, "Process could not be started: ".$details.PHP_EOL
            .'Command: '.json_encode($command, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL, FILE_APPEND);
        return 127;
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 600;
    do {
        $state = proc_get_status($process);
        if (!$state['running']) { $exit = $state['exitcode']; proc_close($process); return $exit; }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            usleep(250000);
            if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
            proc_close($process); file_put_contents($log, "\nValidation process exceeded 600 seconds.\n", FILE_APPEND); return 124;
        }
        usleep(100000);
    } while (true);
}

$compilerReady = execute([...$compilerCommand, '--version'], $out.'/compiler.log');
$report['checks']['compiler'] = ['exit_code' => $compilerReady];
if ($compilerReady === 0) {
    $report['compiler_version'] = trim((string) file_get_contents($out.'/compiler.log'));
    $report['status'] = 'passed';
    $extension = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
    // Run from a different cwd; full source isolation remains an explicit manual gate.
    $fixtureConfig = $out.'/fixture.json';
    file_put_contents($fixtureConfig, json_encode(['mode' => 'fixture', 'data_dir' => $out.'/state', 'ipc_port' => 0,
        'interval_ms' => 20, 'ws_retry_seconds' => 1], JSON_THROW_ON_ERROR));
    foreach (['runtime' => 'project.yml', 'math' => 'project-math.yml', 'client-aot' => 'project-client-aot.yml'] as $name => $project) {
        echo 'Building '.$name.PHP_EOL;
        $binary = $out.'/probe-'.$name.$extension;
        $buildCode = execute([...$compilerCommand, $project, '-o', $binary, '-O2', '--no-progress', '--no-color'], $out.'/'.$name.'-build.log');
        $runCode = null;
        if ($buildCode === 0 && is_file($binary)) {
            $runCommand = $name === 'math' ? [$binary] : [$binary, 'run', '--config='.$fixtureConfig, '--seconds=6'];
            $runCode = execute($runCommand, $out.'/'.$name.'-run.log', $out);
            if ($runCode === 0) {
                $payload = json_decode(trim((string) file_get_contents($out.'/'.$name.'-run.log')), true);
                $valid = is_array($payload) && ($name === 'math' ? (($payload['status'] ?? null) === 'passed')
                    : (($payload['state'] ?? null) === 'stopped' && ($payload['samples'] ?? 0) > 0
                    && ($payload['rest_samples'] ?? 0) > 0 && ($payload['websocket_samples'] ?? 0) > 0));
                if (!$valid) { $runCode = 1; }
            }
        }
        $report['checks'][$name] = ['build_exit' => $buildCode, 'run_exit' => $runCode,
            'status' => $buildCode === 0 && $runCode === 0 ? 'passed' : 'failed'];
        if ($report['checks'][$name]['status'] !== 'passed') { $report['status'] = 'failed'; }
    }
} else {
    $report['reason'] = 'TypePHP could not start. See compiler.log for the launch error; TPC_BIN must be one native executable or PHP script path.';
}
file_put_contents($out.'/native-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['status'] === 'passed' ? 0 : ($report['status'] === 'blocked' ? 2 : 1));
