<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use Closure;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

final class LocalControl
{
    private SocketServer $socket;
    private array $connections = [];

    public function __construct(int $port, private string $token, Closure $status, Closure $stop)
    {
        // Intentionally not configurable to a public host, including in live mode.
        $this->socket = new SocketServer('127.0.0.1:'.$port);
        $this->socket->on('connection', function (ConnectionInterface $connection) use ($status, $stop): void {
            if (count($this->connections) >= 16) { $connection->close(); return; }
            $id = spl_object_id($connection);
            $this->connections[$id] = $connection;
            $buffer = '';
            $handled = false;
            $deadline = Loop::addTimer(2.0, static fn() => $connection->close());
            $connection->on('error', static function (): void {});
            $connection->on('close', function () use ($id, $deadline): void {
                Loop::cancelTimer($deadline);
                unset($this->connections[$id]);
            });
            $connection->on('data', function (string $chunk) use ($connection, &$buffer, &$handled, $status, $stop): void {
                if ($handled) { return; }
                $buffer .= $chunk;
                if (strlen($buffer) > 4096) { $connection->close(); return; }
                if (!str_contains($buffer, "\n")) { return; }
                $handled = true;
                try {
                    $request = json_decode(strtok($buffer, "\n"), true, 8, JSON_THROW_ON_ERROR);
                } catch (\JsonException) { $request = null; }
                if (!is_array($request) || !is_string($request['token'] ?? null) || !hash_equals($this->token, $request['token'])) {
                    $response = ['ok' => false, 'error' => 'unauthorized'];
                } elseif (($request['command'] ?? null) === 'status') {
                    $response = ['ok' => true, 'status' => $status()];
                } elseif (($request['command'] ?? null) === 'stop') {
                    $response = ['ok' => true, 'stopping' => true];
                    Loop::futureTick($stop);
                } else {
                    $response = ['ok' => false, 'error' => 'unknown_command'];
                }
                $connection->end(json_encode($response, JSON_THROW_ON_ERROR)."\n");
            });
        });
    }

    public function port(): int
    {
        return (int) parse_url((string) $this->socket->getAddress(), PHP_URL_PORT);
    }

    public function close(): void
    {
        $this->socket->close();
        foreach ($this->connections as $connection) { $connection->close(); }
        $this->connections = [];
    }
}
