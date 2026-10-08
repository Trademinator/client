<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use Closure;
use InvalidArgumentException;
use React\Promise\PromiseInterface;
use Throwable;
use function React\Async\await;
use function React\Promise\Timer\timeout;

final class ResilientFeed
{
    public string $transport = 'starting';
    public int $webSocketFailures = 0;
    public int $restFailures = 0;
    public int $webSocketSamples = 0;
    public int $restSamples = 0;
    private float $retryAt = 0.0;
    private Closure $clock;
    private bool $closed = false;
    private ?PromiseInterface $pending = null;

    public function __construct(private MarketData $source, private string $symbol,
        private float $timeoutSeconds, private float $retrySeconds, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    public function next(): array
    {
        if ($this->closed) { throw new \RuntimeException('Feed is closed.'); }
        $now = ($this->clock)();
        if ($this->source->supportsWebSocket() && $now >= $this->retryAt) {
            try {
                $this->pending = $this->source->watchTicker($this->symbol);
                $ticker = $this->normalize(await(timeout($this->pending, $this->timeoutSeconds)));
                $this->pending = null;
                $this->transport = 'websocket';
                $this->webSocketSamples++;
                return $ticker;
            } catch (Throwable $error) {
                if ($this->closed) { throw $error; }
                $this->pending?->cancel();
                $this->pending = null;
                $this->webSocketFailures++;
                // Close the failed client so pending subscriptions do not accumulate.
                $this->source->resetWebSocket();
                $this->retryAt = ($this->clock)() + $this->retrySeconds;
            }
        }
        $this->transport = 'rest';
        try {
            $this->pending = $this->source->fetchTicker($this->symbol);
            $ticker = $this->normalize(await(timeout($this->pending, $this->timeoutSeconds)));
            $this->pending = null;
            $this->restSamples++;
            return $ticker;
        } catch (Throwable $error) {
            $this->pending?->cancel();
            $this->pending = null;
            if (!$this->closed) { $this->source->resetRest(); }
            $this->restFailures++;
            throw $error;
        }
    }

    private function normalize(mixed $ticker): array
    {
        if (!is_array($ticker) || ($ticker['symbol'] ?? null) !== $this->symbol) {
            throw new InvalidArgumentException('Unexpected ticker symbol or payload.');
        }
        $result = ['symbol' => $this->symbol, 'timestamp' => null, 'bid' => null, 'ask' => null, 'last' => null];
        $timestamp = $ticker['timestamp'] ?? null;
        if ($timestamp !== null && (!is_int($timestamp) || $timestamp <= 0)) {
            throw new InvalidArgumentException('Invalid exchange timestamp.');
        }
        $result['timestamp'] = $timestamp;
        foreach (['bid', 'ask', 'last'] as $field) {
            if (($ticker[$field] ?? null) !== null) {
                $result[$field] = Decimal::positive($ticker[$field]);
            }
        }
        if ($result['last'] === null && ($result['bid'] === null || $result['ask'] === null)) {
            throw new InvalidArgumentException('Ticker has no usable prices.');
        }
        if ($result['bid'] !== null && $result['ask'] !== null && bccomp($result['bid'], $result['ask'], 100) > 0) {
            throw new InvalidArgumentException('Crossed ticker prices.');
        }
        return $result;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->pending?->cancel();
        $this->pending = null;
        $this->source->close();
    }
}
