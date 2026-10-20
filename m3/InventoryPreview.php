<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Throwable;
use Trademinator\Client\M1\Database;

/** Disposable ledger on disk; never a checkpoint or a source for live recovery. */
final class InventoryPreview
{
    public readonly string $path;
    private string $directory;
    private mixed $lock = null;
    private ?Database $copy = null;
    private bool $ephemeral;

    public function __construct(Database $live, string $account)
    {
        $this->ephemeral = $live->path() === ':memory:';
        $this->directory = $this->ephemeral
            ? sys_get_temp_dir().'/trademinator-inventory-'.bin2hex(random_bytes(12))
            : (realpath(dirname($live->path())) ?: dirname($live->path())).'/reconciliation';
        $name = preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', $account)
            ? $account : hash('sha256', $account);
        $this->path = $this->directory.'/inventory-'.$name.'.sqlite';
        try {
            if (is_link($this->directory) || (!is_dir($this->directory)
                && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory))) {
                throw new RuntimeException('Cannot create inventory validation directory.');
            }
            self::secure($this->directory, 0700);
            $lockPath = $this->path.'.lock';
            if (is_link($lockPath) || ($this->lock = @fopen($lockPath, 'c+b')) === false) {
                throw new RuntimeException('Cannot lock inventory validation database.');
            }
            self::secure($lockPath, 0600);
            if (!flock($this->lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Inventory validation is already running for this account.');
            }
            // Only this account's preview is replaced. Never unlink a live SQLite file.
            foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
                $file = $this->path.$suffix;
                if (is_link($file) || (file_exists($file) && (!is_file($file) || !@unlink($file)))) {
                    throw new RuntimeException('Cannot reset inventory validation database.');
                }
            }
            $file = @fopen($this->path, 'x+b');
            if ($file === false) { throw new RuntimeException('Cannot create inventory validation database.'); }
            fclose($file);
            self::secure($this->path, 0600);
            $this->copy = new Database($this->path);
            // A single inspectable file, bounded page cache and disk-backed SQLite spills.
            $this->copy->pdo()->exec('PRAGMA journal_mode=DELETE');
            $this->copy->pdo()->exec('PRAGMA cache_size=-8192');
            $this->copy->pdo()->exec('PRAGMA temp_store=FILE');
            $this->copy->pdo()->exec('PRAGMA mmap_size=0');
            // This disposable copy is rebuilt each attempt; per-row fsync is unnecessary.
            $this->copy->pdo()->exec('PRAGMA synchronous=OFF');
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function database(): Database
    {
        return $this->copy ?? throw new RuntimeException('Inventory validation database is closed.');
    }

    public function close(): void
    {
        $this->copy = null;
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
        $this->lock = null;
        // In-memory live databases are fixtures/embedded use: no durable data directory.
        if ($this->ephemeral) {
            foreach (['', '-wal', '-shm', '-journal', '.lock'] as $suffix) { @unlink($this->path.$suffix); }
            @rmdir($this->directory);
        }
    }

    public function __destruct() { $this->close(); }

    private static function secure(string $path, int $mode): void
    {
        if (PHP_OS_FAMILY !== 'Windows' && !@chmod($path, $mode)) {
            throw new RuntimeException('Cannot secure inventory validation database.');
        }
    }
}
