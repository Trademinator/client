<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
use function React\Async\async;

final class Daemon
{
    private bool $stopping = false;
    private bool $feedBusy = false;
    private bool $serverBusy = false;
    private int $samples = 0;
    private int $errors = 0;
    private string $lastError = '';
    private ?string $lastErrorType = null;
    private float $started = 0.0;
    private int $warmMemory = 0;
    private string $runId;
    private array $server = ['status' => 'not_configured', 'risk_factor' => null];
    private array $timers = [];
    private ?PromiseInterface $feedTask = null;
    private ?PromiseInterface $serverTask = null;
    private ?LocalControl $control = null;
    private ?StateStore $store = null;
    private ?ResilientFeed $feed = null;
    private int $exitCode = 0;
    private int $maxSamples = 0;
    private ?array $lastQuote = null;

    public function __construct(private Config $config) { $this->runId = bin2hex(random_bytes(12)); }

    public function run(int $seconds = 0, int $maxSamples = 0): int
    {
        umask(0077);
        if (!is_dir($this->config->dataDir) && !mkdir($this->config->dataDir, 0700, true)) {
            throw new RuntimeException('Cannot create data directory.');
        }
        $lock = fopen($this->config->dataDir.'/daemon.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new RuntimeException('Another M0 daemon is using this data directory.');
        }
        $controlFile = $this->config->dataDir.'/control.json';
        try {
            $this->started = hrtime(true) / 1e9;
            $this->maxSamples = $maxSamples;
            $this->store = new StateStore($this->config->dataDir.'/m0.sqlite', $this->config->retention);
            $source = $this->config->mode === 'fixture' ? new FixtureMarketData()
                : new CcxtMarketData($this->config->exchange, $this->config->requestTimeout);
            $this->feed = new ResilientFeed($source, $this->config->symbol,
                $this->config->requestTimeout, $this->config->wsRetrySeconds);
            $api = null;
            if ($this->config->mode === 'live' && $this->config->serverUrl !== '') {
                $api = new ServerApi($this->config->serverUrl, (string) getenv('TRADEMINATOR_API_KEY'), $this->config->requestTimeout);
                $this->server = ['status' => 'pending', 'risk_factor' => null];
            } elseif ($this->config->mode === 'fixture') {
                $this->server = ['status' => 'fixture', 'risk_factor' => '0.2500000000000000',
                    'algorithm_version' => 'questionnaire-risk-v1', 'source' => 'default', 'market_count' => 1];
            }
            $token = bin2hex(random_bytes(32));
            $this->control = new LocalControl($this->config->ipcPort, $token,
                fn(): array => $this->status(), fn() => $this->stop());
            $written = file_put_contents($controlFile, json_encode(['port' => $this->control->port(), 'token' => $token], JSON_THROW_ON_ERROR), LOCK_EX);
            if ($written === false) { throw new RuntimeException('Cannot write local control credentials.'); }
            chmod($controlFile, 0600);
            $this->store->set('status', $this->status());
            // React supplies the timer instance, even when the callback ignores it.
            // Keep its argument explicit for TypePHP's strict compiled signatures.
            $this->timers[] = Loop::addPeriodicTimer($this->config->intervalMs / 1000, fn(TimerInterface $timer) => $this->pollFeed());
            $this->timers[] = Loop::addPeriodicTimer(1.0, fn(TimerInterface $timer) => $this->heartbeat());
            if ($api !== null) {
                $this->timers[] = Loop::addPeriodicTimer($this->config->serverPollSeconds, fn(TimerInterface $timer) => $this->pollServer($api));
                Loop::futureTick(fn() => $this->pollServer($api));
            }
            if ($seconds > 0) { $this->timers[] = Loop::addTimer($seconds, fn(TimerInterface $timer) => $this->stop()); }
            // Signal listeners receive the signal number; future ticks receive no arguments.
            $signalHandler = fn(int $signal) => $this->stop();
            if (extension_loaded('pcntl')) {
                Loop::addSignal(SIGTERM, $signalHandler);
                Loop::addSignal(SIGINT, $signalHandler);
            }
            Loop::futureTick(fn() => $this->pollFeed());
            Loop::run();
        } finally {
            $this->stopping = true;
            try {
                foreach ($this->timers as $timer) { Loop::cancelTimer($timer); }
                if (isset($signalHandler) && extension_loaded('pcntl')) {
                    Loop::removeSignal(SIGTERM, $signalHandler);
                    Loop::removeSignal(SIGINT, $signalHandler);
                }
                $this->feed?->close();
                $this->feedTask?->cancel();
                $this->serverTask?->cancel();
                if ($this->store !== null) {
                    $this->store->set('status', $this->status());
                    $this->store->checkpoint();
                }
            } finally {
                $this->control?->close();
                if (is_file($controlFile)) { unlink($controlFile); }
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        echo json_encode($this->status(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        return $this->exitCode;
    }

    private function pollFeed(): void
    {
        if ($this->stopping || $this->feedBusy) { return; }
        $this->feedBusy = true;
        $this->feedTask = async(function (): void {
            try {
                $quote = $this->feed->next();
                if ($this->stopping) { return; }
                $quote['exchange'] = $this->config->exchange;
                $quote['fixture'] = $this->config->mode === 'fixture';
                $this->store->append($this->runId, $this->feed->transport, $quote);
                $this->lastQuote = $quote;
                $this->samples++;
                $this->lastError = '';
                $this->lastErrorType = null;
                if ($this->maxSamples > 0 && $this->samples >= $this->maxSamples) { $this->stop(); }
            } catch (Throwable $error) {
                if (!$this->stopping) {
                    $this->errors++;
                    $this->lastError = 'market_data_unavailable';
                    $this->lastErrorType = get_class($error);
                    // Persistence errors are fatal; exchange errors remain retryable.
                    if ($error instanceof \PDOException) { $this->exitCode = 1; $this->stop(); }
                }
            } finally { $this->feedBusy = false; }
        })();
    }

    private function pollServer(ServerApi $api): void
    {
        if ($this->stopping || $this->serverBusy) { return; }
        $this->serverBusy = true;
        $this->serverTask = async(function () use ($api): void {
            try {
                $this->server = $api->snapshot();
            } catch (Throwable) {
                $this->server = ['status' => 'unavailable', 'risk_factor' => null];
            } finally { $this->serverBusy = false; }
        })();
    }

    private function heartbeat(): void
    {
        $memory = memory_get_usage(true);
        if ($this->warmMemory === 0 && $this->elapsed() >= 5) { $this->warmMemory = $memory; }
        try { $this->store->set('status', $this->status()); }
        catch (Throwable) { $this->exitCode = 1; $this->lastError = 'persistence_unavailable'; $this->stop(); }
    }

    public function stop(): void
    {
        $this->stopping = true;
        Loop::stop();
    }

    private function elapsed(): float { return hrtime(true) / 1e9 - $this->started; }

    public function status(): array
    {
        return ['milestone' => 'M0', 'mode' => $this->config->mode, 'run_id' => $this->runId,
            'state' => $this->stopping ? 'stopped' : 'running', 'uptime_seconds' => round($this->elapsed(), 3),
            'exchange' => $this->config->exchange, 'symbol' => $this->config->symbol,
            'samples' => $this->samples, 'errors' => $this->errors, 'last_error' => $this->lastError,
            'last_error_type' => $this->lastErrorType,
            'transport' => $this->feed?->transport, 'websocket_failures' => $this->feed?->webSocketFailures,
            'latest_quote' => $this->lastQuote,
            'websocket_samples' => $this->feed?->webSocketSamples, 'rest_samples' => $this->feed?->restSamples,
            'server' => $this->server, 'memory_bytes' => memory_get_usage(true),
            'peak_memory_bytes' => memory_get_peak_usage(true), 'warm_memory_bytes' => $this->warmMemory,
            'growth_after_warmup_bytes' => $this->warmMemory > 0 ? memory_get_usage(true) - $this->warmMemory : null];
    }
}
