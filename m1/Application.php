<?php
declare(strict_types=1);
namespace Trademinator\Client\M1;

use RuntimeException;
use Throwable;

final class Application
{
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $console = null;
        $daemon = null;
        try {
            $options = [];
            foreach (array_slice($argv, 2) as $arg) {
                if (in_array($arg, ['--verbose', '-v'], true)) {
                    if (isset($options['verbose'])) { throw new RuntimeException('Duplicate verbose option.'); }
                    $options['verbose'] = true;
                    continue;
                }
                if (!preg_match('/^--(config|data-dir|seconds|account|approve|point|exchange|label|subscription|market|checks|offset|limit|output|color)=(.+)$/D', $arg, $m) || isset($options[$m[1]])) {
                    throw new RuntimeException('Unknown or duplicate option. See help.');
                }
                $options[$m[1]] = $m[2];
            }
            if ($command === 'help') { $this->help(); return 0; }
            if ($command === 'version') { self::output(['milestone'=>'M2','version'=>'2.1.0-rc1']); return 0; }
            if ($command === 'doctor') { return $this->doctor(); }
            if ($command === 'run') {
                $autoload = dirname(__DIR__).'/vendor/autoload.php';
                if (!is_file($autoload)) { throw new RuntimeException('Console dependencies are missing. Run composer install.'); }
                require_once $autoload;
                if (!class_exists(\Symfony\Component\Console\Output\StreamOutput::class)) {
                    throw new RuntimeException('Symfony Console is missing. Run composer install.');
                }
                $console = new ConsoleEvents($options['output'] ?? 'human', $options['color'] ?? 'auto', isset($options['verbose']));
            } elseif (isset($options['output']) || isset($options['color']) || isset($options['verbose'])) {
                throw new RuntimeException('Output, color and verbose options apply to run only.');
            }
            self::requirements();
            $config = Config::load($options['config'] ?? null, $options['data-dir'] ?? null);
            if ($command === 'recover-backup') {
                self::output(Backup::restoreLatest($config->dataDir, ($options['approve'] ?? '') === 'yes')); return 0;
            }
            if (in_array($command, ['status','stop','reload'], true)) {
                self::output(Control::request($config, $command)); return 0;
            }
            if (in_array($command, ['subscriptions','markets','market-activate','market-stop','market-checks','server-refresh','accounts','account-add'], true)) {
                $arguments = array_intersect_key($options, array_flip(['account','subscription','market','exchange','label']));
                foreach (['offset'=>1000000, 'limit'=>100] as $field=>$max) {
                    if (!isset($options[$field])) { continue; }
                    if (!ctype_digit($options[$field]) || strlen($options[$field]) > 7 || (int) $options[$field] > $max
                        || ($field === 'limit' && (int) $options[$field] < 1)) { throw new RuntimeException('Invalid pagination option.'); }
                    $arguments[$field] = (int) $options[$field];
                }
                if (isset($options['checks'])) {
                    if ($options['checks'] === 'default') { $arguments['checks'] = null; }
                    elseif (ctype_digit($options['checks']) && (int) $options['checks'] >= 1 && (int) $options['checks'] <= 59) {
                        $arguments['checks'] = (int) $options['checks'];
                    } else { throw new RuntimeException('checks must be 1 through 59, or default.'); }
                }
                self::output(Control::request($config, $command, $arguments)); return 0;
            }
            if ($command === 'run') {
                $seconds = $options['seconds'] ?? '0';
                if (!ctype_digit($seconds) || strlen($seconds)>7 || (int)$seconds>604800) {
                    throw new RuntimeException('seconds must be an integer from 0 to 604800.');
                }
                $db = null; $backup = null; $hard = null; $runtime = null; $nextMaintenance = 0.0; $hardCandidates = [];
                $tick = function (Daemon $daemon, Config $config) use (&$db, &$backup, &$hard, &$runtime, &$nextMaintenance, &$hardCandidates): void {
                    if ($db === null) {
                        $db = new Database($config->dataDir.'/client.sqlite');
                        $backup = new Backup($db, $config->dataDir);
                        $hard = new HardPoints($db, $config->dataDir);
                        $hard->recover();
                        if (!$db->integrityCheck()['ok']) { throw new RuntimeException('Database integrity validation failed.'); }
                        $settings = $config->serverSettings();
                        EventRecord::hideSecret($settings['token']);
                        $runtime = new \Trademinator\Client\M2\Runtime($db, $settings, null, null,
                            fn(string $level, string $event, array $context, string $message) => $daemon->log($level, $event, $context, $message));
                        $daemon->log('info', 'storage_ready', ['schema_version'=>Database::SCHEMA_VERSION, 'live_order_execution'=>false], 'Local storage verified; live trading is disabled.');
                        if (!$settings['enabled']) { $daemon->log('info', 'server_disabled', [], 'Server integration is disabled; local core is running.'); }
                    }
                    $runtime->tick();
                    $now = hrtime(true) / 1e9;
                    if ($now < $nextMaintenance) { return; }
                    foreach ($backup->tick() as $reason => $snapshot) {
                        $daemon->log('info', 'backup_created', ['reason'=>$reason, 'backup_id'=>$snapshot['id'] ?? null], 'Local database backup created.');
                    }
                    foreach ($db->pdo()->query('SELECT id FROM exchange_accounts')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                        $candidate = $hard->proposeT1($id);
                        $candidateId = $candidate['token'] ?? null;
                        if ($candidateId !== null && ($hardCandidates[$id] ?? null) !== $candidateId) {
                            $daemon->log('info', 'hard_t1_available', ['account_id'=>$id, 'requires_approval'=>true], 'A new Hard T1 candidate is ready for local review.');
                        }
                        $hardCandidates[$id] = $candidateId;
                    }
                    $nextMaintenance = $now + 60;
                };
                $status = function () use (&$db, &$runtime): array {
                    return ['ready'=>$db!==null,'live_order_execution'=>false,'exchange_connected'=>false,
                        'accounts'=>$db ? (int)$db->pdo()->query('SELECT COUNT(*) FROM exchange_accounts')->fetchColumn() : 0,
                        'server'=>$runtime?->status()];
                };
                $reload = function (Config $candidate) use (&$runtime): void {
                    $settings = $candidate->serverSettings();
                    EventRecord::hideSecret($settings['token']);
                    $runtime?->reconfigure($settings);
                };
                $daemon = new Daemon($config, $tick, $status, $reload, [$console, 'write']);
                $daemon->setHandler(function (string $command, array $arguments) use (&$runtime, &$db, $daemon): array {
                    if ($db === null || $runtime === null) { throw new RuntimeException('Local storage is not ready.'); }
                    if ($command === 'accounts') {
                        return ['accounts'=>$db->pdo()->query('SELECT id,exchange,label FROM exchange_accounts ORDER BY created_at,id')->fetchAll()];
                    }
                    if ($command === 'account-add') {
                        $exchange = $arguments['exchange'] ?? null; $label = $arguments['label'] ?? null;
                        if (!is_string($exchange) || !is_string($label)) { throw new RuntimeException('Supply --exchange and --label for the local account.'); }
                        if (strlen($label) > 128) { throw new RuntimeException('Account label is too long.'); }
                        $account = (new Ledger($db))->createAccount($exchange, $label);
                        $result = ['account_id'=>$account, 'exchange_connected'=>false];
                        try { $daemon->log('info', 'account_created', ['account_id'=>$account, 'exchange'=>$exchange, 'exchange_connected'=>false], 'Local exchange account registered.'); }
                        catch (Throwable) { $result['warning'] = 'account_created_reporting_failed'; }
                        return $result;
                    }
                    return $runtime->command($command, $arguments);
                });
                return $daemon->run((int)$seconds);
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
                    'init' => ['status'=>'initialized','data_dir'=>$config->dataDir,'milestone'=>'M2','live_order_execution'=>false],
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
            if ($console !== null) {
                $console->write(EventRecord::create('error', 'startup_failed', ['error_type'=>get_class($error), 'message'=>$error->getMessage()],
                    $daemon?->status()['run_id'] ?? '', 'Client could not start.'));
            } else {
                fwrite(STDERR,json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES).PHP_EOL);
            }
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
        $asyncDns = extension_loaded('curl') && (curl_version()['features'] & CURL_VERSION_ASYNCHDNS) !== 0;
        $consoleAvailable = is_file(dirname(__DIR__).'/vendor/autoload.php')
            && is_file(dirname(__DIR__).'/vendor/symfony/console/Output/StreamOutput.php')
            && is_file(dirname(__DIR__).'/vendor/symfony/service-contracts/ResetInterface.php');
        $ready = PHP_VERSION_ID>=80300 && PHP_INT_SIZE===8 && extension_loaded('pdo_sqlite') && extension_loaded('bcmath') && extension_loaded('curl') && extension_loaded('openssl') && extension_loaded('gmp') && extension_loaded('mbstring') && $asyncDns && $consoleAvailable;
        self::output(['milestone'=>'M2','status'=>$ready?'ready':'missing_requirements','php'=>PHP_VERSION,'platform'=>PHP_OS_FAMILY,
            'architecture_bits'=>PHP_INT_SIZE*8,'extensions'=>['pdo_sqlite'=>extension_loaded('pdo_sqlite'),'bcmath'=>extension_loaded('bcmath'),'curl'=>extension_loaded('curl'),'openssl'=>extension_loaded('openssl'),'gmp'=>extension_loaded('gmp'),'mbstring'=>extension_loaded('mbstring')],
            'console_dependencies'=>$consoleAvailable, 'console_dependency_hint'=>$consoleAvailable?null:'Run composer install from the Client directory.',
            'curl_async_dns'=>$asyncDns,
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
Trademinator Client M2 — authenticated intelligence (no live order execution)
  php bin/client.php doctor
  php bin/client.php init [--config=PATH] [--data-dir=PATH]
  php bin/client.php run [--config=PATH] [--data-dir=PATH] [--seconds=60]
                         [--output=human|json] [--color=auto|always|never] [--verbose|-v]
  php bin/client.php status|stop|reload [--config=PATH] [--data-dir=PATH]
  php bin/client.php subscriptions|markets|accounts|server-refresh [--config=PATH]
  php bin/client.php subscriptions|markets --offset=0 --limit=50 [--config=PATH]
  php bin/client.php account-add --exchange=bitso --label=paper [--config=PATH]
  php bin/client.php market-activate --account=UUID --subscription=UUID [--checks=3] [--config=PATH]
  php bin/client.php market-stop --market=UUID [--config=PATH]
  php bin/client.php market-checks --market=UUID --checks=3|default [--config=PATH]
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
Market control commands use the running daemon; activation does not require restart.
Run tests: php tests/m2.php and php tests/m1.php. M0 is preserved at bin/m0.php.
HELP;
        echo PHP_EOL;
    }
}
