<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use React\Promise\PromiseInterface;

interface MarketData
{
    public function supportsWebSocket(): bool;
    public function watchTicker(string $symbol): PromiseInterface;
    public function fetchTicker(string $symbol): PromiseInterface;
    public function resetWebSocket(): void;
    public function resetRest(): void;
    public function close(): void;
}
