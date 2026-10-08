<?php

declare(strict_types=1);

// Fast sample turnover with a fixed retention limit. Real live soaks are separate.
$seconds = $argv[1] ?? '60';
if (!ctype_digit($seconds) || (int) $seconds < 10 || (int) $seconds > 604800) {
    fwrite(STDERR, "Usage: php tools/soak.php SECONDS (10..604800)\n"); exit(1);
}
$root = dirname(__DIR__);
$dir = $root.'/build/soak-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($dir, 0700, true);
$config = $dir.'/config.json';
file_put_contents($config, json_encode(['mode' => 'fixture', 'data_dir' => $dir.'/state', 'ipc_port' => 0,
    'interval_ms' => 10, 'retention' => 1000, 'ws_retry_seconds' => 1], JSON_THROW_ON_ERROR));
$process = proc_open([PHP_BINARY, $root.'/bin/client.php', 'run', '--config='.$config, '--seconds='.$seconds],
    [0 => ['pipe', 'r'], 1 => ['file', $dir.'/run.json', 'w'], 2 => ['file', $dir.'/stderr.log', 'w']], $pipes);
fclose($pipes[0]);
$exit = proc_close($process);
$result = json_decode((string) file_get_contents($dir.'/run.json'), true);
$passed = $exit === 0 && is_array($result) && ($result['samples'] ?? 0) >= (int) $seconds
    && ($result['errors'] ?? 1) === 0 && ($result['rest_samples'] ?? 0) > 0 && ($result['websocket_samples'] ?? 0) > 0
    && ($result['warm_memory_bytes'] ?? 0) > 0 && ($result['growth_after_warmup_bytes'] ?? PHP_INT_MAX) <= 16 * 1024 * 1024;
$report = ['status' => $passed ? 'passed' : 'failed', 'mode' => 'fixture', 'requested_seconds' => (int) $seconds,
    'exit_code' => $exit, 'report' => $result,
    'scope' => 'PHP allocator memory only; live CCXT, process RSS and native builds need separate long-run checks.'];
file_put_contents($dir.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($passed ? 0 : 1);
