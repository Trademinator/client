<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use Throwable;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use React\EventLoop\Loop;

use function React\Async\async;
use function React\Async\await;
use function React\Promise\resolve;

/** Estimated deposit basis, explicitly attributed to a historical source and quote currency. */
final class Prices
{
    private array $providers = [];
    private array $created = [];
    private array $loaded = [];
    private array $rates = [];
    private array $estimates = [];
    private int $started = 0;
    private int $finished = 0;
    private ?array $request = null;
    private ?PromiseInterface $pending = null;
    private bool $closed = false;
    private const CACHE_LIMIT = 4096;

    public function __construct(private object $receiving, private array $account, private int $timeoutMs)
    {
        $this->providers[] = $receiving;
    }
    public function estimate(string $currency, string $quote, int $timestamp): PromiseInterface
    {
        return async(function () use ($currency, $quote, $timestamp): ?array {
            $key = $currency.'/'.$quote.':'.$timestamp;
            if (array_key_exists($key, $this->estimates)) { return $this->estimates[$key]; }
            $result = $this->lookup($currency, $quote, $timestamp);
            $this->remember($this->estimates, $key, $result);
            return $result;
        })();
    }
    private function lookup(string $currency, string $quote, int $timestamp): ?array
    {
        if ($this->closed) { return null; }
        if ($currency === $quote) {
            return ['price' => '1','source' => 'quote_currency','quote' => $quote,'at_ms' => $timestamp,'estimated' => true];
        }
        // Keep public clients for this snapshot: load their markets once, not for every deposit.
        foreach ($this->account['public_price_exchanges'] as $name) {
            if (isset($this->created[$name])) { continue; }
            $class = '\\ccxt\\async\\'.$name;
            if (!class_exists($class)) { continue; }
            $client = new $class(['enableRateLimit' => true,'timeout' => $this->timeoutMs,'number' => 'strval']);
            $this->providers[] = $client;
            $this->created[$name] = $client;
        }
        foreach ($this->providers as $provider) {
            if ($this->closed) { return null; }
            $id = spl_object_id($provider);
            try {
                if (!array_key_exists($id, $this->loaded)) {
                    $this->loaded[$id] = false;
                    $this->read($provider->id ?? 'exchange', 'load_markets', null, null, null, fn () => $provider->load_markets());
                    $this->loaded[$id] = true;
                }
                if (!$this->loaded[$id]) { continue; }
                $price = $this->rate($provider, $currency, $quote, $timestamp);
                if ($price !== null) {
                    return ['price' => $price,'source' => 'ccxt:'.$provider->id,'quote' => $quote,'at_ms' => $timestamp,'estimated' => true];
                }
                foreach (['USD','USDT','BTC'] as $bridge) {
                    if (in_array($bridge, [$currency,$quote], true)) { continue; }
                    $a = $this->rate($provider, $currency, $bridge, $timestamp);
                    if ($a === null) { continue; } // A missing first leg cannot be repaired by fetching the second.
                    $b = $this->rate($provider, $bridge, $quote, $timestamp);
                    if ($b !== null) {
                        return ['price' => Numbers::decimal(bcmul($a, $b, 24)),'source' => 'ccxt:'.$provider->id,'quote' => $quote,'via' => $bridge,'at_ms' => $timestamp,'estimated' => true];
                    }
                }
            } catch (Throwable) { /* Next source; never substitute today's price. */ }
        }
        $id = $this->account['coingecko_ids'][$currency] ?? null;
        if (!$this->closed && is_string($id) && preg_match('/^[a-z0-9-]{1,100}$/D', $id)) {
            try {
                $browser = (new \React\Http\Browser())->withTimeout($this->timeoutMs / 1000);
                $headers = ['Accept' => 'application/json'];
                if (isset($this->account['coingecko_api_key_env'])) {
                    $key = \Trademinator\Client\M2\Credentials::read($this->account['coingecko_api_key_env'], null);
                    \Trademinator\Client\M1\EventRecord::hideSecret($key);
                    $headers['x-cg-demo-api-key'] = $key;
                }
                $date = gmdate('d-m-Y', intdiv($timestamp, 1000));
                $response = $this->read('coingecko', 'fetch_history', $currency.'/'.$quote, '1d', $timestamp,
                    fn () => $browser->get('https://api.coingecko.com/api/v3/coins/'.$id.'/history?date='.$date.'&localization=false', $headers));
                $data = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
                $price = Numbers::decimal($data['market_data']['current_price'][strtolower($quote)] ?? null);
                if (bccomp($price, '0', 24) > 0) {
                    return ['price' => $price,'source' => 'coingecko:'.$id,'quote' => $quote,'at_ms' => $timestamp,'date' => $date,'estimated' => true];
                }
            } catch (Throwable) { /* Operator basis is the final fallback. */ }
        }
        return null;
    }
    private function rate(object $client, string $base, string $quote, int $timestamp): ?string
    {
        if ($base === $quote) { return '1'; }
        foreach ([[$base.'/'.$quote,false],[$quote.'/'.$base,true]] as [$symbol,$inverse]) {
            if ($this->closed) { return null; }
            if (!isset($client->markets[$symbol]) || !($client->has['fetchOHLCV'] ?? false)) { continue; }
            foreach (['1m' => 60000,'1h' => 3600000,'1d' => 86400000] as $period => $width) {
                if ($this->closed) { return null; }
                if (!isset($client->timeframes[$period])) { continue; }
                $start = intdiv($timestamp, $width) * $width;
                $key = spl_object_id($client).':'.$symbol.':'.$period.':'.$start;
                if (!array_key_exists($key, $this->rates)) {
                    $price = null;
                    try {
                        $bars = $this->read($client->id ?? 'exchange', 'fetch_ohlcv', $symbol, $period, $start,
                            fn () => $client->fetch_ohlcv($symbol, $period, $start, 2));
                        foreach ($bars as $bar) {
                            if ((int)$bar[0] !== $start) { continue; }
                            $p = Numbers::decimal($bar[4]);
                            if (bccomp($p, '0', 24) > 0) { $price = $p; break; }
                        }
                    } catch (Throwable) { /* This historical candle remains unavailable. */ }
                    $this->remember($this->rates, $key, $price);
                }
                $price = $this->rates[$key];
                if ($price !== null) { return $inverse ? Numbers::decimal(bcdiv('1', $price, 24)) : $price; }
            }
        }
        return null;
    }
    private function read(string $provider, string $operation, ?string $symbol, ?string $period, ?int $since, callable $call): mixed
    {
        if ($this->closed) { throw new \RuntimeException('Price reader is closed.'); }
        ++$this->started;
        $this->request = ['provider' => preg_match('/^[a-z][a-z0-9]{0,40}$/D', $provider) ? $provider : 'exchange',
            'operation' => $operation, 'symbol' => $symbol, 'period' => $period, 'since_ms' => $since,
            'started_at_ms' => (int)floor(microtime(true) * 1000), 'in_flight' => true];
        try {
            // Bounds the complete async method, including CCXT throttling/market loading.
            $this->pending = $this->bounded(resolve($call()));
            return await($this->pending);
        } finally {
            ++$this->finished;
            $this->request['in_flight'] = false;
            $this->pending = null;
        }
    }
    /** Always settle cancellation and clear its timer, even for non-cancellable adapters. */
    private function bounded(PromiseInterface $source): PromiseInterface
    {
        $done = false;
        $timer = null;
        $result = null;
        $result = new Deferred(function () use ($source, &$done, &$timer, &$result): void {
            if ($done) { return; }
            $done = true;
            if ($timer !== null) { Loop::cancelTimer($timer); }
            $source->cancel();
            $result->reject(new \RuntimeException('Price request cancelled.'));
        });
        $timer = Loop::addTimer($this->timeoutMs / 1000, function () use ($source, &$done, $result): void {
            if ($done) { return; }
            $done = true;
            $source->cancel();
            $result->reject(new \RuntimeException('Historical price request timed out.'));
        });
        $source->then(function ($value) use (&$done, $timer, $result): void {
            if ($done) { return; }
            $done = true;
            Loop::cancelTimer($timer);
            $result->resolve($value);
        }, function (Throwable $error) use (&$done, $timer, $result): void {
            if ($done) { return; }
            $done = true;
            Loop::cancelTimer($timer);
            $result->reject($error);
        });
        return $result->promise();
    }
    private function remember(array &$cache, string $key, mixed $value): void
    {
        if (count($cache) >= self::CACHE_LIMIT) { array_shift($cache); }
        $cache[$key] = $value;
    }
    public function progress(?int $now = null): array
    {
        $result = ['requests_started' => $this->started, 'requests_finished' => $this->finished];
        if ($this->request !== null) {
            $result['request'] = $this->request;
            $result['request']['elapsed_ms'] = max(0, ($now ?? (int)floor(microtime(true) * 1000)) - $this->request['started_at_ms']);
        }
        return $result;
    }
    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->pending?->cancel();
        foreach ($this->created as $provider) {
            try { $provider->close(); } catch (Throwable) { /* Keep the original sync result. */ }
        }
        $this->created = [];
    }
}
