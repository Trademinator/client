<?php
declare(strict_types=1);

namespace Trademinator\Client\M1;

use PDO;
use RuntimeException;
use Throwable;

/** Permanent account baselines, with an explicit approval token for every Hard T1. */
final class HardPoints
{
    private string $root;
    private Ledger $ledger;
    private $fault;

    public function __construct(private Database $db, string $dataDir, ?callable $fault = null)
    {
        $this->root = rtrim($dataDir, DIRECTORY_SEPARATOR) . '/hard-points';
        $this->ledger = new Ledger($db);
        $this->fault = $fault;
        Backup::ensureDirectory($this->root);
    }

    /** The only financial eligibility restriction for a user-selected T0 is no open orders. */
    public function createT0(string $account, bool $approved = false): array
    {
        if (!$approved) {
            throw new RuntimeException('Hard T0 requires explicit user approval.');
        }
        return $this->locked($account, function () use ($account): array {
            $this->recoverAccount($account);
            $state = $this->ledger->accountState($account);
            if ($this->openOrders($state) !== 0) {
                throw new RuntimeException('Hard T0 requires no open orders in this account.');
            }
            return $this->activate($account, 'HardT0', $state, null);
        });
    }

    /** Reuses the same token while its account revision and checkpoints remain current. */
    public function proposeT1(string $account): array
    {
        return $this->locked($account, function () use ($account): array {
            $this->recoverAccount($account);
            $state = $this->ledger->accountState($account);
            $reasons = $this->ineligibleReasons($account, $state);
            $candidatePath = $this->accountPath($account) . '/candidate.json';
            if ($reasons !== []) {
                if (is_file($candidatePath)) {
                    unlink($candidatePath);
                }
                return ['eligible' => false, 'account_id' => $account, 'reasons' => $reasons];
            }
            $fingerprint = $this->fingerprint($state);
            if (is_file($candidatePath)) {
                $previous = Backup::readJson($candidatePath);
                if (($previous['fingerprint'] ?? '') === $fingerprint) {
                    return $previous;
                }
            }
            $candidate = [
                'eligible' => true, 'kind' => 'HardT1', 'account_id' => $account,
                'token' => bin2hex(random_bytes(24)), 'revision' => (int) $state['revision'],
                'created_at_ms' => self::now(), 'fingerprint' => $fingerprint,
                'parent_hardpoint' => $this->active($account)['id'] ?? null,
                'checkpoints' => array_map(static fn(array $market): array => [
                    'market_id' => $market['id'], 'checkpoint_id' => $market['checkpoint_id'],
                    'activity_revision' => $market['activity_revision'],
                ], $state['markets']),
                'wallet_balances' => $state['wallet_balances'],
                'requires_approval' => true,
            ];
            Backup::atomicJson($candidatePath, $candidate);
            return $candidate;
        });
    }

    /** Passing this exact candidate token is the approval; no automatic caller does so. */
    public function promoteT1(string $account, string $token): array
    {
        return $this->locked($account, function () use ($account, $token): array {
            $this->recoverAccount($account);
            $path = $this->accountPath($account) . '/candidate.json';
            if (!is_file($path)) {
                throw new RuntimeException('No Hard T1 candidate exists for this account.');
            }
            $candidate = Backup::readJson($path);
            if ($token === '' || !hash_equals((string) $candidate['token'], $token)) {
                throw new RuntimeException('Hard T1 approval must match this specific candidate token.');
            }
            $state = $this->ledger->accountState($account);
            if ((int) $candidate['revision'] !== (int) $state['revision'] ||
                !hash_equals((string) $candidate['fingerprint'], $this->fingerprint($state)) ||
                $this->ineligibleReasons($account, $state) !== []) {
                unlink($path);
                throw new RuntimeException('Hard T1 candidate became stale; review a new candidate.');
            }
            return $this->activate($account, 'HardT1', $state, $candidate);
        });
    }

    /** Restore one account, leaving every other exchange account untouched. */
    public function restore(string $account, string $id, bool $approved = false): array
    {
        if (!$approved) {
            throw new RuntimeException('Restoring a hard point replaces current account state and requires explicit user approval.');
        }
        $this->assertId($id);
        return $this->locked($account, function () use ($account, $id): array {
            $this->recoverAccount($account);
            $hard = $this->verify($account, $id);
            $before = null;
            try {
                $before = $this->ledger->accountState($account);
                if ($this->openOrders($before) !== 0) {
                    throw new RuntimeException('Cannot restore while this account has open orders.');
                }
            } catch (RuntimeException $e) {
                // An empty database is the supported from-scratch recovery case.
                $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM exchange_accounts WHERE id=?');
                $q->execute([$account]);
                if ((int) $q->fetchColumn() !== 0) {
                    throw $e;
                }
            }
            if ($before !== null) {
                (new Backup($this->db, dirname($this->root)))->create('manual');
            }
            $pending = [
                'operation' => 'restore', 'account_id' => $account, 'target_id' => $id,
                'before_active' => $this->active($account),
                'activation_nonce' => bin2hex(random_bytes(16)),
            ];
            Backup::atomicJson($this->accountPath($account) . '/pending.json', $pending);
            try {
                $this->db->transaction(function () use ($account, $hard, $before, $pending): void {
                    if ($before !== null && $this->db->revision($account) !== (int) $before['revision']) {
                        throw new RuntimeException('Account changed while preparing restore.');
                    }
                    $this->ledger->restoreAccountSnapshot($hard['snapshot']);
                    // Restore the financial history, never historical authorization to trade.
                    // M1 snapshots omit these columns and are accepted using the M2 defaults.
                    $q = $this->db->pdo()->prepare("UPDATE markets SET subscription_id=NULL,checks_per_candle=NULL,state=CASE WHEN EXISTS (SELECT 1 FROM orders WHERE orders.market_id=markets.id AND orders.status IN ('open','partially_filled')) THEN 'STOPPING' ELSE 'STOPPED' END WHERE account_id=?");
                    $q->execute([$account]);
                    $baseline = $hard;
                    $baseline['activation_nonce'] = $pending['activation_nonce'];
                    $baseline['hardpoint_id'] = $hard['id'];
                    $this->ledger->installHardT0($account, $baseline, $hard['kind'] === 'HardT1');
                    // A historical reconstruction is not a current exchange reconciliation.
                    $q = $this->db->pdo()->prepare('UPDATE exchange_accounts SET reconciled_revision=NULL,reconciled_at=NULL WHERE id=?');
                    $q->execute([$account]);
                    $this->inject('hardpoint.before_commit');
                });
                $this->inject('hardpoint.after_commit');
                $this->inject('hardpoint.before_pointer');
                $this->publishActive($account, $hard, $pending['activation_nonce']);
                $this->clearPending($account);
                return $this->result($account, $hard) + ['restored' => true, 'reconciliation_required' => true];
            } catch (Throwable $e) {
                // A committed transaction is completed on recovery, never undone by guessing.
                throw $e;
            }
        });
    }

    /** Resolve intents after a crash: SQLite decides whether rollback or completion won. */
    public function recover(?string $account = null): array
    {
        $accounts = $account === null ? array_map('basename', glob($this->root . '/*', GLOB_ONLYDIR) ?: []) : [$account];
        $results = [];
        foreach ($accounts as $id) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
                continue;
            }
            $result = $this->locked($id, fn(): ?array => $this->recoverAccount($id));
            if ($result !== null) {
                $results[] = $result;
            }
        }
        return $results;
    }

    public function active(string $account): ?array
    {
        $path = $this->accountPath($account) . '/active.json';
        return is_file($path) ? Backup::readJson($path) : null;
    }

    public function verify(string $account, string $id): array
    {
        $this->assertId($id);
        $path = $this->accountPath($account) . '/' . $id;
        $manifest = Backup::readJson($path . '/manifest.json');
        if (($manifest['account_id'] ?? null) !== $account || ($manifest['id'] ?? null) !== $id) {
            throw new RuntimeException('Hard-point identity does not match its directory.');
        }
        if (!hash_equals((string) $manifest['hard_json_sha256'], hash_file('sha256', $path . '/hard.json'))) {
            throw new RuntimeException('Hard-point status SHA-256 mismatch.');
        }
        Backup::verifySnapshot($path . '/archive.sqlite', $manifest['archive']['sha256']);
        $hard = Backup::readJson($path . '/hard.json');
        if (($hard['account_id'] ?? null) !== $account || ($hard['id'] ?? null) !== $id) {
            throw new RuntimeException('Hard-point status identity mismatch.');
        }
        return $hard;
    }

    private function activate(string $account, string $kind, array $state, ?array $candidate): array
    {
        $parent = $this->accountPath($account);
        $id = (string) self::now();
        while (file_exists($parent . '/' . $id)) {
            $id = (string) ((int) $id + 1);
        }
        $stage = $parent . '/.stage-' . $id . '-' . bin2hex(random_bytes(4));
        $target = $parent . '/' . $id;
        Backup::ensureDirectory($stage);
        $published = false;
        try {
            Backup::snapshot($this->db, $stage . '/archive.sqlite');
            $snapshot = $this->isolateArchive($stage . '/archive.sqlite', $account);
            if ((int) $snapshot['account']['revision'] !== (int) $state['revision']) {
                throw new RuntimeException('Account changed while capturing the hard point; try again.');
            }
            $hard = [
                'format' => 'trademinator-m1-hardpoint-v1', 'id' => $id, 'hardpoint_id' => $id,
                'kind' => $kind, 'account_id' => $account, 'created_at_ms' => (int) $id,
                'revision' => (int) $state['revision'], 'parent_hardpoint' => $this->active($account)['id'] ?? null,
                'state' => $state, 'snapshot' => $snapshot,
                'approval' => $candidate === null ? ['explicit' => true] : ['explicit' => true, 'candidate_token' => $candidate['token']],
            ];
            Backup::atomicJson($stage . '/hard.json', $hard);
            $manifest = [
                'format' => 'trademinator-m1-hardpoint-manifest-v1', 'id' => $id, 'account_id' => $account,
                'kind' => $kind, 'created_at_ms' => (int) $id,
                'archive' => Backup::verifySnapshot($stage . '/archive.sqlite'),
                'hard_json_sha256' => hash_file('sha256', $stage . '/hard.json'),
            ];
            Backup::atomicJson($stage . '/manifest.json', $manifest);
            $this->inject('hardpoint.after_archive');
            if (!rename($stage, $target)) {
                throw new RuntimeException('Cannot publish permanent hard-point archive.');
            }
            Backup::syncDirectory($parent);
            $published = true;
            $pending = [
                'operation' => 'activate', 'account_id' => $account, 'target_id' => $id,
                'before_active' => $this->active($account), 'activation_nonce' => bin2hex(random_bytes(16)),
            ];
            Backup::atomicJson($parent . '/pending.json', $pending);
            $this->db->transaction(function () use ($account, $kind, $state, $candidate, $hard, $pending): void {
                $current = $this->ledger->accountState($account);
                if ((int) $current['revision'] !== (int) $state['revision'] ||
                    !hash_equals($this->fingerprint($state), $this->fingerprint($current))) {
                    throw new RuntimeException('Account changed before hard-point activation.');
                }
                // This also covers informational rows whose writer does not advance financial revision.
                if (!hash_equals($this->snapshotDigest($hard['snapshot']), $this->snapshotDigest($this->ledger->accountSnapshot($account)))) {
                    throw new RuntimeException('Account history changed after its archive was captured.');
                }
                if ($kind === 'HardT1' && $this->ineligibleReasons($account, $current) !== []) {
                    throw new RuntimeException('Hard T1 is no longer eligible.');
                }
                if ($this->openOrders($current) !== 0) {
                    throw new RuntimeException('Account has open orders.');
                }
                $baseline = $hard;
                $baseline['activation_nonce'] = $pending['activation_nonce'];
                $this->ledger->installHardT0($account, $baseline, $kind === 'HardT1');
                $this->inject('hardpoint.before_commit');
            });
            $this->inject('hardpoint.after_commit');
            $this->inject('hardpoint.before_pointer');
            $this->publishActive($account, $hard, $pending['activation_nonce']);
            $this->clearPending($account);
            if (is_file($parent . '/candidate.json')) {
                unlink($parent . '/candidate.json');
            }
            return $this->result($account, $hard);
        } catch (Throwable $e) {
            if (!$published) {
                Backup::removeTree($stage);
            }
            throw $e;
        }
    }

    private function isolateArchive(string $path, string $account): array
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('DELETE FROM buys_vs_sells WHERE account_id<>?');
            $q->execute([$account]);
            $q = $pdo->prepare('DELETE FROM exchange_accounts WHERE id<>?');
            $q->execute([$account]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $q = $pdo->prepare('SELECT * FROM exchange_accounts WHERE id=?');
        $q->execute([$account]);
        $accountRow = $q->fetch(PDO::FETCH_ASSOC);
        if ($accountRow === false) {
            throw new RuntimeException('Account is absent from the captured SQLite archive.');
        }
        $snapshot = ['account' => $accountRow];
        foreach (['markets', 'orders', 'buys_vs_sells', 'operations', 'checkpoints', 'signal_decisions', 'transfer_labels'] as $table) {
            $q = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE account_id=? ORDER BY id');
            $q->execute([$account]);
            $snapshot[$table] = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $pdo->exec('VACUUM');
        $q = null;
        $pdo = null;
        Backup::syncFile($path);
        Backup::verifySnapshot($path);
        return $snapshot;
    }

    private function ineligibleReasons(string $account, array $state): array
    {
        $reasons = [];
        $m3 = $this->db->pdo()->prepare("SELECT COUNT(*) FROM orders WHERE account_id=? AND json_type(metadata_json,'$.m3') IS NOT NULL");
        $m3->execute([$account]);
        $operations = $this->db->pdo()->prepare("SELECT COUNT(*) FROM operations WHERE account_id=? AND json_type(metadata_json,'$.m3') IS NOT NULL");
        $operations->execute([$account]);
        if ((int)$m3->fetchColumn() > 0 || (int)$operations->fetchColumn() > 0 || ($this->active($account)['kind'] ?? '') === 'HardH0') { $reasons[] = 'm3_requires_candidate_h_and_full_h1_verification'; }
        if ($this->active($account) === null) {
            $reasons[] = 'hard_t0_missing';
        }
        if ($this->openOrders($state) !== 0) {
            $reasons[] = 'open_orders';
        }
        if (empty($state['reconciled'])) {
            $reasons[] = 'wallets_not_reconciled';
        }
        if (empty($state['markets'])) {
            $reasons[] = 'no_account_markets';
        }
        if (empty($state['all_markets_checkpointed'])) {
            $reasons[] = 'market_checkpoint_missing_or_stale';
        }
        foreach ($state['markets'] as $market) {
            if (!empty($market['dust'])) {
                $reasons[] = 'market_dust:' . $market['id'];
            }
            if (isset($market['unmatched_sell_quantity']) && bccomp((string) $market['unmatched_sell_quantity'], '0', 24) !== 0) {
                $reasons[] = 'unmatched_sell:' . $market['id'];
            }
        }
        // Repeated idle ticks must not create new Hard T1 candidates after promotion.
        $active = $this->active($account);
        if ($active !== null && ($active['kind'] ?? '') === 'HardT1' &&
            (int) ($active['revision'] ?? -1) === (int) $state['revision']) {
            $reasons[] = 'no_new_account_activity';
        }
        return array_values(array_unique($reasons));
    }

    private function recoverAccount(string $account): ?array
    {
        $parent = $this->accountPath($account);
        $path = $parent . '/pending.json';
        if (!is_file($path)) {
            return null;
        }
        $pending = Backup::readJson($path);
        if (($pending['account_id'] ?? null) !== $account) {
            throw new RuntimeException('Invalid hard-point recovery intent.');
        }
        $q = $this->db->pdo()->prepare('SELECT hard_t0_json FROM exchange_accounts WHERE id=?');
        $q->execute([$account]);
        $raw = $q->fetchColumn();
        $baseline = $raw === false || $raw === null ? [] : json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        $committed = ($baseline['hardpoint_id'] ?? null) === $pending['target_id'] &&
            ($baseline['activation_nonce'] ?? null) === $pending['activation_nonce'];
        if ($committed) {
            $hard = $this->verify($account, (string) $pending['target_id']);
            $this->publishActive($account, $hard, $pending['activation_nonce']);
            if (($hard['kind'] ?? '') === 'HardH0' && is_file($parent.'/h-candidate.json')) { unlink($parent.'/h-candidate.json'); }
            if (is_file($parent . '/candidate.json')) {
                unlink($parent . '/candidate.json');
            }
        } else {
            if ($pending['before_active'] === null) {
                if (is_file($parent . '/active.json')) {
                    unlink($parent . '/active.json');
                }
            } else {
                Backup::atomicJson($parent . '/active.json', $pending['before_active']);
            }
        }
        $this->clearPending($account);
        return ['account_id' => $account, 'action' => $committed ? 'completed' : 'rolled_back', 'id' => $pending['target_id']];
    }

    private function publishActive(string $account, array $hard, string $nonce): void
    {
        Backup::atomicJson($this->accountPath($account) . '/active.json', [
            'account_id' => $account, 'id' => $hard['id'], 'kind' => $hard['kind'],
            'created_at_ms' => $hard['created_at_ms'], 'revision' => $this->db->revision($account),
            'activation_nonce' => $nonce,
        ]);
    }

    private function result(string $account, array $hard): array
    {
        return [
            'account_id' => $account, 'id' => $hard['id'], 'kind' => $hard['kind'],
            'path' => $this->accountPath($account) . '/' . $hard['id'],
            'revision' => $this->db->revision($account), 'active' => true,
        ];
    }

    private function fingerprint(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function snapshotDigest(array $snapshot): string
    {
        // Mutable review annotations do not change the frozen financial prefix.
        unset($snapshot['transfer_labels']);
        foreach ($snapshot as $table => &$rows) {
            if ($table === 'account') {
                ksort($rows);
                continue;
            }
            foreach ($rows as &$row) { ksort($row); }
            unset($row);
            usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
        }
        unset($rows);
        ksort($snapshot);
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function openOrders(array $state): int
    {
        return is_array($state['open_orders']) ? count($state['open_orders']) : (int) $state['open_orders'];
    }

    private function accountPath(string $account): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $account)) {
            throw new RuntimeException('Invalid account identifier.');
        }
        $path = $this->root . '/' . $account;
        Backup::ensureDirectory($path);
        return $path;
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[0-9]{10,18}$/D', $id)) {
            throw new RuntimeException('Hard-point id must be its Unix-millisecond directory name.');
        }
    }

    private function locked(string $account, callable $callback): mixed
    {
        $fh = fopen($this->accountPath($account) . '/.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new RuntimeException('Cannot acquire hard-point account lock.');
        }
        try {
            return $callback();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function clearPending(string $account): void
    {
        $path = $this->accountPath($account) . '/pending.json';
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Cannot clear completed hard-point recovery intent.');
        }
        Backup::syncDirectory(dirname($path));
    }

    private static function now(): int { return (int) floor(microtime(true) * 1000); }
    private function inject(string $stage): void { if ($this->fault !== null) { ($this->fault)($stage); } }
}
