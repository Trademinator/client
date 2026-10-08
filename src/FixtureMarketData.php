<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use React\Promise\PromiseInterface;
use RuntimeException;
use function React\Promise\reject;
use function React\Promise\resolve;

final class FixtureMarketData implements MarketData
{
    private int $sequence = 0;
    private bool $failed = false;

    public function supportsWebSocket(): bool { return true; }

    public function watchTicker(string $symbol): PromiseInterface
    {
        // The first disconnection proves fallback; subsequent probes can recover.
        if (!$this->failed) {
            $this->failed = true;
            return reject(new RuntimeException('Injected fixture disconnect.'));
        }
        return resolve($this->ticker($symbol));
    }

    public function fetchTicker(string $symbol): PromiseInterface { return resolve($this->ticker($symbol)); }
    public function resetWebSocket(): void {}
    public function resetRest(): void {}
    public function close(): void {}

    private function ticker(string $symbol): array
    {
        $this->sequence++;
        return ['symbol' => $symbol, 'timestamp' => 1700000000000 + $this->sequence * 1000,
            'bid' => '2.300000000000000001', 'ask' => '2.300000000000000002',
            'last' => '2.300000000000000001'];
    }
}
