<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\Paths;

/** Supervises discovery and paper workers without waiting on network I/O. */
final class Runtime
{
    private mixed $process = null;
    private ?string $directory = null;
    private int $lastEvent = 0;
    private int $heartbeat = 0;
    private array $status;
    private ?array $sessions = null;
    private float $nextDiscovery = 0;

    public function __construct(private readonly Config $config, private readonly \Closure $log, private readonly ?array $workerCommand = null)
    {
        $this->status = ['enabled' => $config->paperTrading['enabled'], 'state' => 'disabled', 'live_order_execution' => false];
        if ($config->paperTrading['enabled']) {
            // Validate credentials before creating any run or starting a subprocess.
            new Server($config);
            $this->status['run_id'] = $config->paperTrading['run_id'] ?? 'automatic';
            $this->status['state'] = 'pending';
        }
    }

    public function tick(): void
    {
        if ($this->sessions !== null) {
            foreach ($this->sessions as $id => $session) { $session->tick(); $this->status['runs'][$id] = $session->status(); }
            if ($this->process === null && microtime(true) < $this->nextDiscovery) { return; }
        }
        if (!$this->status['enabled'] || ($this->process === null && in_array($this->status['state'], ['failed', 'stopped'], true))) { return; }
        if ($this->process === null) { $this->start(); }
        if (time() !== $this->heartbeat) { touch($this->directory.'/lease'); $this->heartbeat = time(); }
        $file = $this->directory.'/status.json';
        if (is_file($file)) {
            $snapshot = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $this->status = [...$this->status, ...array_diff_key($snapshot, ['events' => true, 'plans' => true])];
            foreach ($snapshot['events'] ?? [] as $event) {
                if ($event['sequence'] <= $this->lastEvent) { continue; }
                $this->lastEvent = $event['sequence']; $record = $event['record'];
                ($this->log)($record['level'], $record['event'], ['paper_run_id' => $this->status['run_id'], ...$record['context']], $record['message'] ?? '');
            }
        }
        $process = proc_get_status($this->process);
        if (!$process['running']) {
            // The worker can publish its final result between the read above and process exit.
            if (is_file($file)) {
                $snapshot = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                $this->status = [...$this->status, ...array_diff_key($snapshot, ['events' => true, 'plans' => true])];
            }
            if (($snapshot['state'] ?? null) === 'prepared' && $process['exitcode'] === 0) {
                $this->cleanup(); $this->sessions ??= []; $this->status['runs'] = [];
                $ids = array_column($snapshot['plans'], 'run_id');
                foreach ($this->sessions as $id => $session) { if (!in_array($id, $ids, true)) { $session->close(); unset($this->sessions[$id]); } }
                foreach ($snapshot['warnings'] ?? [] as $warning) { ($this->log)('warning', 'paper_setup_waiting', $warning, $warning['reason']); }
                foreach ($snapshot['plans'] as $section) {
                    if (isset($this->sessions[$section['run_id']])) {
                        if (!in_array($this->sessions[$section['run_id']]->status()['state'], ['failed', 'stopped'], true)) { continue; }
                        $this->sessions[$section['run_id']]->close();
                    }
                    $values = $this->config->toArray(); $values['paper_trading'] = $section;
                    $this->sessions[$section['run_id']] = new self(new Config($values), $this->log, $this->workerCommand);
                }
                foreach ($snapshot['created'] ?? [] as $id) { ($this->log)('info', 'paper_created', ['paper_run_id' => $id], 'New paper account created from Server subscriptions and exchange rules.'); }
                $this->status['state'] = $this->sessions === [] ? 'waiting_for_markets' : 'running';
                $this->nextDiscovery = microtime(true) + 60;
                $this->tick(); return;
            }
            $this->status['state'] = 'failed';
            $this->status['error'] ??= 'Paper worker exited (code '.$process['exitcode'].'). Restart the daemon after correcting its configuration.';
            ($this->log)('error', 'paper_worker_failed', $this->status, 'Paper trading stopped; local control remains available.');
            $this->cleanup();
            if (!isset($this->config->paperTrading['markets'])) {
                $this->sessions ??= []; $this->nextDiscovery = microtime(true) + 60;
                $this->status['state'] = $this->sessions === [] ? 'waiting_for_markets' : 'running';
            }
        }
    }

    private function start(): void
    {
        $outcome = 'discovery_started';
        if (isset($this->config->paperTrading['markets'])) {
            $store = new Store($this->config->dataDir.'/paper');
            $outcome = $store->ensure($this->status['run_id'], PaperConfig::settings($this->config->paperTrading));
        }
        $base = $this->config->dataDir.'/runtime';
        if (!is_dir($base) && !mkdir($base, 0700, true)) { throw new RuntimeException('Cannot create paper runtime directory.'); }
        $this->directory = $base.'/paper-'.bin2hex(random_bytes(12));
        if (!mkdir($this->directory, 0700)) { throw new RuntimeException('Cannot create private paper worker directory.'); }
        try {
            $snapshot = $this->directory.'/config.json';
            Paths::atomicJson($snapshot, $this->config->toArray());
            Paths::atomicWrite($this->directory.'/lease', '');
            $command = $this->workerCommand ?? [PHP_BINARY, '-d', 'memory_limit='.ini_get('memory_limit'), dirname(__DIR__).'/bin/paper-worker.php'];
            $command[] = $snapshot;
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $this->process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($this->process)) { $this->process = null; throw new RuntimeException('Cannot start paper worker.'); }
            $this->lastEvent = 0;
            unset($this->status['error']);
            $this->status['state'] = 'starting';
            ($this->log)('info', 'paper_'.$outcome, ['paper_run_id' => $this->status['run_id']], 'Paper run '.$outcome.'.');
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function status(): array { return $this->status; }

    public function close(): void
    {
        if ($this->sessions !== null) {
            foreach ($this->sessions as $id => $session) { $session->close(); $this->status['runs'][$id] = $session->status(); }
            $this->sessions = null;
        }
        if ($this->directory !== null) { @unlink($this->directory.'/lease'); }
        if (is_resource($this->process)) {
            $deadline = microtime(true) + 0.5;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(10000); }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process);
                $deadline = microtime(true) + 0.5;
                while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) { usleep(10000); }
                if (proc_get_status($this->process)['running']) { proc_terminate($this->process, 9); }
            }
        }
        $this->cleanup();
        if ($this->status['enabled']) { $this->status['state'] = 'stopped'; }
    }

    private function cleanup(): void
    {
        if (is_resource($this->process)) { proc_close($this->process); }
        $this->process = null;
        if ($this->directory !== null) {
            foreach (glob($this->directory.'/*') ?: [] as $file) { @unlink($file); }
            @rmdir($this->directory); $this->directory = null;
        }
    }
}
