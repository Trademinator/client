<?php

declare(strict_types=1);

// Standalone regression suite: php tests/diagnostics.php
// These tests validate PHP-side diagnostics, not TypePHP compilation or execution.
function diagnosticAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function diagnosticRun(?string $flag, array $arguments, string $scenario = 'arity', bool $captureArgs = true): array
{
    $environment = getenv();
    unset($environment['TRADEMINATOR_M0_DIAGNOSTIC'], $environment['TRADEMINATOR_API_KEY']);
    if ($flag !== null) { $environment['TRADEMINATOR_M0_DIAGNOSTIC'] = $flag; }
    $environment['M0_DIAGNOSTIC_TEST_SCENARIO'] = $scenario;
    $process = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args='.($captureArgs ? '0' : '1'),
        __DIR__.'/fixtures/diagnostic-cli.php', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start diagnostic fixture.'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $report = null;
    foreach (explode("\n", $stderr) as $line) {
        if (str_starts_with($line, 'M0 diagnostic v5: {')) {
            diagnosticAssert($report === null, 'More than one diagnostic report.');
            $report = json_decode(substr($line, strlen('M0 diagnostic v5: ')), true, 32, JSON_THROW_ON_ERROR);
        }
    }
    diagnosticAssert(!str_contains($stdout.$stderr, 'M0_PRIVATE_ARGUMENT_SENTINEL'), 'Trace leaked an argument.');
    diagnosticAssert(!str_contains($stdout.$stderr, 'M0_PRIVATE_MESSAGE_SENTINEL'), 'Dependency message leaked.');
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'report' => $report];
}

function diagnosticFailure(array $result, string $stage): void
{
    diagnosticAssert($result['exit'] === 1, 'Error exit code changed.');
    diagnosticAssert($result['stdout'] === '', 'Diagnostics contaminated stdout.');
    diagnosticAssert(str_starts_with($result['stderr'], 'M0 diagnostic v5: enabled'), 'New binary marker missing.');
    $report = $result['report'];
    diagnosticAssert(is_array($report) && $report['diagnostic_version'] === 5, 'Versioned report missing.');
    diagnosticAssert($report['stage'] === $stage, 'Wrong failure stage.');
    diagnosticAssert($report['line'] > 0 && $report['file'] !== '', 'Exception location missing.');
    foreach ($report['trace'] as $frame) {
        diagnosticAssert(array_keys($frame) === ['file', 'line', 'call'], 'Unexpected trace field, possibly args/object.');
    }
}

$tests = [];
$tests['diagnostics stay disabled without an explicit 1'] = static function (): void {
    foreach ([null, '', '0', 'true'] as $flag) {
        $result = diagnosticRun($flag, ['math']);
        diagnosticAssert($result['exit'] === 1 && $result['stdout'] === '', 'Ordinary failure changed.');
        diagnosticAssert($result['report'] === null && !str_contains($result['stderr'], 'M0 diagnostic'), 'Diagnostics enabled unexpectedly.');
        diagnosticAssert(str_contains($result['stderr'], 'M0 failed: ArgumentCountError.'), 'Generic error missing.');
    }
};
$tests['arity failure includes stage, message and an argument-free trace'] = static function (): void {
    $result = diagnosticRun('1', ['math']);
    diagnosticFailure($result, 'math');
    diagnosticAssert($result['report']['exception'] === 'ArgumentCountError', 'Wrong exception class.');
    diagnosticAssert(str_contains($result['report']['message'], 'strlen()'), 'Actual arity message missing.');
    diagnosticAssert(count($result['report']['trace']) > 0, 'Exception trace missing.');
};
$tests['diagnostics also work when PHP omits captured arguments'] = static function (): void {
    diagnosticFailure(diagnosticRun('1', ['math'], 'arity', false), 'math');
};
$tests['non-arity dependency messages remain omitted'] = static function (): void {
    $result = diagnosticRun('1', ['math'], 'message');
    diagnosticFailure($result, 'math');
    diagnosticAssert($result['report']['message'] === '[omitted]', 'Unexpected dependency message.');
};
$tests['successful math keeps stdout as valid JSON'] = static function (): void {
    $result = diagnosticRun('1', ['math'], 'success');
    diagnosticAssert($result['exit'] === 0 && $result['report'] === null, 'Success became failure.');
    diagnosticAssert(json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR) === ['status' => 'passed'], 'Success payload changed.');
    diagnosticAssert(trim($result['stderr']) === 'M0 diagnostic v5: enabled', 'Success marker missing.');
};
$tests['argument parsing errors do not print command arguments'] = static function (): void {
    diagnosticFailure(diagnosticRun('1', ['run', '--invalid=M0_PRIVATE_ARGUMENT_SENTINEL']), 'arguments');
};
$tests['configuration errors are distinguished from math'] = static function (): void {
    diagnosticFailure(diagnosticRun('1', ['run', '--config='.__FILE__.'/not-a-directory.json']), 'config.load');
};
$tests['invalid limits are distinguished from daemon startup'] = static function (): void {
    diagnosticFailure(diagnosticRun('1', ['run', '--seconds=invalid']), 'run.limits');
};
$tests['run math errors are distinguished from daemon errors'] = static function (): void {
    diagnosticFailure(diagnosticRun('1', ['run', '--seconds=6']), 'run.math');
    diagnosticFailure(diagnosticRun('1', ['run', '--seconds=6'], 'daemon'), 'run.daemon');
};
$tests['long traces are bounded to 32 frames'] = static function (): void {
    $result = diagnosticRun('1', ['math'], 'deep');
    diagnosticFailure($result, 'math');
    diagnosticAssert(count($result['report']['trace']) === 32, 'Trace length is not bounded.');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo 'PASS '.$name.PHP_EOL; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL); }
}
echo count($tests).' diagnostic tests, '.$failed.' failures.'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
