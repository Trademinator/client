<?php

declare(strict_types=1);

namespace Trademinator\Client\M1;

use RuntimeException;

/** Private, per-user state. The lock file is never removed or replaced. */
final class Paths
{
    public readonly string $dataDir;
    public readonly string $runtimeDir;
    public readonly string $logDir;
    public readonly string $backupsDir;
    public readonly string $lockFile;
    public readonly string $statusFile;
    public readonly string $controlFile;

    public function __construct(Config|string $config)
    {
        $this->dataDir = self::absolute($config instanceof Config ? $config->dataDir : $config);
        $this->runtimeDir = $this->dataDir.'/runtime';
        $this->logDir = $this->dataDir.'/logs';
        $this->backupsDir = $this->dataDir.'/backups';
        $this->lockFile = $this->runtimeDir.'/daemon.lock';
        $this->statusFile = $this->runtimeDir.'/status.json';
        $this->controlFile = $this->runtimeDir.'/control.json';
    }

    public static function defaultDataDir(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $base = getenv('LOCALAPPDATA');
            if (!$base) { throw new RuntimeException('LOCALAPPDATA is required; pass --data-dir for a service account.'); }
            return self::absolute($base.'/Trademinator/Client');
        }
        $home = getenv('HOME');
        if (!$home) { throw new RuntimeException('HOME is required; pass --data-dir explicitly.'); }
        if (PHP_OS_FAMILY === 'Darwin') { return self::absolute($home.'/Library/Application Support/Trademinator'); }
        $base = getenv('XDG_DATA_HOME');
        return self::absolute(($base ?: $home.'/.local/share').'/trademinator/client');
    }

    public static function absolute(string $path, ?string $base = null): string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/[\r\n]/', $path)) {
            throw new RuntimeException('A local, nonempty data path is required.');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $path = str_replace('\\', '/', $path);
            if (str_starts_with($path, '//')) { throw new RuntimeException('Network data directories are unsupported.'); }
            if (!preg_match('/^[A-Za-z]:\//', $path)) { $path = str_replace('\\', '/', $base ?? (string) getcwd()).'/'.$path; }
            if (!preg_match('/^[A-Za-z]:\//', $path)) { throw new RuntimeException('An absolute local drive path is required.'); }
            $prefix = strtoupper(substr($path, 0, 2)).'/';
            $path = substr($path, 3);
        } else {
            if (!str_starts_with($path, '/')) { $path = ($base ?? (string) getcwd()).'/'.$path; }
            $prefix = '/';
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { array_pop($parts); } else { $parts[] = $part; }
        }
        if ($parts === []) { throw new RuntimeException('A filesystem root cannot be used as the client data directory.'); }
        return $prefix.implode('/', $parts);
    }

    public function ensure(): void
    {
        umask(0077);
        foreach ([$this->dataDir, $this->runtimeDir, $this->logDir, $this->backupsDir, $this->dataDir.'/archives'] as $dir) {
            if (is_link($dir)) { throw new RuntimeException('State directories must not be symbolic links: '.$dir); }
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('Cannot create state directory: '.$dir); }
            if (PHP_OS_FAMILY !== 'Windows' && !chmod($dir, 0700)) { throw new RuntimeException('Cannot restrict state directory permissions.'); }
        }
        if (PHP_OS_FAMILY === 'Windows') { Control::hardenWindowsDirectory($this->dataDir); }
    }

    public function socketPath(): string
    {
        $path = $this->runtimeDir.'/control.sock';
        if (strlen($path) > 100) { throw new RuntimeException('Data directory is too long for a native Unix socket; choose a shorter --data-dir.'); }
        return $path;
    }

    public function pipeName(): string
    {
        return 'trademinator-client-'.substr(hash('sha256', strtolower($this->dataDir)), 0, 32);
    }

    /** @return resource */
    public function acquireLock()
    {
        $this->ensure();
        if (is_link($this->lockFile)) { throw new RuntimeException('Unsafe lock file.'); }
        $lock = fopen($this->lockFile, 'c+b');
        if ($lock === false) { throw new RuntimeException('Cannot open daemon lock.'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException('Another M1 process is using this data directory. Stop it before offline changes.'); }
        @chmod($this->lockFile, 0600);
        return $lock;
    }

    public static function atomicJson(string $path, array $value): void
    {
        self::atomicWrite($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public static function atomicWrite(string $path, string $contents): void
    {
        if (is_link($path)) { throw new RuntimeException('Refusing to replace a symbolic link.'); }
        $temp = $path.'.tmp-'.bin2hex(random_bytes(8));
        $handle = fopen($temp, 'xb');
        if ($handle === false) { throw new RuntimeException('Cannot create atomic file: '.$path); }
        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) { throw new RuntimeException('Cannot write atomic file: '.$path); }
                $offset += $written;
            }
            if (!fflush($handle)) { throw new RuntimeException('Cannot flush file: '.$path); }
            if (function_exists('fsync') && !fsync($handle)) { throw new RuntimeException('Cannot sync file: '.$path); }
            @chmod($temp, 0600);
        } catch (\Throwable $error) { fclose($handle); @unlink($temp); throw $error; }
        fclose($handle);
        // PHP rename replaces an existing ordinary destination on supported local Windows filesystems too.
        if (!rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Cannot atomically replace file: '.$path); }
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('fsync')) {
            $directory = fopen(dirname($path), 'r');
            if ($directory === false) { throw new RuntimeException('Cannot open directory for durable publication.'); }
            try { if (!fsync($directory)) { throw new RuntimeException('Cannot sync directory after atomic publication.'); } }
            finally { fclose($directory); }
        }
    }
}
