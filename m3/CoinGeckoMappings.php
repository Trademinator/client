<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;
use Trademinator\Client\M2\ServerApi;
use Trademinator\Client\M2\Transport;

use function React\Promise\resolve;

/** Server-owned identities, scoped to an exchange and exact spot pair. No disk or config overrides. */
final class CoinGeckoMappings
{
    private ?object $api;
    private \Closure $clock;
    private ?\Closure $sink;
    private array $mappings = [];
    private array $staged = [];
    private array $seen = [];
    private ?string $cursor = null;
    private ?string $request = null;
    private ?Deferred $waiting = null;
    private ?int $startedAt = null;
    private ?int $updatedAt = null;
    private int $due = 0;
    private int $sequence = 0;
    private int $revision = 0;
    private int $highWater = 0;
    private string $state = 'pending';
    private ?string $error = null;
    private bool $closed = false;
    private const REFRESH_MS = 300000;
    private const RETRY_MS = 60000;

    public function __construct(array $settings, ?object $api = null, ?callable $clock = null, ?callable $sink = null)
    {
        $this->api = ($settings['enabled'] ?? false)
            ? ($api ?? new ServerApi(new Transport($settings['url'], $settings['token'], $settings['timeout_ms']))) : null;
        $this->clock = $clock === null ? static fn (): int => (int)floor(microtime(true) * 1000) : \Closure::fromCallable($clock);
        $this->sink = $sink === null ? null : \Closure::fromCallable($sink);
        if ($this->api === null) { $this->state = 'disabled'; }
    }

    public function tick(): void
    {
        if ($this->closed || $this->api === null) { return; }
        $now = $this->now();
        if ($this->startedAt !== null && $now - $this->startedAt >= 60000) {
            $this->fail('refresh_timeout', $now); return;
        }
        foreach ($this->api->poll($now) as $response) {
            if (($response['request_id'] ?? null) !== $this->request) { continue; }
            $this->request = null;
            if (($response['error'] ?? null) !== null || ($response['status'] ?? null) !== 200) {
                $this->fail($response['error'] ?? 'mapping_request_failed', $now, $response['retry_after_ms'] ?? 0); return;
            }
            try { $this->accept($response['data']); }
            catch (Throwable) { $this->fail('invalid_mapping_catalogue', $now); return; }
            if ($this->cursor === null) {
                $this->mappings = $this->staged;
                $this->staged = $this->seen = [];
                $this->updatedAt = $now;
                $this->startedAt = null;
                $this->due = $now + self::REFRESH_MS;
                $this->state = 'ready'; $this->error = null; ++$this->revision;
                $this->settle();
                $this->emit('info', 'coingecko_mappings_updated', 'CoinGecko mappings refreshed from the Server.');
            }
        }
        if ($this->request !== null || $now < $this->due) { return; }
        if ($this->startedAt === null) {
            $this->mappings = $this->staged = $this->seen = [];
            $this->cursor = null;
            $this->startedAt = $now;
            $this->state = 'refreshing'; ++$this->revision;
            $this->waiting = new Deferred();
        }
        $this->request = 'coingecko-'.++$this->sequence;
        // Pages have their own modest budget; they do not occupy the signal poller.
        $this->due = $now + 1000;
        try { $this->api->coinGeckoMappings($this->request, $this->cursor); }
        catch (Throwable) { $this->fail('mapping_request_failed', $now); }
    }

    public function ready(): PromiseInterface
    {
        $this->tick();
        return $this->waiting?->promise() ?? resolve(null);
    }

    public function coinId(string $exchange, string $base, string $quote): ?string
    {
        if ($this->closed || $this->state !== 'ready' || $this->updatedAt === null
            || $this->now() >= $this->updatedAt + self::REFRESH_MS) { return null; }
        return $this->mappings[$exchange.':'.$base.'/'.$quote] ?? null;
    }

    public function revision(): int { return $this->revision; }

    private function accept(array $data): void
    {
        if (($data['api_version'] ?? null) !== 1 || !is_array($data['mappings'] ?? null)
            || !array_is_list($data['mappings']) || count($data['mappings']) > 250
            || !array_key_exists('next_cursor', $data)
            || ($data['next_cursor'] !== null && !self::uuid($data['next_cursor']))) {
            throw new RuntimeException('Invalid mapping page.');
        }
        $last = $this->cursor;
        foreach ($data['mappings'] as $row) {
            if (!is_array($row) || !self::uuid($row['mapping_id'] ?? null)
                || ($last !== null && strcmp($row['mapping_id'], $last) <= 0)
                || !is_string($row['exchange'] ?? null) || !preg_match('/^[a-z][a-z0-9]{1,40}$/D', $row['exchange'])
                || !is_string($row['symbol'] ?? null) || strlen($row['symbol']) > 100
                || !in_array($row['status'] ?? null, ['resolved','pending','ambiguous','unmapped','unsupported'], true)
                || !array_key_exists('coin_id', $row) || !is_bool($row['manually_mapped'] ?? null)) {
                throw new RuntimeException('Invalid mapping row.');
            }
            $last = $row['mapping_id'];
            $key = $row['exchange'].':'.$row['symbol'];
            if (isset($this->seen[$key]) || count($this->seen) >= 10000) { throw new RuntimeException('Duplicate or excessive mappings.'); }
            $this->seen[$key] = true;
            if ($row['status'] !== 'resolved') {
                if ($row['coin_id'] !== null) { throw new RuntimeException('Unresolved coin identity.'); }
                continue;
            }
            if (!is_string($row['coin_id']) || !preg_match('/^[a-z0-9_-]{1,128}$/D', $row['coin_id'])
                || !is_string($row['base_symbol'] ?? null) || !is_string($row['vs_currency'] ?? null)
                || !preg_match('/^[A-Z0-9._-]+\/[A-Z0-9._-]+$/D', $row['symbol'])
                || $row['symbol'] !== $row['base_symbol'].'/'.strtoupper($row['vs_currency'])) {
                throw new RuntimeException('Inconsistent spot mapping.');
            }
            $this->staged[$key] = $row['coin_id'];
        }
        if ($data['next_cursor'] !== null && ($data['mappings'] === [] || $data['next_cursor'] !== $last)) {
            throw new RuntimeException('Invalid mapping cursor.');
        }
        $this->cursor = $data['next_cursor'];
    }

    private function fail(string $error, int $now, int $retryAfter = 0): void
    {
        $this->api?->cancelAll();
        $this->mappings = $this->staged = $this->seen = [];
        $this->request = $this->cursor = null;
        $this->startedAt = $this->updatedAt = null;
        $this->state = 'unavailable'; $this->error = $error; ++$this->revision;
        $this->due = $now + max(self::RETRY_MS, min(86400000, $retryAfter));
        $this->settle();
        $this->emit('warning', 'coingecko_mappings_unavailable', 'Server CoinGecko mappings are unavailable; historical exchange pricing remains available. CoinGecko fallback will retry automatically.');
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function now(): int { return $this->highWater = max($this->highWater, ($this->clock)()); }
    private function settle(): void { $waiting = $this->waiting; $this->waiting = null; $waiting?->resolve(null); }
    private function emit(string $level, string $event, string $message): void
    {
        if ($this->sink !== null) { ($this->sink)($level, $event, $this->status(), $message); }
    }
    public function status(): array
    {
        return ['state' => $this->state, 'resolved_pairs' => count($this->mappings),
            'updated_at_ms' => $this->updatedAt, 'error' => $this->error];
    }
    public function close(): void
    {
        $this->closed = true;
        $this->api?->cancelAll();
        $this->mappings = $this->staged = $this->seen = [];
        $this->state = 'closed'; ++$this->revision;
        $this->settle();
    }
}
