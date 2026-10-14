<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use Composer\InstalledVersions;
use RuntimeException;
use Throwable;

final class Application
{
    public function run(array $argv): int
    {
        try {
            $command = $argv[1] ?? 'help';
            $options = [];
            foreach (array_slice($argv, 2) as $argument) {
                if (!preg_match('/^--(config|seconds|samples)=(.+)$/D', $argument, $match) || isset($options[$match[1]])) {
                    throw new RuntimeException('Use --config=PATH, --seconds=N, and --samples=N; no duplicate options.');
                }
                $options[$match[1]] = $match[2];
            }
            if ($command === 'help') { $this->help(); return 0; }
            if ($command === 'doctor') { return $this->doctor(); }
            if ($command === 'math') { self::print(MathProbe::run()); return 0; }
            $config = Config::load($options['config'] ?? null);
            if ($command === 'run') {
                $seconds = self::integer($options['seconds'] ?? '0', 0, 604800);
                $samples = self::integer($options['samples'] ?? '0', 0, 10000000);
                MathProbe::run();
                return (new Daemon($config))->run($seconds, $samples);
            }
            if (in_array($command, ['status', 'stop'], true)) {
                self::print($this->control($config, $command));
                return 0;
            }
            throw new RuntimeException('Unknown command. Run help.');
        } catch (Throwable $error) {
            // Debug-only diagnostics: disabled by default so dependency exceptions
            // cannot expose credentials in routine logs. Never print arguments.
            if (getenv('TRADEMINATOR_M0_DIAGNOSTIC') === '1') {
                fwrite(STDERR, 'M0 diagnostic: '.get_class($error).' at '.$error->getFile().':'.$error->getLine().PHP_EOL);
                if ($error instanceof \ArgumentCountError) {
                    fwrite(STDERR, 'M0 diagnostic: '.$error->getMessage().PHP_EOL);
                }
                fwrite(STDERR, $error->getTraceAsString().PHP_EOL);
            }
            // Dependency exceptions can contain credentials; keep their output generic.
            $safe = str_starts_with($error->getFile(), __DIR__.DIRECTORY_SEPARATOR);
            fwrite(STDERR, ($safe ? $error->getMessage() : 'M0 failed: '.get_class($error).'. Check configuration and installed dependencies.').PHP_EOL);
            return 1;
        }
    }

    private static function integer(string $value, int $minimum, int $maximum): int
    {
        if (!ctype_digit($value) || strlen($value) > 9 || (int) $value < $minimum || (int) $value > $maximum) {
            throw new RuntimeException('Invalid nonnegative duration or sample count.');
        }
        return (int) $value;
    }

    private function doctor(): int
    {
        $extensions = [];
        foreach (['bcmath', 'curl', 'gmp', 'json', 'mbstring', 'openssl', 'pdo_sqlite'] as $name) {
            $extensions[$name] = extension_loaded($name);
        }
        $packages = [];
        foreach (['ccxt/ccxt', 'trademinator/bcmath', 'trademinator/indicators', 'react/event-loop'] as $package) {
            $packages[$package] = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
        }
        $ok = PHP_INT_SIZE === 8 && !in_array(false, $extensions, true) && !in_array(null, $packages, true);
        self::print(['status' => $ok ? 'ready' : 'missing_requirements', 'php' => PHP_VERSION,
            'platform' => PHP_OS_FAMILY, 'architecture_bits' => PHP_INT_SIZE * 8, 'extensions' => $extensions,
            'packages' => $packages, 'typephp_minimum_php_met' => PHP_VERSION_ID >= 80400,
            'native_build' => 'not_proven_by_doctor', 'math' => $ok ? MathProbe::run() : null]);
        return $ok ? 0 : 1;
    }

    private function control(Config $config, string $command): array
    {
        $file = $config->dataDir.'/control.json';
        if (!is_file($file)) { throw new RuntimeException('No running daemon found for this data directory.'); }
        $control = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($control) || !is_int($control['port'] ?? null) || $control['port'] < 1 || $control['port'] > 65535
            || !is_string($control['token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $control['token'])) {
            throw new RuntimeException('Invalid local control file.');
        }
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$control['port'], $errno, $message, 3);
        if ($socket === false) { throw new RuntimeException('Local daemon is unavailable.'); }
        try {
            stream_set_timeout($socket, 3);
            $request = json_encode(['command' => $command, 'token' => $control['token']], JSON_THROW_ON_ERROR)."\n";
            $offset = 0;
            while ($offset < strlen($request)) {
                $sent = fwrite($socket, substr($request, $offset));
                if ($sent === false || $sent === 0) { throw new RuntimeException('Local control write failed.'); }
                $offset += $sent;
            }
            $line = fgets($socket, 65536);
            if ($line === false || !str_ends_with($line, "\n")) { throw new RuntimeException('Local control response timed out or exceeded its limit.'); }
            $response = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($response) || ($response['ok'] ?? null) !== true) { throw new RuntimeException('Local control request rejected.'); }
            return $response;
        } finally { fclose($socket); }
    }

    private static function print(array $value): void { echo json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL; }

    private function help(): void
    {
        echo <<<'HELP'
Trademinator Client M0 (read-only technology probe)

  php bin/client.php doctor
  php bin/client.php math
  php bin/client.php run [--config=config.local.json] [--seconds=60] [--samples=20]
  php bin/client.php status [--config=config.local.json]
  php bin/client.php stop [--config=config.local.json]

Default: offline fixtures. In live mode, Server credentials come only from
TRADEMINATOR_API_KEY. Exchange probes use public ticker data. No orders are sent.
Duration/sample limits of 0 mean unlimited. Either finite limit stops the probe.
HELP;
        echo PHP_EOL;
    }
}
