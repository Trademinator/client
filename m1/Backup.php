<?php
declare(strict_types=1);

namespace Trademinator\Client\M1;

use PDO;
use RuntimeException;
use Throwable;

/** Consistent SQLite snapshots. Daily and trade-count retention are independent. */
final class Backup
{
    private string $root;
    private $fault;

    public function __construct(private Database $db, string $dataDir, ?callable $fault = null)
    {
        $this->root = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backups';
        $this->fault = $fault;
        self::ensureDirectory($this->root);
    }

    public function create(string $reason = 'manual', ?int $nowMs = null, ?int $completedTrades = null): array
    {
        if (!in_array($reason, ['manual', 'daily', 'trades', 'pre-migration'], true)) {
            throw new RuntimeException('Unknown backup reason.');
        }
        $nowMs ??= (int) floor(microtime(true) * 1000);
        return $this->locked(function () use ($reason, $nowMs, $completedTrades): array {
            return $this->createLocked($reason, $nowMs, $completedTrades);
        });
    }

    /** A caller may supply its monotonic completed-trade count and a virtual clock. */
    public function tick(?int $nowMs = null, ?int $completedTrades = null): array
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        $completedTrades ??= $this->completedTrades();
        if ($completedTrades < 0) {
            throw new RuntimeException('Completed-trade count cannot be negative.');
        }
        return $this->locked(function () use ($nowMs, $completedTrades): array {
            $result = [];
            $day = gmdate('Y-m-d', intdiv($nowMs, 1000));
            $lastDaily = $this->latest('daily');
            if ($lastDaily === null || ($lastDaily['utc_day'] ?? '') < $day) {
                $result['daily'] = $this->createLocked('daily', $nowMs, $completedTrades);
            }
            $lastTrade = $this->latest('trades');
            $previous = (int) ($lastTrade['completed_trades'] ?? 0);
            // A restore can reduce a count. A new verified snapshot resets this schedule.
            if ($completedTrades < $previous || intdiv($completedTrades, 1000) > intdiv($previous, 1000)) {
                $result['trades'] = $this->createLocked('trades', $nowMs, $completedTrades);
            }
            return $result;
        });
    }

    public function dailyIfDue(?int $nowMs = null): ?array
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        return $this->locked(function () use ($nowMs): ?array {
            $previous = $this->latest('daily');
            if ($previous !== null && ($previous['utc_day'] ?? '') >= gmdate('Y-m-d', intdiv($nowMs, 1000))) {
                return null;
            }
            return $this->createLocked('daily', $nowMs, $this->completedTrades());
        });
    }

    public function completedTradesIfDue(int $completedTrades, ?int $nowMs = null): ?array
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        if ($completedTrades < 0) {
            throw new RuntimeException('Completed-trade count cannot be negative.');
        }
        return $this->locked(function () use ($completedTrades, $nowMs): ?array {
            $previous = (int) (($this->latest('trades')['completed_trades'] ?? 0));
            if ($completedTrades >= $previous && intdiv($completedTrades, 1000) <= intdiv($previous, 1000)) {
                return null;
            }
            return $this->createLocked('trades', $nowMs, $completedTrades);
        });
    }

    public function preMigration(?int $nowMs = null): array
    {
        return $this->create('pre-migration', $nowMs);
    }

    public function list(string $reason): array
    {
        if (!in_array($reason, ['manual', 'daily', 'trades', 'pre-migration'], true)) {
            throw new RuntimeException('Unknown backup reason.');
        }
        $rows = [];
        foreach (glob($this->root . '/' . $reason . '/*/manifest.json') ?: [] as $path) {
            try {
                $manifest = self::readJson($path);
                $manifest['path'] = dirname($path) . '/database.sqlite';
                if (!isset($manifest['database']['sha256'], $manifest['id'])) {
                    continue;
                }
                self::verifySnapshot($manifest['path'], $manifest['database']['sha256']);
                $rows[] = $manifest;
            } catch (Throwable) {
                // A corrupt manifest must never count as a usable backup.
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $rows;
    }

    /** Offline fallback, deliberately usable when the primary database cannot be opened. */
    public static function restoreLatest(string $dataDir, bool $approved = false): array
    {
        if (!$approved) {
            throw new RuntimeException('Backup recovery replaces current local state and requires --approve=yes.');
        }
        $paths = new Paths($dataDir);
        $lock = $paths->acquireLock();
        $root = $paths->dataDir . '/backups';
        self::ensureDirectory($root);
        try {
            $candidates = [];
            foreach (glob($root . '/*/*/manifest.json') ?: [] as $manifestPath) {
                try {
                    $manifest = self::readJson($manifestPath);
                    if (($manifest['format'] ?? '') !== 'trademinator-m1-backup-v1') { continue; }
                    $candidates[] = $manifest + ['path' => dirname($manifestPath) . '/database.sqlite'];
                } catch (Throwable) { /* Invalid metadata cannot authorize a restore. */ }
            }
            usort($candidates, static fn(array $a, array $b): int =>
                ((int) $b['created_at_ms'] <=> (int) $a['created_at_ms']) ?: strcmp($b['id'], $a['id']));
            $chosen = null;
            $skipped = [];
            foreach ($candidates as $candidate) {
                try {
                    self::verifySnapshot($candidate['path'], $candidate['database']['sha256']);
                    $chosen = $candidate;
                    break;
                } catch (Throwable $e) {
                    $skipped[] = ['path' => $candidate['path'], 'reason' => $e->getMessage()];
                }
            }
            if ($chosen === null) {
                throw new RuntimeException('No verified backup is available; preserve the database and restore an approved hard point into a fresh data directory.');
            }
            $stamp = (string) (int) floor(microtime(true) * 1000) . '-' . bin2hex(random_bytes(4));
            $quarantine = $paths->dataDir . '/quarantine/' . $stamp;
            self::ensureDirectory($quarantine);
            $stage = $quarantine . '/restored.sqlite';
            if (!copy($chosen['path'], $stage)) {
                throw new RuntimeException('Cannot stage the selected backup.');
            }
            @chmod($stage, 0600);
            self::verifySnapshot($stage, $chosen['database']['sha256']);
            $pdo = new PDO('sqlite:' . $stage, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('UPDATE exchange_accounts SET reconciled_revision=NULL,reconciled_at=NULL,revision=revision+1');
            if (Database::schemaVersion($pdo) >= 2) {
                // An old subscription choice is not current authorization to resume a market.
                // Open orders still need monitoring until an authoritative reconciliation closes them.
                $pdo->exec("UPDATE markets SET subscription_id=NULL,checks_per_candle=NULL,state=CASE WHEN EXISTS (SELECT 1 FROM orders WHERE orders.market_id=markets.id AND orders.status IN ('open','partially_filled')) THEN 'STOPPING' ELSE 'STOPPED' END");
            }
            $accounts = $pdo->query('SELECT id,revision,hard_t0_json FROM exchange_accounts')->fetchAll(PDO::FETCH_ASSOC);
            $pdo = null;
            self::syncFile($stage);
            self::verifySnapshot($stage);
            $intent = ['source' => $chosen['path'], 'quarantine' => $quarantine, 'created_at_ms' => (int) floor(microtime(true) * 1000)];
            self::atomicJson($root . '/restore-pending.json', $intent);
            $primary = $paths->dataDir . '/client.sqlite';
            // No replacement is exposed until every stale WAL/SHM file is moved away.
            foreach (['', '-wal', '-shm'] as $suffix) {
                if (is_file($primary . $suffix) && !rename($primary . $suffix, $quarantine . '/client.sqlite' . $suffix)) {
                    throw new RuntimeException('Cannot quarantine the previous database. Rerun recover-backup before startup.');
                }
            }
            self::syncDirectory($paths->dataDir);
            self::syncDirectory($quarantine);
            if (!rename($stage, $primary)) {
                throw new RuntimeException('Cannot install recovered database. Rerun recover-backup before startup.');
            }
            self::syncDirectory($quarantine);
            self::syncDirectory($paths->dataDir);
            // Historical hard points are permanent, but an active pointer must match the restored database.
            foreach (glob($paths->dataDir . '/hard-points/*/active.json') ?: [] as $pointer) {
                if (!unlink($pointer)) { throw new RuntimeException('Cannot reset stale hard-point pointer.'); }
                self::syncDirectory(dirname($pointer));
            }
            foreach ($accounts as $account) {
                $baseline = $account['hard_t0_json'] === null ? [] : json_decode($account['hard_t0_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!isset($baseline['hardpoint_id'])) { continue; }
                self::atomicJson($paths->dataDir . '/hard-points/' . $account['id'] . '/active.json', [
                    'account_id' => $account['id'], 'id' => $baseline['hardpoint_id'],
                    'kind' => $baseline['kind'] ?? 'HardT0', 'created_at_ms' => $baseline['created_at_ms'] ?? 0,
                    'revision' => (int) $account['revision'], 'activation_nonce' => $baseline['activation_nonce'] ?? null,
                ]);
            }
            // Old uncommitted intents and approvals refer to the pre-restore database.
            foreach (['pending.json', 'candidate.json'] as $name) {
                foreach (glob($paths->dataDir . '/hard-points/*/' . $name) ?: [] as $metadata) {
                    if (!unlink($metadata)) { throw new RuntimeException('Cannot clear stale recovery metadata.'); }
                    self::syncDirectory(dirname($metadata));
                }
            }
            self::atomicJson($paths->dataDir . '/recovery-required.json', [
                'reason' => 'backup_restored', 'source' => $chosen['path'],
                'exchange_replay_and_reconciliation_required' => true,
                'created_at_ms' => (int) floor(microtime(true) * 1000),
            ]);
            if (!unlink($root . '/restore-pending.json')) { throw new RuntimeException('Cannot finalize backup recovery.'); }
            self::syncDirectory($root);
            return ['status' => 'restored', 'source' => $chosen['path'], 'quarantine' => $quarantine,
                'reconciliation_required' => true, 'skipped' => $skipped];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function verifySnapshot(string $path, ?string $expectedHash = null): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('SQLite snapshot is missing or empty: ' . $path);
        }
        if ($expectedHash !== null && !hash_equals($expectedHash, hash_file('sha256', $path))) {
            throw new RuntimeException('SQLite snapshot SHA-256 mismatch.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schemaVersion = Database::schemaVersion($pdo);
        if ($schemaVersion < 1) { throw new RuntimeException('SQLite snapshot has no completed client schema.'); }
        $results = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        if ($results !== ['ok']) {
            throw new RuntimeException('SQLite snapshot failed integrity_check: ' . implode('; ', $results));
        }
        $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        if ($violations !== []) {
            throw new RuntimeException('SQLite snapshot failed foreign_key_check.');
        }
        $pdo = null;
        return ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path), 'integrity_check' => 'ok', 'schema_version' => $schemaVersion];
    }

    /** VACUUM INTO includes committed WAL content and never copies a live main file alone. */
    public static function snapshot(Database $db, string $destination): array
    {
        if ($db->inTransaction()) {
            throw new RuntimeException('Cannot make a SQLite snapshot inside a transaction.');
        }
        if (file_exists($destination)) {
            throw new RuntimeException('Refusing to overwrite an existing SQLite snapshot.');
        }
        self::ensureDirectory(dirname($destination));
        $db->pdo()->exec('VACUUM INTO ' . $db->pdo()->quote($destination));
        @chmod($destination, 0600);
        self::syncFile($destination);
        self::syncDirectory(dirname($destination));
        return self::verifySnapshot($destination);
    }

    public static function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            $parent = dirname($path);
            if (!is_dir($parent)) { self::ensureDirectory($parent); }
            if (!mkdir($path, 0700) && !is_dir($path)) {
                throw new RuntimeException('Cannot create directory: ' . $path);
            }
            self::syncDirectory($parent);
        }
        @chmod($path, 0700);
    }

    public static function atomicJson(string $path, array $data): void
    {
        self::ensureDirectory(dirname($path));
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        try {
            $fh = fopen($tmp, 'xb');
            if ($fh === false) {
                throw new RuntimeException('Cannot create temporary metadata file.');
            }
            @chmod($tmp, 0600);
            try {
                $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($fh, substr($json, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Incomplete metadata write.');
                    }
                    $offset += $written;
                }
                if (!fflush($fh) || (function_exists('fsync') && !fsync($fh))) {
                    throw new RuntimeException('Cannot flush metadata file.');
                }
            } finally {
                fclose($fh);
            }
            if (!rename($tmp, $path)) {
                throw new RuntimeException('Cannot atomically publish metadata file.');
            }
            self::syncDirectory(dirname($path));
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public static function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read metadata: ' . $path);
        }
        $result = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            throw new RuntimeException('Invalid metadata object.');
        }
        return $result;
    }

    private function createLocked(string $reason, int $nowMs, ?int $completedTrades): array
    {
        $id = sprintf('%013d', $nowMs) . '-' . bin2hex(random_bytes(4));
        $parent = $this->root . '/' . $reason;
        self::ensureDirectory($parent);
        $stage = $parent . '/.stage-' . $id;
        $target = $parent . '/' . $id;
        self::ensureDirectory($stage);
        try {
            $verification = self::snapshot($this->db, $stage . '/database.sqlite');
            $this->inject('backup.after_snapshot');
            $manifest = [
                'format' => 'trademinator-m1-backup-v1', 'id' => $id, 'reason' => $reason,
                'created_at_ms' => $nowMs, 'utc_day' => gmdate('Y-m-d', intdiv($nowMs, 1000)),
                'completed_trades' => $completedTrades,
                'trade_threshold' => $completedTrades === null ? null : intdiv($completedTrades, 1000) * 1000,
                'database' => $verification,
            ];
            self::atomicJson($stage . '/manifest.json', $manifest);
            self::verifySnapshot($stage . '/database.sqlite', $verification['sha256']);
            $this->inject('backup.before_publish');
            if (!rename($stage, $target)) {
                throw new RuntimeException('Cannot publish verified backup.');
            }
            self::syncDirectory($parent);
            // Retention only runs after the new verified snapshot is safely published.
            if (in_array($reason, ['daily', 'trades'], true)) {
                $rows = $this->list($reason);
                foreach (array_slice($rows, 0, max(0, count($rows) - 7)) as $row) {
                    self::removeTree(dirname($row['path']));
                }
            }
            return $manifest + ['path' => $target . '/database.sqlite'];
        } catch (Throwable $e) {
            self::removeTree($stage);
            throw $e;
        }
    }

    private function latest(string $reason): ?array
    {
        $rows = $this->list($reason);
        return $rows === [] ? null : $rows[count($rows) - 1];
    }

    private function completedTrades(): int
    {
        $ledger = new Ledger($this->db);
        $total = 0;
        foreach ($this->db->pdo()->query('SELECT id FROM exchange_accounts')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $total += $ledger->completedTrades((string) $id);
        }
        return $total;
    }

    private function locked(callable $callback): mixed
    {
        $fh = fopen($this->root . '/.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new RuntimeException('Cannot acquire backup lock.');
        }
        try {
            return $callback();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public static function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Cannot remove expired backup directory.');
        }
    }

    public static function syncFile(string $path): void
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot reopen snapshot for synchronization.');
        }
        try {
            if (function_exists('fsync') && !fsync($fh)) {
                throw new RuntimeException('Cannot synchronize snapshot.');
            }
        } finally {
            fclose($fh);
        }
    }

    public static function syncDirectory(string $path): void
    {
        // PHP cannot open Windows directories as fsync-able handles. Native OS validation remains required.
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('fsync')) { return; }
        $fh = fopen($path, 'r');
        if ($fh === false) { throw new RuntimeException('Cannot open directory for durable publication.'); }
        try {
            if (!fsync($fh)) { throw new RuntimeException('Cannot synchronize directory metadata.'); }
        } finally { fclose($fh); }
    }

    private function inject(string $stage): void
    {
        if ($this->fault !== null) {
            ($this->fault)($stage);
        }
    }
}
