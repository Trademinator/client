<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\EventRecord;
use Trademinator\Client\M1\Paths;

/** Public network calls run outside the daemon's local control loop. */
final class Worker
{
    public static function run(string $snapshot, ?callable $feedFactory = null, ?\Closure $transport = null): int
    {
        $directory = dirname($snapshot);
        $lease = $directory.'/lease';
        $statusFile = $directory.'/status.json';
        $keepRunning = static function () use ($lease): bool {
            clearstatcache(true, $lease);
            return is_file($lease) && time() - (int) filemtime($lease) < 15;
        };
        $status = ['state' => 'starting', 'updated_at_ms' => (int) floor(microtime(true) * 1000), 'events' => []];
        $publish = static function () use (&$status, $statusFile): void {
            $status['updated_at_ms'] = (int) floor(microtime(true) * 1000);
            Paths::atomicJson($statusFile, $status);
        };
        try {
            $config = Config::load($snapshot);
            $status['run_id'] = $config->paperTrading['run_id'] ?? 'automatic';
            $publish();
            if (!$keepRunning()) { $status['state'] = 'stopped'; $publish(); return 0; }
            $server = new Server($config, $transport);
            if (!isset($config->paperTrading['markets'])) {
                $status['state'] = 'discovering'; $publish();
                $result = AutomaticSetup::discover($config, $server, $feedFactory, $keepRunning);
                $status = [...$status, ...$result, 'state' => 'prepared']; $publish(); return 0;
            }
            $store = new Store($config->dataDir.'/paper');
            $sequence = 0;
            $emit = static function (array $record) use (&$status, &$sequence, $publish): void {
                $status['state'] = 'running';
                $symbol = $record['context']['symbol'] ?? null;
                if (is_string($symbol)) {
                    $status['markets'][$symbol] = ['state' => $record['event'] === 'paper_paused' ? 'paused' : 'running',
                        'event' => $record['event'], 'at' => $record['at'], ...$record['context']];
                }
                $status['events'][] = ['sequence' => ++$sequence, 'record' => $record];
                $status['events'] = array_slice($status['events'], -64);
                $publish();
            };
            Live::run($store, $status['run_id'], $server, 0, $emit, $feedFactory, $keepRunning);
            $status['state'] = 'stopped'; $publish(); return 0;
        } catch (\Throwable $error) {
            $status['state'] = 'failed'; $status['error'] = EventRecord::safeText($error->getMessage());
            $publish(); return 1;
        }
    }
}
