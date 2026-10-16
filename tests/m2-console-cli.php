<?php

declare(strict_types=1);

// Exercise the real CLI boundary, including stdout/stderr separation. Every
// invocation uses an isolated local-core configuration and cannot contact Server.
$root = dirname(__DIR__);
$dir = sys_get_temp_dir().'/tm2-console-cli-'.bin2hex(random_bytes(6));
$count = 0;
$skips = [];

function check(bool $pass, string $message): void
{
    global $count;
    if (!$pass) { throw new RuntimeException($message); }
    ++$count;
}

function removeTree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') { removeTree($path.'/'.$name); }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) { unlink($path); }
}

function cli(array $arguments, ?string $clientRoot = null): array
{
    global $dir, $root;
    $clientRoot ??= $root;
    $tag = bin2hex(random_bytes(6));
    $out = $dir.'/'.$tag.'.stdout';
    $err = $dir.'/'.$tag.'.stderr';
    $command = [PHP_BINARY, '-d', 'memory_limit=512M', $clientRoot.'/bin/client.php', ...$arguments];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $clientRoot, null, ['bypass_shell' => true]);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start the Client CLI fixture.'); }
    fclose($pipes[0]);
    $deadline = microtime(true) + 20;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if ($status['running']) {
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('Client CLI fixture exceeded its 20-second deadline.');
    }
    $code = $status['exitcode'];
    proc_close($process);
    return ['code' => $code, 'out' => (string) file_get_contents($out), 'err' => (string) file_get_contents($err)];
}

function jsonDocument(string $text): array
{
    $value = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($value)) { throw new RuntimeException('Expected one JSON document.'); }
    return $value;
}

function eventRows(string $text): array
{
    if ($text === '' || !str_ends_with($text, "\n")) { throw new RuntimeException('JSONL output must contain complete newline-terminated events.'); }
    return array_map('jsonDocument', explode("\n", substr($text, 0, -1)));
}

if (!mkdir($dir, 0700, true)) { throw new RuntimeException('Cannot create isolated CLI fixture directory.'); }
try {
    $config = $dir.'/config.json';
    file_put_contents($config, json_encode(['server_url' => '', 'data_dir' => $dir.'/state'], JSON_THROW_ON_ERROR));
    chmod($config, 0600);
    $local = ['--config='.$config, '--data-dir='.$dir.'/state'];

    $result = cli(['run', ...$local, '--seconds=invalid', '--output=json', '--color=always']);
    $rows = eventRows($result['out']);
    check($result['code'] === 1 && $result['err'] === '', 'JSON startup failure exits 1 without a second stderr error.');
    check(count($rows) === 1 && $rows[0]['event'] === 'startup_failed' && $rows[0]['level'] === 'error', 'Startup failure is exactly one structured event.');
    check($rows[0]['schema_version'] === 1 && $rows[0]['context']['error_type'] === RuntimeException::class, 'Startup event retains schema and diagnostic type.');
    check(str_contains($rows[0]['context']['message'], 'seconds must be an integer'), 'JSON startup event describes the actual validation failure.');
    check(!str_contains($result['out'], "\033"), 'JSON event output never contains ANSI, even with color=always.');
    check(!is_file($dir.'/state/client.sqlite'), 'Invalid startup duration does not create a database.');

    $result = cli(['run', ...$local, '--seconds=invalid', '--color=always']);
    $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $result['out']);
    $lines = explode("\n", rtrim($plain, "\n"));
    check($result['code'] === 1 && $result['err'] === '', 'Human startup failure exits 1 and stays on the selected output stream.');
    check(str_contains($result['out'], "\033[") && count($lines) === 2, 'Forced human color produces exactly two event lines.');
    check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z ERROR  \[STARTUP_FAILED\] Client could not start\.$/D', $lines[0]) === 1, 'Human event header starts with timestamp and severity after removing ANSI.');
    check(str_starts_with($lines[1], '        ') && str_contains(jsonDocument(trim($lines[1]))['message'], 'seconds must be an integer'), 'Human second line is literal diagnostic JSON.');

    foreach ([[], ['--color=never'], ['--color=auto']] as $presentation) {
        $result = cli(['run', ...$local, '--seconds=invalid', ...$presentation]);
        check($result['code'] === 1 && $result['err'] === '' && !str_contains($result['out'], "\033"), 'Redirected default, never, and automatic output remain plain.');
    }
    foreach ([['--unknown'], ['--verbose', '-v'], ['--output=json', '--output=human'], ['--output=xml'], ['--color=sometimes']] as $flags) {
        $result = cli(['run', ...$local, ...$flags]);
        $error = jsonDocument($result['err']);
        check($result['code'] === 1 && $result['out'] === '' && $error['ok'] === false, 'Invalid or duplicate presentation options fail cleanly before event startup.');
    }

    $result = cli(['version']);
    check($result['code'] === 0 && $result['err'] === '' && jsonDocument($result['out']) === ['milestone' => 'M3', 'version' => '3.0.0-rc1'], 'Version remains a plain JSON command with the new release number.');
    $result = cli(['doctor']);
    $doctor = jsonDocument($result['out']);
    check($result['code'] === 0 && $result['err'] === '' && $doctor['status'] === 'ready', 'Doctor reports this fully provisioned test environment ready.');
    check($doctor['console_dependencies'] === true && $doctor['console_dependency_hint'] === null, 'Doctor checks installed console dependencies.');
    check($doctor['extensions']['gmp'] === true && $doctor['extensions']['mbstring'] === true && !str_contains($result['out'], "\033"), 'Doctor reports Composer extension prerequisites without decoration.');

    // Reproduce a patch applied before composer install without touching the
    // real vendor directory: only the unchanged CLI loader files are copied.
    $bare = $dir.'/without-vendor';
    mkdir($bare.'/bin', 0700, true);
    mkdir($bare.'/m1', 0700, true);
    foreach (['bin/client.php', 'm1/bootstrap.php', 'm1/Application.php'] as $file) { copy($root.'/'.$file, $bare.'/'.$file); }
    $result = cli(['doctor'], $bare);
    $doctor = jsonDocument($result['out']);
    check($result['code'] === 1 && $result['err'] === '' && $doctor['status'] === 'missing_requirements', 'Doctor fails readiness when the dependency installation is missing.');
    check($doctor['console_dependencies'] === false && str_contains($doctor['console_dependency_hint'], 'composer install'), 'Missing dependencies have an actionable installation hint.');
    $result = cli(['run', ...$local, '--seconds=invalid', '--output=json'], $bare);
    check($result['code'] === 1 && $result['out'] === '' && str_contains(jsonDocument($result['err'])['error'], 'composer install'), 'Missing dependencies produce clean bootstrap JSON on stderr before the console exists.');

    foreach (['status', 'markets', 'subscriptions', 'accounts'] as $command) {
        $result = cli([$command, ...$local]);
        $error = jsonDocument($result['err']);
        check($result['code'] === 1 && $result['out'] === '' && $error['ok'] === false && str_contains($error['error'], 'no control endpoint'), 'Read-only control commands preserve undecorated JSON errors without a daemon.');
    }
    $result = cli(['markets', ...$local, '--color=always']);
    check($result['code'] === 1 && $result['out'] === '' && str_contains(jsonDocument($result['err'])['error'], 'run only'), 'Presentation options do not change control-command output.');

    // This additional lifecycle probe is explicitly skipped only when the host
    // denies native Unix sockets. Other daemon errors remain real test failures.
    $result = cli(['run', ...$local, '--seconds=1', '--output=json']);
    $events = eventRows($result['out']);
    check($result['err'] === '' && !str_contains($result['out'], "\033"), 'Real daemon lifecycle output is exclusively ANSI-free JSONL.');
    $names = array_column($events, 'event');
    $failures = array_values(array_filter($events, static fn(array $event): bool => $event['event'] === 'daemon_failed'));
    $failure = $failures[0]['context']['message'] ?? '';
    if ($result['code'] === 1 && PHP_OS_FAMILY !== 'Windows'
        && str_starts_with($failure, 'Cannot open native Unix control socket:')
        && (str_contains($failure, 'Operation not permitted') || str_contains($failure, 'Permission denied'))) {
        $skips[] = ['check' => 'native_daemon_lifecycle', 'reason' => $failure];
    } else {
        check($result['code'] === 0 && $failures === [], 'Local-core daemon completes a timed run successfully.');
        check(in_array('daemon_started', $names, true) && in_array('server_disabled', $names, true), 'Timed run reports startup and deliberately disabled Server integration.');
        check(array_slice($names, -2) === ['daemon_stopping', 'daemon_stopped'], 'Timed run emits its normal shutdown lifecycle.');
    }
    check(!in_array('server_request_started', $names, true) && !in_array('server_catalog_refreshed', $names, true), 'Isolated CLI lifecycle never enables Server communication.');
    echo json_encode(['suite' => 'm2-console-cli', 'status' => 'passed', 'assertions' => $count, 'skipped' => $skips, 'network_requests' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} finally {
    removeTree($dir);
}
