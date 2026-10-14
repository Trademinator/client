<?php

declare(strict_types=1);

// Standalone callback-contract test doubles. Never load these with Composer or
// in the application: only Daemon.php and LocalControl.php are real here.
// No sockets, exchange requests, credentials, SQLite, or native code are used.
namespace M0CallbackTest {
    function expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }

    // Standard PHP accepts surplus arguments; explicitly check the signature so
    // the same zero-argument regression is detectable without a native compiler.
    function invoke(\Closure $callback, array $arguments, string $context): mixed
    {
        $signature = new \ReflectionFunction($callback);
        $count = count($arguments);
        expect($count >= $signature->getNumberOfRequiredParameters()
            && ($signature->isVariadic() || $count <= $signature->getNumberOfParameters()),
            $context.': callback signature does not accept '.$count.' supplied argument(s).');
        return $callback(...$arguments);
    }
}

namespace React\EventLoop {
    interface TimerInterface
    {
        public function getInterval();
        public function getCallback();
        public function isPeriodic();
    }

    final class TestTimer implements TimerInterface
    {
        public bool $cancelled = false;
        public function __construct(private float $interval, private \Closure $callback, private bool $periodic) {}
        public function getInterval(): float { return $this->interval; }
        public function getCallback(): \Closure { return $this->callback; }
        public function isPeriodic(): bool { return $this->periodic; }
    }

    final class Loop
    {
        public static array $timers = [];
        public static array $signals = [];
        public static array $ticks = [];
        public static ?\Closure $duringRun = null;
        public static bool $stopped = false;
        public static function reset(): void
        {
            self::$timers = self::$signals = self::$ticks = [];
            self::$duringRun = null; self::$stopped = false;
        }
        public static function addTimer(float $interval, \Closure $callback): TestTimer
        {
            return self::$timers[] = new TestTimer($interval, $callback, false);
        }
        public static function addPeriodicTimer(float $interval, \Closure $callback): TestTimer
        {
            return self::$timers[] = new TestTimer($interval, $callback, true);
        }
        public static function cancelTimer(TestTimer $timer): void { $timer->cancelled = true; }
        public static function addSignal(int $signal, \Closure $callback): void { self::$signals[$signal] = $callback; }
        public static function removeSignal(int $signal, \Closure $callback): void { unset(self::$signals[$signal]); }
        public static function futureTick(\Closure $callback): void { self::$ticks[] = $callback; }
        public static function run(): void
        {
            if (self::$duringRun !== null) { (self::$duringRun)(); }
        }
        public static function stop(): void { self::$stopped = true; }
    }
}

namespace React\Promise {
    interface PromiseInterface { public function cancel(); }
    final class TestPromise implements PromiseInterface { public function cancel(): void {} }
}

namespace React\Async {
    function async(\Closure $work): \Closure
    {
        return static function () use ($work): \React\Promise\PromiseInterface {
            \M0CallbackTest\invoke($work, [], 'async body');
            return new \React\Promise\TestPromise();
        };
    }
}

namespace React\Socket {
    interface ConnectionInterface {}

    trait TestEmitter
    {
        private array $listeners = [];
        public function on(string $event, \Closure $callback): void { $this->listeners[$event][] = $callback; }
        public function emit(string $event, array $arguments = []): void
        {
            foreach ($this->listeners[$event] ?? [] as $callback) {
                \M0CallbackTest\invoke($callback, $arguments, 'socket '.$event);
            }
        }
    }

    final class SocketServer
    {
        use TestEmitter;
        public static ?self $last = null;
        public function __construct(string $address) { self::$last = $this; }
        public function getAddress(): string { return 'tcp://127.0.0.1:17840'; }
        public function close(): void {}
    }

    final class TestConnection implements ConnectionInterface
    {
        use TestEmitter;
        public bool $closed = false;
        public string $reply = '';
        public function close(): void
        {
            if ($this->closed) { return; }
            $this->closed = true;
            $this->emit('close');
        }
        public function end(string $data): void { $this->reply = $data; $this->close(); }
    }
}

namespace Trademinator\Client\M0 {
    final class Config
    {
        public int $retention = 1000, $requestTimeout = 1, $wsRetrySeconds = 1;
        public int $ipcPort = 0, $intervalMs = 20, $serverPollSeconds = 60;
        public string $exchange = 'fixture', $symbol = 'XRP/CAD';
        public function __construct(public string $dataDir, public string $mode = 'fixture', public string $serverUrl = '') {}
    }
    final class StateStore
    {
        public static int $writes = 0;
        public function __construct(string $path, int $retention) {}
        public function set(string $key, array $value): void { self::$writes++; }
        public function append(string $run, string $transport, array $quote): void {}
        public function checkpoint(): void {}
    }
    final class FixtureMarketData {}
    final class CcxtMarketData { public function __construct(string $exchange, int $timeout) {} }
    final class ResilientFeed
    {
        public string $transport = 'websocket';
        public int $webSocketFailures = 0, $webSocketSamples = 0, $restSamples = 0;
        public function __construct(object $source, string $symbol, int $timeout, int $retry) {}
        public function next(): array
        {
            $this->webSocketSamples++;
            return ['symbol' => 'XRP/CAD', 'bid' => '2.3', 'ask' => '2.4', 'timestamp' => 1700000000000];
        }
        public function close(): void {}
    }
    final class ServerApi
    {
        public static int $snapshots = 0;
        public function __construct(string $url, string $token, int $timeout) {}
        public function snapshot(): array
        {
            self::$snapshots++;
            return ['status' => 'available', 'risk_factor' => '0.25'];
        }
    }
}
