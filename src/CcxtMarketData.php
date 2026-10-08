<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use React\Promise\PromiseInterface;
use InvalidArgumentException;
use function React\Promise\reject;

final class CcxtMarketData implements MarketData
{
    private object $rest;
    private ?object $webSocket = null;
    private ?string $webSocketClass = null;
    private array $options;

    public function __construct(string $exchange, int $timeoutSeconds)
    {
        if (!preg_match('/^[a-z][a-z0-9]{1,40}$/D', $exchange)) {
            throw new InvalidArgumentException('Invalid exchange ID.');
        }
        $restClass = '\\ccxt\\async\\'.$exchange;
        $proClass = '\\ccxt\\pro\\'.$exchange;
        if (!class_exists($restClass)) {
            throw new InvalidArgumentException('Exchange is not provided by the installed CCXT version.');
        }
        $this->options = ['enableRateLimit' => true, 'timeout' => $timeoutSeconds * 1000, 'newUpdates' => true];
        // No private exchange credentials or order methods are used by M0.
        $this->rest = new $restClass($this->options);
        if (class_exists($proClass)) {
            $candidate = new $proClass($this->options);
            if (($candidate->has['watchTicker'] ?? false) === true) {
                $this->webSocketClass = $proClass;
                $this->webSocket = $candidate;
            } else {
                $candidate->close();
            }
        }
    }

    public function supportsWebSocket(): bool { return $this->webSocketClass !== null; }

    public function watchTicker(string $symbol): PromiseInterface
    {
        if ($this->webSocketClass === null) {
            return reject(new \RuntimeException('WebSocket ticker unavailable.'));
        }
        if ($this->webSocket === null) {
            $class = $this->webSocketClass;
            $this->webSocket = new $class($this->options);
        }
        return $this->webSocket->watch_ticker($symbol);
    }

    public function fetchTicker(string $symbol): PromiseInterface { return $this->rest->fetch_ticker($symbol); }

    public function resetRest(): void
    {
        // CCXT caches the market-loading promise, including a failed/cancelled one.
        // Keep its rate limiter and successful market cache but permit a fresh load.
        $this->rest->marketsLoading = null;
        $this->rest->reloadingMarkets = false;
    }

    public function resetWebSocket(): void
    {
        $this->webSocket?->close();
        $this->webSocket = null;
    }

    public function close(): void
    {
        $this->resetWebSocket();
        $this->rest->close();
    }
}
