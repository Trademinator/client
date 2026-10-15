<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use React\Dns\Model\Message;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;
use Trademinator\Client\M0\Config;
use Trademinator\Client\M0\Decimal;
use Trademinator\Client\M0\LocalControl;
use Trademinator\Client\M0\MarketData;
use Trademinator\Client\M0\MathProbe;
use Trademinator\Client\M0\ResilientFeed;
use Trademinator\Client\M0\ServerApi;
use Trademinator\Client\M0\StateStore;
use Trademinator\Client\M0\SystemResolver;
use function React\Async\async;
use function React\Async\await;
use function React\Promise\reject;
use function React\Promise\resolve;
use function React\Promise\Timer\timeout;

$tests = [];
function test(string $name, Closure $test): void { global $tests; $tests[$name] = $test; }
function same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) { throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true)); }
}
function yes(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function raises(Closure $operation): void {
    try { $operation(); } catch (Throwable) { return; }
    throw new RuntimeException('Expected an exception.');
}
function quote(): array { return ['symbol' => 'XRP/CAD', 'timestamp' => 1700000000000, 'bid' => '2.000000000000000001', 'ask' => '2.000000000000000002']; }
function risk(): array { return ['api_version' => 1, 'risk_factor' => '0.2500000000000000', 'algorithm_version' => 'questionnaire-risk-v1', 'source' => 'default']; }

final class FakeMarket implements MarketData
{
    public int $watched = 0;
    public int $fetched = 0;
    public int $resets = 0;
    public int $restResets = 0;
    public bool $supports = true;
    public array $watchResults = [];
    public mixed $restResult;
    public function __construct() { $this->restResult = quote(); }
    public function supportsWebSocket(): bool { return $this->supports; }
    public function watchTicker(string $symbol): PromiseInterface {
        $this->watched++;
        $result = array_shift($this->watchResults) ?? quote();
        if ($result instanceof Throwable) { return reject($result); }
        return $result instanceof PromiseInterface ? $result : resolve($result);
    }
    public function fetchTicker(string $symbol): PromiseInterface {
        $this->fetched++;
        return $this->restResult instanceof Throwable ? reject($this->restResult) : resolve($this->restResult);
    }
    public function resetWebSocket(): void { $this->resets++; }
    public function resetRest(): void { $this->restResets++; }
    public function close(): void {}
}

test('shared packages retain tiny decimal EMA/SMA and the agreed sizing fixture', function (): void {
    same('passed', MathProbe::run()['status']);
    same('0.00000000000000000001', Decimal::positive('1e-20'));
    raises(fn() => Decimal::positive('0'));
    raises(fn() => Decimal::positive(INF));
    raises(fn() => Decimal::positive('1e999999999'));
});

test('WebSocket is preferred and decimals remain strings', function (): void {
    $source = new FakeMarket();
    $feed = new ResilientFeed($source, 'XRP/CAD', 0.1, 30);
    $value = $feed->next();
    same('2.000000000000000001', $value['bid']);
    same('websocket', $feed->transport);
    same(0, $source->fetched);
    $feed->close();
});

test('disconnect falls back, cooldown avoids reconnect storms, then WebSocket recovers', function (): void {
    $source = new FakeMarket();
    $source->watchResults = [new RuntimeException('disconnect'), quote()];
    $now = 0.0;
    $feed = new ResilientFeed($source, 'XRP/CAD', 0.1, 30, static function () use (&$now): float { return $now; });
    $feed->next();
    $feed->next();
    same(1, $source->watched); same(2, $source->fetched); same(1, $source->resets);
    $now = 31.0;
    $feed->next();
    same('websocket', $feed->transport); same(1, $feed->webSocketFailures);
    $feed->close();
});

test('a stalled WebSocket is bounded, cancelled, and replaced by REST', function (): void {
    $cancelled = false;
    $source = new FakeMarket();
    $source->watchResults = [new Promise(static function (): void {}, static function () use (&$cancelled): void { $cancelled = true; })];
    $feed = new ResilientFeed($source, 'XRP/CAD', 0.02, 30);
    await(async(fn() => $feed->next())());
    same('rest', $feed->transport); same(true, $cancelled); same(1, $source->resets);
    $feed->close();
});

test('REST-only exchanges are supported without false WebSocket failures', function (): void {
    $source = new FakeMarket(); $source->supports = false;
    $feed = new ResilientFeed($source, 'XRP/CAD', 0.1, 30);
    $feed->next(); same(0, $source->watched); same(0, $feed->webSocketFailures);
    $source->restResult = new RuntimeException('offline');
    raises(fn() => $feed->next()); same(1, $feed->restFailures);
    same(1, $source->restResets);
    $source->restResult = quote();
    same('XRP/CAD', $feed->next()['symbol']);
    $feed->close();
});

test('malformed, crossed and mismatched prices cannot be persisted as healthy data', function (): void {
    foreach ([['symbol' => 'BTC/CAD'], ['bid' => '-1'], ['bid' => '3', 'ask' => '2'], ['timestamp' => 'yesterday']] as $bad) {
        $source = new FakeMarket();
        $source->watchResults = [array_replace(quote(), $bad)];
        $source->restResult = array_replace(quote(), $bad);
        $feed = new ResilientFeed($source, 'XRP/CAD', 0.1, 30);
        raises(fn() => $feed->next()); same(1, $feed->webSocketFailures); $feed->close();
    }
});

test('risk factors come from the Server and invalid responses never become defaults', function (): void {
    same('0.2500000000000000', ServerApi::validateRisk(risk())['risk_factor']);
    foreach ([['risk_factor' => 0.25], ['risk_factor' => '1.00000000000000000001'], ['risk_factor' => '-0.1'],
        ['source' => 'cached'], ['algorithm_version' => 'future-v2'], ['api_version' => 2], ['risk_factor' => '0.5']] as $bad) {
        raises(fn() => ServerApi::validateRisk(array_replace(risk(), $bad)));
    }
    same('0', ServerApi::validateRisk(array_replace(risk(), ['risk_factor' => '0', 'source' => 'questionnaire']))['risk_factor']);
});

test('configuration rejects public HTTP, URL credentials, unknown fields and invalid polling', function (): void {
    foreach ([['server_url' => 'http://example.com'], ['server_url' => 'https://user:secret@example.com'],
        ['server_url' => 'https://example.com?token=secret'], ['ipc_host' => '0.0.0.0'], ['interval_ms' => 0],
        ['exchange' => '../kraken'], ['retention' => 1], ['mode' => 'paper']] as $bad) {
        raises(fn() => new Config($bad));
    }
    new Config(['server_url' => 'http://127.0.0.1:8000']);
    new Config(['server_url' => 'https://example.com/trademinator']);
});

test('system resolver follows the PHP native IPv4 resolver without public DNS fallback', function (): void {
    $resolver = new SystemResolver();
    $address = await($resolver->resolve('localhost'));
    yes(@inet_pton($address) !== false && !str_contains($address, ':'), 'Native resolver did not return IPv4.');
    same([$address], await($resolver->resolveAll('localhost', Message::TYPE_A)));
    raises(fn() => await($resolver->resolveAll('localhost', Message::TYPE_AAAA)));
});

$testDir = sys_get_temp_dir().'/trademinator-m0-'.bin2hex(random_bytes(8));
mkdir($testDir, 0700, true);

test('SQLite retains exact decimals across reopen and prunes old samples', function () use ($testDir): void {
    $file = $testDir.'/state.sqlite';
    $store = new StateStore($file, 10);
    for ($i = 0; $i < 23; $i++) { $store->append('run-one', 'rest', quote()); }
    $store->set('server', risk());
    unset($store);
    $reopened = new StateStore($file, 10);
    same(10, $reopened->count()); same('2.000000000000000001', $reopened->latest()['bid']);
    same(risk(), $reopened->get('server'));
    $reopened->append('run-two', 'websocket', quote()); same(10, $reopened->count());
});

function localRequest(int $port, string $request): array {
    $connection = await((new Connector())->connect('127.0.0.1:'.$port));
    $pending = new Promise(function (Closure $resolve, Closure $reject) use ($connection, $request): void {
        $buffer = '';
        $connection->on('data', static function (string $chunk) use (&$buffer): void { $buffer .= $chunk; });
        $connection->on('close', static function () use (&$buffer, $resolve): void { $resolve($buffer); });
        $connection->on('error', $reject);
        $connection->write(substr($request, 0, 3));
        Loop::addTimer(0.001, static fn() => $connection->write(substr($request, 3)));
    });
    try { $reply = await(timeout($pending, 3)); }
    finally { $connection->close(); }
    return json_decode($reply, true, 32, JSON_THROW_ON_ERROR);
}

test('TCP control requires token, handles fragmented input, and only accepts status/stop', function (): void {
    $stopped = false;
    $server = new LocalControl(0, 'test-secret', static fn(): array => ['fixture' => true], static function () use (&$stopped): void { $stopped = true; });
    try {
        await(async(function () use ($server, &$stopped): void {
            same(false, localRequest($server->port(), "{\"command\":\"status\",\"token\":\"wrong\"}\n")['ok']);
            same(true, localRequest($server->port(), "{\"command\":\"status\",\"token\":\"test-secret\"}\n")['status']['fixture']);
            same('unknown_command', localRequest($server->port(), "{\"command\":\"buy\",\"token\":\"test-secret\"}\n")['error']);
            same(true, localRequest($server->port(), "{\"command\":\"stop\",\"token\":\"test-secret\"}\n")['ok']);
            same(true, $stopped);
        })());
    } finally { $server->close(); }
});

test('Server HTTP contract uses bearer GETs and rejects redirects/auth failures/malformed JSON', function (): void {
    $mode = 'ok'; $requests = []; $redirectHits = 0;
    $socket = new SocketServer('127.0.0.1:0');
    $origin = str_replace('tcp://', 'http://', $socket->getAddress());
    $http = new HttpServer(function ($request) use (&$mode, &$requests, &$redirectHits, $origin) {
        $path = $request->getUri()->getPath();
        if ($path === '/trap') { $redirectHits++; }
        $requests[] = [$request->getMethod(), $path, $request->getHeaderLine('Authorization')];
        if ($mode === 'redirect') { return new Response(302, ['Location' => $origin.'/trap']); }
        if ($mode === 'auth') { return new Response(401, [], '{"message":"secret must not be echoed"}'); }
        if ($mode === 'json') { return new Response(200, [], '<html>not json</html>'); }
        $payload = $path === '/api/v1/client/markets' ? ['api_version' => 1, 'markets' => []] : risk();
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload));
    });
    $http->listen($socket);
    try {
        await(async(function () use ($origin, &$mode, &$requests, &$redirectHits): void {
            $api = new ServerApi($origin, 'test-token', 1);
            $snapshot = $api->snapshot();
            same('available', $snapshot['status']); same('0.2500000000000000', $snapshot['risk_factor']);
            same([['GET', '/api/v1/client/markets', 'Bearer test-token'], ['GET', '/api/v1/client/risk-factor', 'Bearer test-token']], $requests);
            foreach (['redirect', 'auth', 'json'] as $failure) { $mode = $failure; raises(fn() => $api->snapshot()); }
            same(0, $redirectHits);
        })());
    } finally { $socket->close(); }
});

test('daemon CLI stops at sample limit and restart preserves database rows', function () use ($testDir): void {
    $config = $testDir.'/with spaces.json';
    file_put_contents($config, json_encode(['data_dir' => 'daemon data', 'ipc_port' => 0, 'interval_ms' => 10]));
    for ($i = 1; $i <= 2; $i++) {
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/m0.php', 'run', '--config='.$config, '--samples=3', '--seconds=5'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        same(0, proc_close($process)); same('', $error);
        $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        same(3, $report['samples']); same('stopped', $report['state']);
        same($i * 3, (new StateStore($testDir.'/daemon data/m0.sqlite', 1000))->count());
        yes(!is_file($testDir.'/daemon data/control.json'), 'Control file was not removed on shutdown.');
    }
});

test('the installed CCXT package provides the real Kraken REST and Pro adapters', function (): void {
    $source = new Trademinator\Client\M0\CcxtMarketData('kraken', 1);
    same(true, $source->supportsWebSocket());
    $source->close();
});

test('daemon status/stop work across processes and a second daemon cannot share its state', function () use ($testDir): void {
    $config = $testDir.'/control-config.json';
    $data = $testDir.'/control-data';
    file_put_contents($config, json_encode(['data_dir' => $data, 'ipc_port' => 0, 'interval_ms' => 20]));
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/m0.php', 'run', '--config='.$config, '--seconds=10'],
        [1 => ['file', $testDir.'/control-out.json', 'w'], 2 => ['file', $testDir.'/control-err.log', 'w']], $pipes);
    try {
        $deadline = microtime(true) + 5;
        while (!is_file($data.'/control.json') && microtime(true) < $deadline) { usleep(10000); }
        yes(is_file($data.'/control.json'), 'Daemon did not start.');
        if (PHP_OS_FAMILY !== 'Windows') { same(0600, fileperms($data.'/control.json') & 0777); }
        foreach (['status', 'run', 'stop'] as $command) {
            $args = [PHP_BINARY, dirname(__DIR__).'/bin/m0.php', $command, '--config='.$config];
            if ($command === 'run') { $args[] = '--seconds=1'; }
            $child = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $streams);
            $out = stream_get_contents($streams[1]); $err = stream_get_contents($streams[2]);
            fclose($streams[1]); fclose($streams[2]);
            same($command === 'run' ? 1 : 0, proc_close($child));
            if ($command === 'run') { yes(str_contains($err, 'Another M0 daemon'), 'Lock error was not reported.'); }
            else { same(true, json_decode($out, true, 32, JSON_THROW_ON_ERROR)['ok']); }
        }
        same(0, proc_close($process));
        $process = null;
        same('', file_get_contents($testDir.'/control-err.log'));
        clearstatcache(true, $data.'/control.json');
        yes(!is_file($data.'/control.json'), 'Control file remains after stop.');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
});

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo 'PASS '.$name.PHP_EOL; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL); }
}
echo count($tests).' tests, '.$failed.' failures.'.PHP_EOL;
exit($failed > 0 ? 1 : 0);
