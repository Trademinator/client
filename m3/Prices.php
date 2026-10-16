<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use Throwable;
use React\Promise\PromiseInterface;

use function React\Async\async;
use function React\Async\await;

/** Estimated deposit basis, explicitly attributed to a historical source and quote currency. */
final class Prices
{
    public function __construct(private object $receiving, private array $account, private int $timeoutMs)
    {
    }
    public function estimate(string $currency, string $quote, int $timestamp): PromiseInterface
    {
        return async(function () use ($currency, $quote, $timestamp): ?array {
            if ($currency === $quote) {
                return ['price' => '1','source' => 'quote_currency','quote' => $quote,'at_ms' => $timestamp,'estimated' => true];
            }
            $providers = [$this->receiving];
            $created = [];
            try {
                foreach ($this->account['public_price_exchanges'] as $name) {
                    $class = '\\ccxt\\async\\'.$name;
                    if (!class_exists($class)) {
                        continue;
                    }
                    $client = new $class(['enableRateLimit' => true,'timeout' => $this->timeoutMs,'number' => 'strval']);
                    $providers[] = $client;
                    $created[] = $client;
                }
                foreach ($providers as $provider) {
                    try {
                        await($provider->load_markets());
                        $price = $this->rate($provider, $currency, $quote, $timestamp);
                        if ($price !== null) {
                            return ['price' => $price,'source' => 'ccxt:'.$provider->id,'quote' => $quote,'at_ms' => $timestamp,'estimated' => true];
                        }
                        foreach (['USD','USDT','BTC'] as $bridge) {
                            if (in_array($bridge, [$currency,$quote], true)) {
                                continue;
                            }
                            $a = $this->rate($provider, $currency, $bridge, $timestamp);
                            $b = $this->rate($provider, $bridge, $quote, $timestamp);
                            if ($a !== null && $b !== null) {
                                return ['price' => Numbers::decimal(bcmul($a, $b, 24)),'source' => 'ccxt:'.$provider->id,'quote' => $quote,'via' => $bridge,'at_ms' => $timestamp,'estimated' => true];
                            }
                        }
                    } catch (Throwable) { /* Next source; never substitute today's price. */
                    }
                }
                $id = $this->account['coingecko_ids'][$currency] ?? null;
                if (is_string($id) && preg_match('/^[a-z0-9-]{1,100}$/D', $id)) {
                    try {
                        $browser = (new \React\Http\Browser())->withTimeout($this->timeoutMs / 1000);
                        $headers = ['Accept' => 'application/json'];
                        if (isset($this->account['coingecko_api_key_env'])) {
                            $key = \Trademinator\Client\M2\Credentials::read($this->account['coingecko_api_key_env'], null);
                            \Trademinator\Client\M1\EventRecord::hideSecret($key);
                            $headers['x-cg-demo-api-key'] = $key;
                        }
                        $date = gmdate('d-m-Y', intdiv($timestamp, 1000));
                        $response = await($browser->get('https://api.coingecko.com/api/v3/coins/'.$id.'/history?date='.$date.'&localization=false', $headers));
                        $data = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
                        $price = Numbers::decimal($data['market_data']['current_price'][strtolower($quote)] ?? null);
                        if (bccomp($price, '0', 24) > 0) {
                            return ['price' => $price,'source' => 'coingecko:'.$id,'quote' => $quote,'at_ms' => $timestamp,'date' => $date,'estimated' => true];
                        }
                    } catch (Throwable) { /* Operator basis is the final fallback. */
                    }
                }
                return null;
            } finally {
                foreach ($created as $provider) {
                    $provider->close();
                }
            }
        })();
    }
    private function rate(object $client, string $base, string $quote, int $timestamp): ?string
    {
        if ($base === $quote) {
            return '1';
        }
        foreach ([[$base.'/'.$quote,false],[$quote.'/'.$base,true]] as [$symbol,$inverse]) {
            if (!isset($client->markets[$symbol]) || !($client->has['fetchOHLCV'] ?? false)) {
                continue;
            }
            foreach (['1m' => 60000,'1h' => 3600000,'1d' => 86400000] as $period => $width) {
                if (!isset($client->timeframes[$period])) {
                    continue;
                }
                try {
                    $start = intdiv($timestamp, $width) * $width;
                    $bars = await($client->fetch_ohlcv($symbol, $period, $start, 2));
                    foreach ($bars as $bar) {
                        if ((int)$bar[0] !== $start) {
                            continue;
                        }$p = Numbers::decimal($bar[4]);
                        if (bccomp($p, '0', 24) > 0) {
                            return $inverse ? Numbers::decimal(bcdiv('1', $p, 24)) : $p;
                        }
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }
        return null;
    }
}
