<?php

declare(strict_types=1);

namespace Trademinator\Client\M1;

use RuntimeException;
use Throwable;

/** Local core loop with nonblocking M2 reads. It never submits exchange orders. */
final class Daemon
{
    private readonly Paths $paths;
    private readonly string $runId;
    private ?\Closure $onTick;
    private ?\Closure $statusProvider;
    private ?\Closure $onReload;
    private ?\Closure $eventSink;
    private ?\Closure $commandHandler = null;
    private ?Control $control = null;
    private bool $stopping = false;
    private string $state = 'created';
    private float $started = 0;
    private int $configGeneration = 1;
    private int $ticks = 0;
    private bool $recovered = false;
    private ?string $lastError = null;
    private array $componentStatus = [];

    public function __construct(private Config $config, ?callable $onTick = null, ?callable $statusProvider = null, ?callable $onReload = null, ?callable $eventSink = null)
    {
        $this->paths = new Paths($config);
        $this->runId = bin2hex(random_bytes(12));
        $this->onTick = $onTick === null ? null : \Closure::fromCallable($onTick);
        $this->statusProvider = $statusProvider === null ? null : \Closure::fromCallable($statusProvider);
        $this->onReload = $onReload === null ? null : \Closure::fromCallable($onReload);
        $this->eventSink = $eventSink === null ? null : \Closure::fromCallable($eventSink);
    }

    public function setHandler(callable $handler): void { $this->commandHandler = \Closure::fromCallable($handler); }
    public function config(): Config { return $this->config; }

    public function run(int $seconds = 0): int
    {
        if ($seconds < 0) { throw new RuntimeException('Run duration cannot be negative.'); }
        if ($this->state !== 'created') { throw new RuntimeException('A daemon instance can run only once.'); }
        $lock = $this->paths->acquireLock();
        $exitCode = 0;
        $this->started = hrtime(true) / 1e9;
        $this->state = 'starting';
        $signals = [];
        try {
            $this->log('info', 'daemon_starting', ['pid' => getmypid(), 'mode' => $this->config->serverUrl === '' ? 'local_core' : 'server_intelligence'], 'Starting Trademinator Client.');
            if (is_file($this->paths->statusFile)) {
                $previous = json_decode((string) file_get_contents($this->paths->statusFile), true);
                $this->recovered = is_array($previous) && in_array($previous['state'] ?? '', ['running', 'starting', 'stopping', 'failed'], true);
            }
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
                foreach ([SIGTERM, SIGINT] as $signal) {
                    $signals[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, function (int $signal): void { $this->stop(); });
                }
            }
            $this->writeStatus();
            // Initialize and verify storage while the exclusive data-directory lock is held.
            $this->tick();
            $this->control = new Control($this->paths, fn(string $command, array $args): array => $this->dispatch($command, $args));
            $this->state = 'running';
            $this->log('info', 'daemon_started', ['recovered_after_unclean_shutdown' => $this->recovered, 'trading_enabled' => false], 'Client is running; local control is ready.');
            $this->writeStatus();
            $nextTick = hrtime(true) / 1e9 + $this->config->tickMs / 1000;
            $nextStatus = hrtime(true) / 1e9 + 1;
            while (!$this->stopping) {
                $this->control->poll();
                $now = hrtime(true) / 1e9;
                if ($seconds > 0 && $now - $this->started >= $seconds) { $this->stop(); }
                if ($this->stopping) { break; }
                if ($now >= $nextTick) {
                    $this->tick();
                    $nextTick = hrtime(true) / 1e9 + $this->config->tickMs / 1000;
                }
                if ($now >= $nextStatus) { $this->writeStatus(); $nextStatus = $now + 1; }
                usleep(10000);
            }
        } catch (Throwable $error) {
            $exitCode = 1;
            $this->lastError = EventRecord::safeText($error->getMessage());
            $this->state = 'failed';
            if ($this->eventSink === null) { fwrite(STDERR, json_encode(['ok' => false, 'milestone' => 'M3', 'error' => $this->lastError], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL); }
            try { $this->log('error', 'daemon_failed', ['error_type' => get_class($error), 'message' => $error->getMessage()], 'Client stopped because an operation failed.'); } catch (Throwable) { /* Preserve the original storage failure. */ }
        } finally {
            $this->stopping = true;
            if ($this->state !== 'failed') { $this->state = 'stopped'; }
            try { $this->log('info', 'daemon_stopped', ['exit_code' => $exitCode], 'Client process stopped.'); $this->writeStatus(); }
            catch (Throwable $error) { $exitCode = 1; $this->state = 'failed'; $this->lastError ??= EventRecord::safeText($error->getMessage()); }
            $this->control?->close();
            foreach ($signals as $signal => $handler) { pcntl_signal($signal, $handler); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $exitCode;
    }

    private function tick(): void
    {
        if ($this->onTick !== null) { ($this->onTick)($this, $this->config); }
        if ($this->statusProvider !== null) { $this->componentStatus = ($this->statusProvider)(); }
        $this->ticks++;
    }

    public function stop(): void
    {
        $firstRequest = !$this->stopping;
        $this->stopping = true;
        if ($this->state === 'running') { $this->state = 'stopping'; }
        if ($firstRequest) {
            try { $this->log('info', 'daemon_stopping', [], 'Shutdown requested; stopping the client.'); } catch (Throwable) { /* Shutdown remains available if file logging fails. */ }
        }
    }

    private function dispatch(string $command, array $arguments): array
    {
        if ($command === 'status') { return $this->status(); }
        if ($command === 'stop') { $this->stop(); return ['state' => 'stopping', 'run_id' => $this->runId]; }
        if ($command === 'reload') {
            try { $this->log('info', 'configuration_reloading', [], 'Checking the updated configuration.'); } catch (Throwable) { /* Continue validation if file logging fails. */ }
            try {
                $candidate = $this->config->reload();
                if ($this->onReload !== null) { ($this->onReload)($candidate); }
            } catch (Throwable $error) {
                try { $this->log('warning', 'configuration_rejected', ['message' => $error->getMessage()], 'Configuration rejected; keeping the current settings.'); } catch (Throwable) { /* Keep the original validation error. */ }
                throw $error;
            }
            // The runtime has committed its new settings. A reporting failure must
            // never make the displayed Config silently disagree with that runtime.
            $this->config = $candidate;
            $this->configGeneration++;
            $result = ['state' => $this->state, 'config_generation' => $this->configGeneration, 'config' => $this->config->toArray()];
            try {
                $this->log('info', 'configuration_reloaded', ['generation' => $this->configGeneration], 'Updated configuration applied.');
                $this->writeStatus();
            } catch (Throwable) { $result['warning'] = 'configuration_applied_reporting_failed'; }
            return $result;
        }
        try {
            if ($this->commandHandler !== null) { return ($this->commandHandler)($command, $arguments); }
            throw new RuntimeException('Unsupported local control command.');
        } catch (Throwable $error) {
            if (!in_array($command, ['accounts', 'markets', 'subscriptions'], true)) {
                try { $this->log('warning', 'control_command_failed', ['command' => $command, 'error_type' => get_class($error), 'message' => $error->getMessage()], 'Local command was rejected.'); }
                catch (Throwable) { /* Preserve the command failure even if file logging fails. */ }
            }
            throw $error;
        }
    }

    public function status(): array
    {
        return ['milestone' => 'M3', 'mode' => $this->config->serverUrl === '' ? 'local_core' : 'server_intelligence', 'trading_enabled' => false, 'state' => $this->state,
            'pid' => getmypid(), 'run_id' => $this->runId, 'uptime_seconds' => $this->started > 0 ? round(hrtime(true) / 1e9 - $this->started, 3) : 0,
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'data_dir' => $this->paths->dataDir,
            'ipc_transport' => PHP_OS_FAMILY === 'Windows' ? 'windows_named_pipe' : 'unix_socket',
            'recovered_after_unclean_shutdown' => $this->recovered, 'ticks' => $this->ticks,
            'config_generation' => $this->configGeneration, 'config' => $this->config->toArray(),
            'last_error' => $this->lastError, 'memory_bytes' => memory_get_usage(true), 'ledger' => $this->componentStatus];
    }

    private function writeStatus(): void
    {
        $snapshot = $this->status();
        // Server catalog, timers, warnings and intelligence are process-local.
        // Live IPC status can show them; restart never reloads them from disk.
        unset($snapshot['ledger']['server'], $snapshot['ledger']['exchange']);
        Paths::atomicJson($this->paths->statusFile, $snapshot);
    }

    public function log(string $level, string $event, array $context = [], string $message = ''): void
    {
        $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        if (!isset($levels[$level])) { throw new RuntimeException('Unknown log level.'); }
        $record = EventRecord::create($level, $event, $context, $this->runId, $message);
        if ($this->eventSink !== null) {
            try { ($this->eventSink)($record); } catch (Throwable) { /* Console failures cannot interrupt the daemon or disk logging. */ }
        }
        if ($levels[$level] < $levels[$this->config->logLevel]) { return; }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $path = $this->paths->logDir.'/client.jsonl';
        if (is_link($path)) { throw new RuntimeException('Unsafe log path.'); }
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) + strlen($line) > $this->config->logMaxBytes) {
            foreach (glob($path.'.*') ?: [] as $older) {
                $suffix = substr($older, strlen($path) + 1);
                if (ctype_digit($suffix) && (int) $suffix > $this->config->logKeep) {
                    if (is_link($older)) { throw new RuntimeException('Unsafe rotated log path.'); }
                    if (is_file($older) && !unlink($older)) { throw new RuntimeException('Cannot remove log beyond configured retention.'); }
                }
            }
            for ($i = $this->config->logKeep; $i >= 1; $i--) {
                $target = $path.'.'.$i;
                if (is_link($target)) { throw new RuntimeException('Unsafe rotated log path.'); }
                if ($i === $this->config->logKeep && is_file($target) && !unlink($target)) { throw new RuntimeException('Cannot remove old log.'); }
                $source = $i === 1 ? $path : $path.'.'.($i - 1);
                if (is_file($source) && !rename($source, $target)) { throw new RuntimeException('Cannot rotate log.'); }
            }
        }
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) { throw new RuntimeException('Cannot append daemon log.'); }
        @chmod($path, 0600);
    }
}
