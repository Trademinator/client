<?php

declare(strict_types=1);

namespace Trademinator\Client\M1;

use RuntimeException;
use Throwable;

/** Local-only control. Unix sockets or ACL-restricted Windows named pipes; never TCP. */
final class Control
{
    public const MAX_MESSAGE = 16384;
    private mixed $server = null;
    private mixed $bridge = null;
    private array $clients = [];
    private string $token;
    private string $queueDir;
    private bool $closed = false;

    public function __construct(private readonly Paths $paths, private readonly \Closure $handler)
    {
        $this->token = bin2hex(random_bytes(32));
        $this->queueDir = $paths->runtimeDir.'/pipe-queue';
        if (is_link($paths->controlFile)) { throw new RuntimeException('Unsafe control metadata path.'); }
        if (is_file($paths->controlFile) && !unlink($paths->controlFile)) { throw new RuntimeException('Cannot remove stale control metadata.'); }
        if (PHP_OS_FAMILY === 'Windows') {
            if (!is_dir($this->queueDir) && !mkdir($this->queueDir, 0700)) { throw new RuntimeException('Cannot create named-pipe bridge queue.'); }
            foreach (glob($this->queueDir.'/*') ?: [] as $file) { if (is_file($file)) { @unlink($file); } }
            $args = self::powershellArgs('Serve');
            array_push($args, '-PipeName', $paths->pipeName(), '-QueueDir', $this->queueDir, '-ParentPid', (string) getmypid());
            $this->bridge = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $paths->logDir.'/pipe-bridge.log', 'a'], 2 => ['file', $paths->logDir.'/pipe-bridge.log', 'a']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($this->bridge)) { throw new RuntimeException('Cannot start the Windows named-pipe helper.'); }
            fclose($pipes[0]);
            $deadline = microtime(true) + 10;
            while (!is_file($this->queueDir.'/ready') && microtime(true) < $deadline) {
                $status = proc_get_status($this->bridge);
                if (!$status['running']) { throw new RuntimeException('Windows named-pipe helper failed; inspect logs/pipe-bridge.log.'); }
                usleep(20000);
            }
            if (!is_file($this->queueDir.'/ready')) { $this->close(); throw new RuntimeException('Windows named-pipe helper did not become ready.'); }
            $transport = ['transport' => 'windows_named_pipe', 'endpoint' => '\\\\.\\pipe\\'.$paths->pipeName()];
        } else {
            $socket = $paths->socketPath();
            if (file_exists($socket) || is_link($socket)) {
                if (is_link($socket) || filetype($socket) !== 'socket') { throw new RuntimeException('Unsafe control socket path.'); }
                if (!unlink($socket)) { throw new RuntimeException('Cannot remove stale control socket.'); }
            }
            $this->server = @stream_socket_server('unix://'.$socket, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
            if ($this->server === false) { throw new RuntimeException('Cannot open native Unix control socket: '.$error); }
            if (!chmod($socket, 0600)) { $this->close(); throw new RuntimeException('Cannot restrict socket permissions.'); }
            stream_set_blocking($this->server, false);
            $transport = ['transport' => 'unix_socket', 'endpoint' => $socket];
        }
        Paths::atomicJson($paths->controlFile, $transport + ['token' => $this->token, 'pid' => getmypid(), 'protocol' => 1]);
    }

    public static function hardenWindowsDirectory(string $directory): void
    {
        $args = self::powershellArgs('HardenDirectory');
        array_push($args, '-DataDir', $directory);
        self::runHelper($args, '', 15);
    }

    private static function powershellArgs(string $mode): array
    {
        $windows = getenv('SystemRoot') ?: 'C:\\Windows';
        $exe = $windows.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        return [$exe, '-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', dirname(__DIR__).'/tools/m1-pipe.ps1', '-Mode', $mode];
    }

    /** Bound each process call; Windows pipes cannot reliably use PHP nonblocking reads. */
    private static function runHelper(array $args, string $input, int $timeout): string
    {
        $out = tempnam(sys_get_temp_dir(), 'tm1-out-');
        $err = tempnam(sys_get_temp_dir(), 'tm1-err-');
        if ($out === false || $err === false) { throw new RuntimeException('Cannot create helper response file.'); }
        @chmod($out, 0600); @chmod($err, 0600);
        try {
            $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start Windows control helper.'); }
            if ($input !== '') { fwrite($pipes[0], $input); }
            fclose($pipes[0]);
            $deadline = microtime(true) + $timeout;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) { break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            if ($status['running']) { proc_terminate($process); proc_close($process); throw new RuntimeException('Windows control helper timed out.'); }
            $exitCode = $status['exitcode'];
            proc_close($process);
            if ($exitCode !== 0) { throw new RuntimeException('Windows control helper failed: '.substr(trim((string) file_get_contents($err)), 0, 512)); }
            if (filesize($out) > self::MAX_MESSAGE * 8) { throw new RuntimeException('Control response is too large.'); }
            return (string) file_get_contents($out);
        } finally { @unlink($out); @unlink($err); }
    }

    public function poll(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $status = proc_get_status($this->bridge);
            if (!$status['running']) { throw new RuntimeException('Windows named-pipe helper stopped unexpectedly.'); }
            foreach (array_slice(glob($this->queueDir.'/*.request') ?: [], 0, 16) as $file) {
                $bytes = filesize($file) <= self::MAX_MESSAGE ? (string) file_get_contents($file) : '';
                $response = $this->dispatch($bytes);
                Paths::atomicWrite(substr($file, 0, -8).'.response', json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                @unlink($file);
            }
            return;
        }
        for ($i = 0; $i < 16; $i++) {
            $client = @stream_socket_accept($this->server, 0);
            if ($client === false) { break; }
            if (count($this->clients) >= 32) { fclose($client); continue; }
            stream_set_blocking($client, false);
            $this->clients[(int) $client] = ['stream' => $client, 'buffer' => '', 'deadline' => microtime(true) + 2];
        }
        foreach ($this->clients as $id => &$client) {
            $chunk = @fread($client['stream'], self::MAX_MESSAGE + 1);
            if ($chunk !== false) { $client['buffer'] .= $chunk; }
            $newline = strpos($client['buffer'], "\n");
            if (strlen($client['buffer']) > self::MAX_MESSAGE || microtime(true) > $client['deadline'] || feof($client['stream'])) {
                fclose($client['stream']); unset($this->clients[$id]); continue;
            }
            if ($newline !== false) {
                $response = json_encode($this->dispatch(substr($client['buffer'], 0, $newline)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                // A bounded local response avoids an unresponsive client blocking the daemon.
                stream_set_blocking($client['stream'], true);
                stream_set_timeout($client['stream'], 1);
                @fwrite($client['stream'], $response);
                fclose($client['stream']); unset($this->clients[$id]);
            }
        }
        unset($client);
    }

    private function dispatch(string $bytes): array
    {
        try {
            if (strlen($bytes) > self::MAX_MESSAGE) { throw new RuntimeException('Request is too large.'); }
            $request = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($request) || !is_string($request['token'] ?? null) || !hash_equals($this->token, $request['token'])) { return ['ok' => false, 'error' => 'unauthorized']; }
            if (!is_string($request['command'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $request['command']) || !is_array($request['arguments'] ?? [])) { throw new RuntimeException('Invalid control request.'); }
            return ['ok' => true, 'result' => ($this->handler)($request['command'], $request['arguments'] ?? [])];
        } catch (Throwable $error) { return ['ok' => false, 'error' => $error->getMessage()]; }
    }

    public static function request(Config|string $configOrDataDir, string $command, array $arguments = []): array
    {
        $paths = new Paths($configOrDataDir);
        if (!is_file($paths->controlFile)) { throw new RuntimeException('M1 is not running (no control endpoint).'); }
        $metadata = json_decode((string) file_get_contents($paths->controlFile), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || !is_string($metadata['token'] ?? null)) { throw new RuntimeException('Invalid control metadata.'); }
        $bytes = json_encode(['token' => $metadata['token'], 'command' => $command, 'arguments' => $arguments], JSON_THROW_ON_ERROR)."\n";
        if (strlen($bytes) > self::MAX_MESSAGE) { throw new RuntimeException('Control request is too large.'); }
        if (PHP_OS_FAMILY === 'Windows') {
            if (($metadata['transport'] ?? '') !== 'windows_named_pipe' || ($metadata['endpoint'] ?? '') !== '\\\\.\\pipe\\'.$paths->pipeName()) { throw new RuntimeException('Unexpected named-pipe endpoint.'); }
            $args = self::powershellArgs('Request');
            array_push($args, '-PipeName', $paths->pipeName());
            $response = self::runHelper($args, $bytes, 15);
        } else {
            if (($metadata['transport'] ?? '') !== 'unix_socket' || ($metadata['endpoint'] ?? '') !== $paths->socketPath()) { throw new RuntimeException('Unexpected Unix socket endpoint.'); }
            $socket = @stream_socket_client('unix://'.$paths->socketPath(), $errno, $error, 2);
            if ($socket === false) { throw new RuntimeException('M1 is not responding: '.$error); }
            try {
                stream_set_timeout($socket, 10);
                if (fwrite($socket, $bytes) !== strlen($bytes)) { throw new RuntimeException('Cannot send control request.'); }
                $response = fgets($socket, self::MAX_MESSAGE * 8 + 1);
                if ($response === false || !str_ends_with($response, "\n")) { throw new RuntimeException('Control request timed out or returned an invalid response.'); }
            } finally { fclose($socket); }
        }
        $decoded = json_decode(trim($response), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_key_exists('ok', $decoded)) { throw new RuntimeException('Invalid control response.'); }
        if ($decoded['ok'] !== true) { throw new RuntimeException((string) ($decoded['error'] ?? 'Control request failed.')); }
        return $decoded['result'] ?? [];
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        foreach ($this->clients as $client) { if (is_resource($client['stream'])) { fclose($client['stream']); } }
        $this->clients = [];
        if (is_resource($this->server)) { fclose($this->server); $this->server = null; @unlink($this->paths->socketPath()); }
        if (is_resource($this->bridge)) {
            @file_put_contents($this->queueDir.'/stop', 'stop');
            $deadline = microtime(true) + 2;
            do { $status = proc_get_status($this->bridge); if (!$status['running']) { break; } usleep(20000); } while (microtime(true) < $deadline);
            if ($status['running']) { proc_terminate($this->bridge); }
            proc_close($this->bridge); $this->bridge = null;
        }
        @unlink($this->paths->controlFile);
    }

    public function __destruct() { $this->close(); }
}
