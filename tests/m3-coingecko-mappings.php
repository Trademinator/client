<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use React\Http\Message\Response;
use React\Promise\Deferred;
use Trademinator\Client\M1\{Config,Database,Ledger};
use Trademinator\Client\M2\{ServerApi,Transport};
use Trademinator\Client\M3\{CoinGeckoMappings,Prices,Runtime,Settings};
use function React\Async\await;
use function React\Promise\resolve;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException($label); } ++$checks; echo 'PASS '.$label.PHP_EOL; }
final class MappingTransport
{
    public array $requests = [];
    public array $responses = [];
    public int $cancelled = 0;
    public function start(string $requestId, string $path): void { $this->requests[] = ['request_id' => $requestId, 'path' => $path]; }
    public function poll(): array { $rows = $this->responses; $this->responses = []; return $rows; }
    public function pending(): int { return 0; }
    public function cancelAll(): void { ++$this->cancelled; }
    public function reply(array $rows, ?string $next = null, int $status = 200, array $headers = []): void
    {
        $this->responses[] = ['request_id' => end($this->requests)['request_id'], 'status' => $status, 'headers' => $headers,
            'body' => json_encode(['api_version' => 1, 'mappings' => $rows, 'next_cursor' => $next]), 'error' => null];
    }
}
function mappingRow(int $id = 1, string $coin = 'cosmos', string $exchange = 'bitso', string $symbol = 'ATOM/USD'): array
{
    [$base,$quote] = explode('/', $symbol);
    return ['mapping_id' => '00000000-0000-4000-8000-'.str_pad((string)$id, 12, '0', STR_PAD_LEFT),
        'exchange' => $exchange, 'symbol' => $symbol, 'base_symbol' => $base, 'vs_currency' => strtolower($quote),
        'status' => 'resolved', 'coin_id' => $coin, 'manually_mapped' => true, 'updated_at' => '2026-10-21T00:00:00.000000Z'];
}
function fixture(int &$now, array &$events): array
{
    $transport = new MappingTransport();
    $maps = new CoinGeckoMappings(['enabled' => true], new ServerApi($transport), function () use (&$now): int { return $now; },
        function ($level,$event,$context,$message) use (&$events): void { $events[] = compact('level','event','context','message'); });
    return [$maps,$transport];
}
$now = 1000; $events = []; [$maps,$transport] = fixture($now,$events);
$maps->tick();
check($transport->requests[0]['path'] === '/api/v1/client/coingecko-mappings', 'mapping discovery uses the authenticated Server API path');
$finished = false; $maps->ready()->then(function () use (&$finished): void { $finished = true; });
$transport->reply([mappingRow()], mappingRow()['mapping_id']); $maps->tick();
check(!$finished && $maps->coinId('bitso','ATOM','USD') === null, 'a partial catalogue is never used for historical pricing');
$now += 1000; $maps->tick();
check(end($transport->requests)['path'] === '/api/v1/client/coingecko-mappings?after='.mappingRow()['mapping_id'], 'subsequent pages use the Server cursor');
$transport->reply([mappingRow(2,'other-asset','kraken')]); $maps->tick();
check($finished && $maps->coinId('bitso','ATOM','USD') === 'cosmos' && $maps->coinId('kraken','ATOM','USD') === 'other-asset', 'complete mappings preserve exchange-specific asset identities');
check($maps->coinId('bitso','ATOM','MXN') === null && $maps->coinId('coinbase','ATOM','USD') === null, 'a quote or exchange mismatch cannot borrow another mapping');
$now += 300000;
check($maps->coinId('bitso','ATOM','USD') === null, 'expired mappings cannot be used even before the next poll');
$maps->tick(); $transport->reply([mappingRow(1,'corrected-asset')]); $maps->tick();
check($maps->coinId('bitso','ATOM','USD') === 'corrected-asset' && $maps->coinId('kraken','ATOM','USD') === null, 'refresh replaces corrections and removals rather than merging stale entries');
$now += 300000; $maps->tick(); $transport->reply([]); $maps->tick();
check($maps->status()['state'] === 'ready' && $maps->coinId('bitso','ATOM','USD') === null, 'an empty successful catalogue revokes all old mappings');
$now += 300000; $maps->tick(); $transport->reply([], null, 429, ['retry-after' => '120']); $maps->tick();
$count = count($transport->requests); $now += 119999; $maps->tick();
check(count($transport->requests) === $count && $maps->status()['state'] === 'unavailable', 'rate limiting honors Retry-After without a request storm');
$now++; $maps->tick(); $transport->reply([mappingRow()]); $maps->tick();
check($maps->coinId('bitso','ATOM','USD') === 'cosmos', 'transient failure recovers automatically');
$now += 300000; $maps->tick(); $transport->reply([],null,404); $maps->tick();
check($maps->coinId('bitso','ATOM','USD') === null && $maps->status()['error'] === 'http_404', 'an older Server disables the fallback instead of keeping stale local identities');
check(end($events)['event'] === 'coingecko_mappings_unavailable', 'unavailable mappings have an actionable status and warning');

foreach (['pending','ambiguous','unmapped','unsupported'] as $state) {
    $now = 1000; $events = []; [$maps,$transport] = fixture($now,$events); $maps->tick();
    $row = mappingRow(); $row['status'] = $state; $row['coin_id'] = null;
    $transport->reply([$row]); $maps->tick();
    check($maps->status()['state'] === 'ready' && $maps->coinId('bitso','ATOM','USD') === null, $state.' mapping never supplies an asset ID');
}
$badRows = [
    'duplicate pair' => [mappingRow(),mappingRow(2)],
    'inconsistent quote' => [array_replace(mappingRow(),['vs_currency' => 'mxn'])],
    'unsafe coin ID' => [mappingRow(1,'../secret')],
    'stale unresolved ID' => [array_replace(mappingRow(),['status' => 'ambiguous'])],
    'descending IDs' => [mappingRow(2),mappingRow(1,'other','kraken')],
];
foreach ($badRows as $label => $rows) {
    $now = 1000; $events = []; [$maps,$transport] = fixture($now,$events); $maps->tick();
    $transport->reply($rows); $maps->tick();
    check($maps->status()['error'] === 'invalid_mapping_catalogue' && $maps->coinId('bitso','ATOM','USD') === null, $label.' rejects the whole catalogue');
}
$now = 1000; $events = []; [$maps,$transport] = fixture($now,$events); $maps->tick();
$transport->reply([mappingRow()],mappingRow()['mapping_id']); $maps->tick(); $now += 1000; $maps->tick();
$transport->reply([mappingRow()],mappingRow()['mapping_id']); $maps->tick();
check($maps->status()['state'] === 'unavailable', 'a repeated cursor cannot loop or publish partial mappings');
$now = 1000; $events = []; [$maps,$transport] = fixture($now,$events); $maps->tick();
$finished = false; $maps->ready()->then(function () use (&$finished): void { $finished = true; });
$now += 60000; $maps->tick();
check($finished && $maps->status()['error'] === 'refresh_timeout' && $transport->cancelled === 1, 'a stalled refresh releases waiting pricing work within a bounded deadline');

final class MappingPriceExchange
{
    public string $id = 'bitso';
    public array $markets = [];
    public array $has = ['fetchOHLCV' => true];
    public array $timeframes = ['1m' => '1m'];
    public function load_markets(): object { return resolve($this->markets); }
    public function fetch_ohlcv(string $symbol, string $period, int $since, int $limit): object { return resolve([[$since,'7','7','7','7','1']]); }
}
final class MappingHistoryBrowser
{
    public array $urls = [];
    public ?Deferred $pending = null;
    public function get(string $url, array $headers): object
    {
        $this->urls[] = $url;
        return $this->pending?->promise() ?? resolve(new Response(200, [], '{"market_data":{"current_price":{"usd":10}}}'));
    }
}
$now = 1000; $events = []; [$maps,$transport] = fixture($now,$events);
$exchange = new MappingPriceExchange(); $browser = new MappingHistoryBrowser();
$legacy = ['public_price_exchanges' => [], 'coingecko_ids' => ['ATOM' => 'WRONG-LOCAL-ASSET']];
$prices = new Prices($exchange,$legacy,1000,$maps,$browser); $time = 1700000000100;
$result = 'waiting'; $prices->estimate('ATOM','USD',$time)->then(function ($value) use (&$result): void { $result = $value; });
check($result === 'waiting' && $browser->urls === [], 'historical fallback waits for initial discovery instead of using config IDs');
$transport->reply([mappingRow()]); $maps->tick();
check($result['source'] === 'coingecko:cosmos' && $result['price'] === '10' && $result['estimated'], 'actual price fallback uses the Server identity and preserves estimated attribution');
check($browser->urls === ['https://api.coingecko.com/api/v3/coins/cosmos/history?date=14-11-2023&localization=false'], 'historical request retains the deposit date');
await($prices->estimate('ATOM','USD',$time)); $calls = count($browser->urls);
await($prices->estimate('ATOM','USD',$time));
check(count($browser->urls) === $calls, 'unchanged authoritative estimates remain cached');
$now += 300000; $maps->tick(); $transport->reply([mappingRow(1,'corrected-asset')]); $maps->tick();
check(await($prices->estimate('ATOM','USD',$time))['source'] === 'coingecko:corrected-asset', 'cached estimates follow owner corrections');
$now += 300000; $maps->tick(); $transport->reply([]); $maps->tick();
check(await($prices->estimate('ATOM','USD',$time)) === null, 'removal invalidates cached CoinGecko estimates within an active price reader');
$exchange->markets = ['ATOM/USD' => ['symbol' => 'ATOM/USD']];
$exchangePrices = new Prices($exchange,$legacy,1000,$maps,$browser);
check(await($exchangePrices->estimate('ATOM','USD',$time))['source'] === 'ccxt:bitso', 'exchange historical prices remain available without a CoinGecko mapping');
$exchange->markets = []; $prices->close();
$localOnly = new Prices($exchange,$legacy,1000,null,$browser); $calls = count($browser->urls);
check(await($localOnly->estimate('ATOM','USD',$time)) === null && count($browser->urls) === $calls, 'legacy local IDs cannot bypass a disabled or missing Server');
$localOnly->close(); $exchangePrices->close();
$now += 300000; $maps->tick(); $transport->reply([mappingRow()]); $maps->tick();
$browser->pending = new Deferred(); $prices = new Prices($exchange,$legacy,1000,$maps,$browser);
$result = 'waiting'; $prices->estimate('ATOM','USD',$time)->then(function ($value) use (&$result): void { $result = $value; });
$now += 300000; $maps->tick(); $transport->reply([]); $maps->tick();
$browser->pending->resolve(new Response(200, [], '{"market_data":{"current_price":{"usd":10}}}'));
check($result === null, 'a mapping revoked during HTTP lookup cannot publish a price'); $prices->close();

$config = new Config(['exchange' => ['accounts' => [['exchange' => 'bitso', 'api_key_env' => 'UNUSED', 'secret_env' => 'UNUSED', 'coingecko_ids' => ['ATOM' => 'cosmos']]]]]);
check(!array_key_exists('coingecko_ids', $config->exchange['accounts'][0]), 'legacy mapping config is accepted for upgrade and removed from normalized settings');
foreach (['config.example.json','config.m3.example.json'] as $name) {
    check(!str_contains(file_get_contents(dirname(__DIR__).'/'.$name), 'coingecko_ids'), $name.' requires no CoinGecko mappings');
}
$transport = new MappingTransport(); $disabled = new CoinGeckoMappings(['enabled' => false],new ServerApi($transport)); $disabled->tick();
check($transport->requests === [] && $disabled->status()['state'] === 'disabled', 'disabled Server integration performs no mapping requests');
$maps->close(); check($maps->coinId('bitso','ATOM','USD') === null, 'shutdown clears cached identities');
$now = 1000; $events = []; [$replacement,$replacementTransport] = fixture($now,$events);
check($replacement->coinId('bitso','ATOM','USD') === null, 'a replacement runtime starts without identities from prior Server credentials');
$db = new Database(':memory:'); $id = (new Ledger($db))->createAccount('bitso','Mapping runtime');
$settings = Settings::validate(['accounts' => [['account_id' => $id,'api_key_env' => 'UNUSED','secret_env' => 'UNUSED','history_since_ms' => 0]]]);
$adapter = new class {
    public function wsCapabilities(): array { return []; }
    public function snapshot(array $context): object { return (new Deferred())->promise(); }
    public function close(): void {}
};
$runtime = new Runtime($db,$settings,fn() => $adapter,mappings: $replacement); $runtime->tick();
check(count($replacementTransport->requests) === 1, 'M3 daemon ticks drive automatic discovery without a config mapping section');
$replacementTransport->reply([mappingRow()]); $runtime->tick();
check($runtime->status()['coingecko_mappings']['state'] === 'ready', 'exchange status exposes Server mapping readiness');
$runtime->close(); check($replacement->status()['state'] === 'closed', 'runtime shutdown cancels its mapping reader');

$http = new Transport('http://127.0.0.1:1','fixture-only-token',100,allowLoopbackHttp: true);
foreach (['/api/v1/client/coingecko-mappings','/api/v1/client/coingecko-mappings?after='.mappingRow()['mapping_id']] as $i => $path) {
    $http->start('allowed-'.$i,$path); $http->cancelAll();
}
check($http->pending() === 0, 'transport accepts only the intended mapping endpoint and cursor form');
foreach (['?after=../secrets','?after='.mappingRow()['mapping_id'].'&url=https://elsewhere','/extra'] as $suffix) {
    $rejected = false;
    try { $http->start('bad','/api/v1/client/coingecko-mappings'.$suffix); } catch (InvalidArgumentException) { $rejected = true; }
    check($rejected, 'transport rejects unsupported mapping path '.$suffix);
}
echo json_encode(['checks' => $checks,'status' => 'passed']).PHP_EOL;
