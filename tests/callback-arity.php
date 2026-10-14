<?php

declare(strict_types=1);

// Run separately: php tests/callback-arity.php (no Composer required).
// Execute the real callback registration/body code with isolated dependency
// doubles and an explicit arity guard. This is NOT a native or integration test.
require __DIR__.'/fixtures/callback-arity-doubles.php';
require dirname(__DIR__).'/src/LocalControl.php';
require dirname(__DIR__).'/src/Daemon.php';

use React\EventLoop\Loop;
use React\Socket\SocketServer;
use React\Socket\TestConnection;
use Trademinator\Client\M0\Config;
use Trademinator\Client\M0\Daemon;
use Trademinator\Client\M0\LocalControl;
use Trademinator\Client\M0\ServerApi;
use Trademinator\Client\M0\StateStore;
use function M0CallbackTest\expect;
use function M0CallbackTest\invoke;

function callbackDaemon(bool $server, Closure $exercise): array
{
    Loop::reset(); StateStore::$writes = 0; ServerApi::$snapshots = 0;
    $directory = sys_get_temp_dir().'/m0-callback-'.bin2hex(random_bytes(8));
    $config = new Config($directory, $server ? 'live' : 'fixture', $server ? 'https://fixture.invalid' : '');
    Loop::$duringRun = $exercise;
    $level = ob_get_level();
    $oldMask = umask();
    ob_start();
    try {
        $code = (new Daemon($config))->run(6);
        $output = ob_get_clean();
        expect($code === 0, 'Daemon exit code changed.');
        expect(!is_file($directory.'/control.json'), 'Control file not cleaned up.');
        return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    } finally {
        while (ob_get_level() > $level) { ob_end_clean(); }
        Loop::$duringRun = null;
        foreach (['control.json', 'daemon.lock'] as $file) {
            if (is_file($directory.'/'.$file)) { unlink($directory.'/'.$file); }
        }
        if (is_dir($directory)) { rmdir($directory); }
        umask($oldMask);
    }
}

function callbackControl(Closure $exercise): void
{
    Loop::reset();
    $stopped = false;
    $control = new LocalControl(0, 'test-token', static fn(): array => ['fixture' => true],
        static function () use (&$stopped): void { $stopped = true; });
    try { $exercise(SocketServer::$last, $stopped); }
    finally { $control->close(); }
}

$tests = [];
$tests['guard rejects the original zero-parameter timer signature'] = static function (): void {
    try { invoke(static function (): void {}, [new stdClass()], 'negative control'); }
    catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'callback signature'), 'Wrong guard failure.');
        return;
    }
    throw new RuntimeException('Guard accepted a surplus timer argument.');
};
$tests['fixture timers receive one argument and future ticks receive none'] = static function (): void {
    $report = callbackDaemon(false, static function (): void {
        expect(count(Loop::$timers) === 3, 'Expected feed, heartbeat and shutdown timers.');
        expect(count(Loop::$ticks) === 1, 'Expected the initial feed tick.');
        foreach (Loop::$ticks as $tick) { invoke($tick, [], 'future tick'); }
        foreach (Loop::$timers as $timer) { invoke($timer->getCallback(), [$timer], 'daemon timer'); }
        expect(Loop::$stopped, 'Duration timer did not stop the daemon.');
    });
    expect($report['samples'] === 2 && $report['errors'] === 0, 'Feed callback behavior changed.');
    expect(StateStore::$writes >= 3, 'Heartbeat did not write status to the test store.');
};
$tests['Server polling timer accepts its timer argument'] = static function (): void {
    $report = callbackDaemon(true, static function (): void {
        expect(count(Loop::$timers) === 4, 'Expected the additional Server timer.');
        expect(count(Loop::$ticks) === 2, 'Expected initial Server and feed ticks.');
        foreach (Loop::$ticks as $tick) { invoke($tick, [], 'future tick'); }
        foreach (Loop::$timers as $timer) { invoke($timer->getCallback(), [$timer], 'Server-enabled timer'); }
    });
    expect(ServerApi::$snapshots === 2, 'Initial and periodic Server callbacks did not both run.');
    expect($report['server']['status'] === 'available', 'Server callback result lost.');
};
if (extension_loaded('pcntl')) {
    $tests['SIGTERM and SIGINT callbacks accept the signal number'] = static function (): void {
        foreach ([SIGTERM, SIGINT] as $signal) {
            callbackDaemon(false, static function () use ($signal): void {
                expect(isset(Loop::$signals[$signal]), 'Signal callback missing.');
                invoke(Loop::$signals[$signal], [$signal], 'signal');
                expect(Loop::$stopped, 'Signal did not stop the daemon.');
            });
        }
    };
} else {
    echo 'SKIP signal callback registration (pcntl unavailable)'.PHP_EOL;
}
$tests['local-control timeout accepts the timer and closes the connection'] = static function (): void {
    callbackControl(static function (SocketServer $socket, bool &$stopped): void {
        $connection = new TestConnection();
        $socket->emit('connection', [$connection]);
        expect(count(Loop::$timers) === 1, 'Connection deadline missing.');
        $timer = Loop::$timers[0];
        invoke($timer->getCallback(), [$timer], 'connection deadline');
        expect($connection->closed && $timer->cancelled, 'Deadline cleanup failed.');
    });
};
$tests['local-control error listener accepts the exception argument'] = static function (): void {
    callbackControl(static function (SocketServer $socket, bool &$stopped): void {
        $connection = new TestConnection();
        $socket->emit('connection', [$connection]);
        $connection->emit('error', [new RuntimeException('fixture socket error')]);
        expect(!$connection->closed, 'Error listener behavior changed.');
    });
};
$tests['close notifications continue to use zero arguments'] = static function (): void {
    callbackControl(static function (SocketServer $socket, bool &$stopped): void {
        $connection = new TestConnection();
        $socket->emit('connection', [$connection]);
        $connection->close();
        expect(Loop::$timers[0]->cancelled, 'Close did not cancel the deadline.');
    });
};
$tests['status and stop keep their zero-argument callback contract'] = static function (): void {
    callbackControl(static function (SocketServer $socket, bool &$stopped): void {
        foreach (['status', 'stop'] as $command) {
            $connection = new TestConnection();
            $socket->emit('connection', [$connection]);
            $connection->emit('data', [json_encode(['command' => $command, 'token' => 'test-token'])."\n"]);
            $reply = json_decode($connection->reply, true, 32, JSON_THROW_ON_ERROR);
            expect($reply['ok'] === true, 'Control command failed.');
            if ($command === 'status') { expect($reply['status']['fixture'], 'Status callback not invoked.'); }
        }
        expect(!$stopped, 'Stop was not deferred.');
        foreach (Loop::$ticks as $tick) { invoke($tick, [], 'control future tick'); }
        expect($stopped, 'Stop callback not invoked.');
    });
};

$failures = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo 'PASS '.$name.PHP_EOL; }
    catch (Throwable $error) { $failures++; fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL); }
}
echo count($tests).' callback-arity tests, '.$failures.' failures.'.PHP_EOL;
exit($failures === 0 ? 0 : 1);
