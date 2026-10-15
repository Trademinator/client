<?php
declare(strict_types=1);
namespace Trademinator\Client\M1;

use RuntimeException;
use Throwable;

final class Application
{
    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? 'help';
            $options = [];
            foreach (array_slice($argv, 2) as $arg) {
                if (!preg_match('/^--(config|data-dir|seconds|account|approve|point)=(.+)$/D', $arg, $m) || isset($options[$m[1]])) {
                    throw new RuntimeException('Unknown or duplicate option. See help.');
                }
                $options[$m[1]] = $m[2];
            }
            if ($command === 'help') { $this->help(); return 0; }
            if ($command === 'version') { self::output(['milestone'=>'M1','version'=>'1.0.0-rc1']); return 0; }
            if ($command === 'doctor') { return $this->doctor(); }
            self::requirements();
            $config = Config::load($options['config'] ?? null, $options['data-dir'] ?? null);
            if ($command === 'recover-backup') {
                self::output(Backup::restoreLatest($config->dataDir, ($options['approve'] ?? '') === 'yes')); return 0;
            }
            if (in_array($command, ['status','stop','reload'], true)) {
                self::output(Control::request($config, $command)); return 0;
            }
            if ($command === 'run') {
                $seconds = $options['seconds'] ?? '0';
                if (!ctype_digit($seconds) || strlen($seconds)>7 || (int)$seconds>604800) {
                    throw new RuntimeException('seconds must be an integer from 0 to 604800.');
                }
                $db = null; $backup = null; $hard = null; $nextMaintenance = 0.0;
                $tick = function (Daemon $daemon, Config $config) use (&$db, &$backup, &$hard, &$nextMaintenance): void {
                    if ($db === null) {
                        $db = new Database($config->dataDir.'/client.sqlite');
                        $backup = new Backup($db, $config->dataDir);
                        $hard = new HardPoints($db, $config->dataDir);
                        $hard->recover();
                        if (!$db->integrityCheck()['ok']) { throw new RuntimeException('Database integrity validation failed.'); }
                    }
                    $now = hrtime(true) / 1e9;
                    if ($now < $nextMaintenance) { return; }
                    $backup->tick();
                    foreach ($db->pdo()->query('SELECT id FROM exchange_accounts')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                        $hard->proposeT1($id);
                    }
                    $nextMaintenance = $now + 60;
                };
                $status = function () use (&$db): array {
                    return ['ready'=>$db!==null,'live_order_execution'=>false,'exchange_connected'=>false,
                        'accounts'=>$db ? (int)$db->pdo()->query('SELECT COUNT(*) FROM exchange_accounts')->fetchColumn() : 0];
                };
                return (new Daemon($config, $tick, $status))->run((int)$seconds);
            }
            if (!in_array($command,['init','check','backup','hard-t0','hard-t1-propose','hard-t1-promote','hard-restore','recover'],true)) {
                throw new RuntimeException('Unknown command. See help.');
            }
            $paths = new Paths($config);
            $lock = $paths->acquireLock();
            try {
                $db = new Database($config->dataDir.'/client.sqlite');
                $hard = new HardPoints($db, $config->dataDir);
                $hard->recover();
                $account = $options['account'] ?? '';
                if (str_starts_with($command, 'hard-') && $account === '') { throw new RuntimeException('Supply --account=UUID.'); }
                $result = match($command) {
                    'init' => ['status'=>'initialized','data_dir'=>$config->dataDir,'milestone'=>'M1','live_order_execution'=>false],
                    'check' => ['status'=>'checked','integrity'=>$db->integrityCheck()],
                    'backup' => (new Backup($db,$config->dataDir))->create('manual'),
                    'hard-t0' => $hard->createT0($account, ($options['approve'] ?? '') === 'yes'),
                    'hard-t1-propose' => $hard->proposeT1($account),
                    'hard-t1-promote' => $hard->promoteT1($account, $options['approve'] ?? ''),
                    'hard-restore' => $hard->restore($account, $options['point'] ?? '', ($options['approve'] ?? '') === 'yes'),
                    'recover' => ['status'=>'recovered'],
                };
                self::output(is_array($result) ? $result : ['result'=>$result]);
                if ($command === 'check' && !($result['integrity']['ok'] ?? false)) { return 1; }
            } finally { flock($lock,LOCK_UN); fclose($lock); }
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES).PHP_EOL);
            return 1;
        }
    }

    private static function requirements(): void
    {
        if (PHP_VERSION_ID<80300 || PHP_INT_SIZE!==8 || !extension_loaded('pdo_sqlite') || !extension_loaded('bcmath')) {
            throw new RuntimeException('M1 needs 64-bit PHP 8.3+ with PDO SQLite and BCMath.');
        }
    }

    private function doctor(): int
    {
        $ready = PHP_VERSION_ID>=80300 && PHP_INT_SIZE===8 && extension_loaded('pdo_sqlite') && extension_loaded('bcmath');
        self::output(['milestone'=>'M1','status'=>$ready?'ready':'missing_requirements','php'=>PHP_VERSION,'platform'=>PHP_OS_FAMILY,
            'architecture_bits'=>PHP_INT_SIZE*8,'extensions'=>['pdo_sqlite'=>extension_loaded('pdo_sqlite'),'bcmath'=>extension_loaded('bcmath')],
            'ipc'=>PHP_OS_FAMILY==='Windows'?'Windows named pipe (PowerShell bridge)':'Unix domain socket',
            'live_order_execution'=>false,'native_build'=>'separate validation required']);
        return $ready?0:1;
    }

    private static function output(array $value): void
    {
        echo json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    }

    private function help(): void
    {
        echo <<<'HELP'
Trademinator Client M1 — local core (no live order execution)
  php bin/client.php doctor
  php bin/client.php init [--config=PATH] [--data-dir=PATH]
  php bin/client.php run [--config=PATH] [--data-dir=PATH] [--seconds=60]
  php bin/client.php status|stop|reload [--config=PATH] [--data-dir=PATH]
  php bin/client.php check|backup|recover [--config=PATH] [--data-dir=PATH]
  php bin/client.php recover-backup --approve=yes [--config=PATH] [--data-dir=PATH]
  php bin/client.php hard-t0 --account=UUID --approve=yes [--data-dir=PATH]
  php bin/client.php hard-t1-propose --account=UUID [--data-dir=PATH]
  php bin/client.php hard-t1-promote --account=UUID --approve=TOKEN [--data-dir=PATH]
  php bin/client.php hard-restore --account=UUID --point=UNIX_MS --approve=yes [--data-dir=PATH]

Offline database/hard-point commands require the daemon stopped.
Hard T1 promotion uses the exact reviewed candidate token. A stale token is rejected.
Hard restore is an explicit replacement of account state; inspect the archive first.
Use a NEW isolated directory for simulation: php tools/m1-simulate.php --data-dir=PATH --orders=10000
Run tests: php tests/m1.php. M0 is preserved at bin/m0.php (locked PHP libraries included).
HELP;
        echo PHP_EOL;
    }
}
