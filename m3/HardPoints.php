<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Throwable;
use Trademinator\Client\M1\Backup;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;

/** Candidate H is frozen; H1 verifies fresh exchange evidence; approval rebases only its prefix. */
final class HardPoints
{
    private Accounting $accounting;
    private Ledger $ledger;
    public function __construct(private Database $db, private string $dataDir, private $fault = null)
    {
        $this->accounting = new Accounting($db);
        $this->ledger = new Ledger($db);
    }
    private function root(string $account): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $account)) {
            throw new RuntimeException('Invalid account ID.');
        }
        $path = $this->dataDir.'/hard-points/'.$account;
        Backup::ensureDirectory($path);
        return $path;
    }
    private function locked(string $account, callable $fn): mixed
    {
        $fh = fopen($this->root($account).'/.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new RuntimeException('Cannot lock hard point.');
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
    public function verificationPending(string $account): bool
    {
        $path = $this->root($account).'/h-candidate.json';
        return is_file($path) && (Backup::readJson($path)['verification'] ?? '') === 'pending';
    }
    public function capture(string $account, bool $retry = false): array
    {
        return $this->locked($account, function () use ($account, $retry): array {
            $root = $this->root($account);
            $pointer = $root.'/h-candidate.json';
            if (is_file($pointer)) {
                return Backup::readJson($pointer);
            }
            $state = $this->ledger->accountState($account);
            $reasons = [];
            if (!$retry && is_file($root.'/h-rejected.json')) {
                $rejected = Backup::readJson($root.'/h-rejected.json');
                if (($rejected['revision'] ?? null) === $state['revision']) {
                    $reasons[] = 'h1_failed_same_financial_revision';
                }
            }
            if (!$state['reconciled']) {
                $reasons[] = 'wallets_not_reconciled';
            }if ($state['open_orders'] !== 0) {
                $reasons[] = 'open_or_uncertain_orders';
            }if (!$state['all_markets_checkpointed']) {
                $reasons[] = 'checkpoints_incomplete';
            }
            foreach ($state['markets'] as $market) {
                if ($market['dust'] || !$market['acquisition_cost_known'] || bccomp($market['unmatched_sell_quantity'], '0', 24) !== 0) {
                    $reasons[] = 'unresolved_inventory';
                }
            }
            $snapshot = $this->ledger->accountSnapshot($account, true);
            $baseline = Numbers::decode($snapshot['account']['hard_t0_json']);
            if (($baseline['kind'] ?? '') === 'HardH0' && $snapshot['orders'] === [] && $snapshot['operations'] === []) {
                $reasons[] = 'no_new_financial_activity';
            }
            if ($reasons !== []) {
                return ['eligible' => false,'account_id' => $account,'reasons' => array_unique($reasons)];
            }
            $id = (string)(int)floor(microtime(true) * 1000);
            while (file_exists($root.'/'.$id)) {
                $id = (string)((int)$id + 1);
            }
            $cutoff = (int)$id;
            $stage = $root.'/.h-stage-'.$id;
            Backup::ensureDirectory($stage);
            try {
                $archive = new Database($stage.'/archive.sqlite');
                (new Ledger($archive))->restoreAccountSnapshot($snapshot);
                $archive->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                unset($archive);
                $hard = ['format' => 'trademinator-m3-hardpoint-v1','id' => $id,'hardpoint_id' => $id,'kind' => 'HardH0','account_id' => $account,
                    'created_at_ms' => $cutoff,'cutoff_ms' => $cutoff,'revision' => $state['revision'],'parent_hardpoint' => $baseline['hardpoint_id'] ?? null,'state' => $state,'snapshot' => $snapshot];
                Backup::atomicJson($stage.'/hard.json', $hard);
                Backup::atomicJson($stage.'/manifest.json', ['id' => $id,'account_id' => $account,'kind' => 'HardH0','created_at_ms' => $cutoff,
                    'archive' => Backup::verifySnapshot($stage.'/archive.sqlite'),'hard_json_sha256' => hash_file('sha256', $stage.'/hard.json')]);
                if (!rename($stage, $root.'/'.$id)) {
                    throw new RuntimeException('Cannot publish immutable candidate.');
                }Backup::syncDirectory($root);
                $candidate = ['eligible' => true,'account_id' => $account,'id' => $id,'token' => bin2hex(random_bytes(24)),'cutoff_ms' => $cutoff,'revision' => $state['revision'],'verification' => 'pending','requires_approval' => true];
                Backup::atomicJson($pointer, $candidate);
                return $candidate;
            } catch (Throwable $e) {
                if (is_dir($stage)) {
                    Backup::removeTree($stage);
                }throw $e;
            }
        });
    }
    public function verifyPending(string $account, array $batch): ?array
    {
        return $this->locked($account, function () use ($account, $batch): ?array {
            $root = $this->root($account);
            $pointer = $root.'/h-candidate.json';
            if (!is_file($pointer)) {
                return null;
            }$c = Backup::readJson($pointer);
            if ($c['verification'] !== 'pending') {
                return $c;
            }
            try {
                if (($batch['complete'] ?? false) !== true || $batch['at_ms'] < $c['cutoff_ms']) {
                    throw new RuntimeException('Fresh complete H1 evidence required.');
                }
                $hard = (new \Trademinator\Client\M1\HardPoints($this->db, $this->dataDir))->verify($account, $c['id']);
                $snapshot = $hard['snapshot'];
                $baseline = Numbers::decode($snapshot['account']['hard_t0_json']);
                $floor = (int)($baseline['cutoff_ms'] ?? 0);
                if ($batch['since_ms'] > $floor + 1 && $floor > 0) {
                    throw new RuntimeException('H1 history does not reach Hard H0.');
                }
                $expectedTrades = [];
                $expectedOps = [];
                foreach ($snapshot['orders'] as $row) {
                    $m = Numbers::decode($row['metadata_json'])['m3'] ?? null;
                    if ($m === null && bccomp($row['filled_quantity'], '0', 24) > 0) {
                        throw new RuntimeException('Legacy orders need exchange-backed reconstruction before H1.');
                    }foreach ($m['fills'] ?? [] as $fill) {
                        $expectedTrades[$fill['symbol'].'|'.$fill['id']] = $fill;
                    }
                }
                foreach ($snapshot['operations'] as $row) {
                    if ((Numbers::decode($row['metadata_json'])['m3']['origin'] ?? '') === 'user_adjustment') {
                        continue;
                    }$e = Numbers::decode($row['metadata_json'])['m3']['evidence'] ?? null;
                    if ($e === null) {
                        throw new RuntimeException('Legacy operations need exchange-backed reconstruction before H1.');
                    }$expectedOps[$e['id']] = $e;
                }
                $actualTrades = [];
                foreach ($batch['trades'] as $fill) {
                    if ($fill['timestamp'] <= $c['cutoff_ms'] && $fill['timestamp'] > $floor) {
                        $actualTrades[$fill['symbol'].'|'.$fill['id']] = $fill;
                    }
                }
                $actualOps = [];
                foreach ($batch['operations'] as $op) {
                    if ($op['timestamp'] <= $c['cutoff_ms'] && $op['timestamp'] > $floor) {
                        $actualOps[$op['id']] = $op;
                    }
                }
                ksort($expectedTrades);
                ksort($actualTrades);
                ksort($expectedOps);
                ksort($actualOps);
                if ($expectedTrades !== $actualTrades || $expectedOps !== $actualOps) {
                    throw new RuntimeException('Fresh H1 evidence differs from the frozen financial history.');
                }
                $memory = new Database(':memory:');
                $ledger = new Ledger($memory);
                $ledger->restoreAccountSnapshot($snapshot);
                (new Accounting($memory))->rebuild($account);
                $ledger->reconcileAccount($account, $hard['state']['wallet_balances']);
                $reconstructed = $ledger->accountState($account);
                foreach ($hard['state']['markets'] as $market) {
                    $now = $ledger->marketState($market['id']);
                    if ($now['lots'] !== $market['lots'] || $now['holdings'] !== $market['holdings']) {
                        throw new RuntimeException('H1 lot reconstruction differs from candidate.');
                    }
                }
                $c['verification'] = 'verified';
                $c['verified_at_ms'] = $batch['at_ms'];
                $c['evidence_sha256'] = hash('sha256', Numbers::json([$actualTrades,$actualOps]));
                Backup::atomicJson($root.'/'.$c['id'].'/verification.json', $c);
                Backup::atomicJson($pointer, $c);
                return $c;
            } catch (Throwable $error) {
                Backup::atomicJson($root.'/'.$c['id'].'/verification.json', ['verification' => 'failed','reason' => $error->getMessage()]);
                Backup::atomicJson($root.'/h-rejected.json', ['revision' => $c['revision'],'id' => $c['id'],'reason' => $error->getMessage()]);
                unlink($pointer);
                return ['eligible' => false,'verification' => 'failed','reason' => $error->getMessage()];
            }
        });
    }
    public function promote(string $account, string $token): array
    {
        return $this->locked($account, function () use ($account, $token): array {
            $root = $this->root($account);
            $path = $root.'/h-candidate.json';
            $c = Backup::readJson($path);
            if ($c['verification'] !== 'verified' || $token === '' || !hash_equals($c['token'], $token)) {
                throw new RuntimeException('Promotion requires this verified candidate approval token.');
            }
            $hard = (new \Trademinator\Client\M1\HardPoints($this->db, $this->dataDir))->verify($account, $c['id']);
            $nonce = bin2hex(random_bytes(16));
            $pending = ['operation' => 'activate','account_id' => $account,'target_id' => $c['id'],'before_active' => is_file($root.'/active.json') ? Backup::readJson($root.'/active.json') : null,'activation_nonce' => $nonce];
            Backup::atomicJson($root.'/pending.json', $pending);
            $this->db->transaction(function () use ($account, $hard, $c, $nonce): void {
                $current = $this->ledger->accountSnapshot($account);
                $original = $hard['snapshot'];
                // New rows are allowed. Corrections to the frozen prefix invalidate verification.
                foreach (['orders','operations'] as $table) {
                    $byId = array_column($current[$table], null, 'id');
                    foreach ($original[$table] as $row) {
                        if (($byId[$row['id']] ?? null) !== $row) {
                            throw new RuntimeException('Candidate prefix changed after capture; verify a new snapshot.');
                        }
                    }
                }
                if ($current['account']['opening_balances_json'] !== $original['account']['opening_balances_json'] || $current['account']['hard_t0_json'] !== $original['account']['hard_t0_json']) {
                    throw new RuntimeException('Candidate parent baseline changed.');
                }
                $wasReconciled = $this->ledger->accountState($account)['reconciled'];
                $this->accounting->execute('DELETE FROM buys_vs_sells WHERE account_id=?', [$account]);
                foreach ($hard['state']['markets'] as $market) {
                    $carry = [];
                    foreach ($market['lots'] as $lot) {
                        $key = $lot['source_key'];
                        $lot['id'] = hash('sha256', $c['id'].'|'.$key);
                        $lot['origin'] = ['kind' => 'hard_point','source_key' => $key,'account_id' => $account,'hardpoint_id' => $c['id'],'acquisition' => $lot['origin']];
                        unset($lot['source_key'],$lot['source_type'],$lot['source_id']);
                        $carry[] = $lot;
                    }
                    $this->accounting->execute('UPDATE markets SET baseline_lots_json=? WHERE id=?', [Numbers::json($carry),$market['id']]);
                }
                foreach (['signal_decisions','checkpoints','operations','orders'] as $table) {
                    foreach ($original[$table] as $row) {
                        $this->accounting->execute('DELETE FROM '.$table.' WHERE id=? AND account_id=?', [$row['id'],$account]);
                    }
                }
                $baseline = $hard;
                unset($baseline['snapshot']);
                $baseline['activation_nonce'] = $nonce;
                $baseline['approval'] = ['explicit' => true,'token' => $c['token'],'verified_at_ms' => $c['verified_at_ms']];
                $this->accounting->execute('UPDATE exchange_accounts SET opening_balances_json=?,baseline_completed_trades=?,hard_t0_json=? WHERE id=?', [
                    Numbers::json((object)$hard['state']['wallet_balances']),$hard['state']['completed_trades'],Numbers::json($baseline),$account]);
                $this->accounting->touch($account);
                (new Accounting($this->db))->rebuild($account);
                if ($wasReconciled) {
                    $this->ledger->reconcileAccount($account, Numbers::decode($current['account']['wallet_balances_json']));
                }
                if ($this->fault !== null) {
                    ($this->fault)('hardpoint.before_commit');
                }
            });
            if ($this->fault !== null) {
                ($this->fault)('hardpoint.after_commit');
            }
            // Existing M1 crash-recovery protocol uses the committed nonce as its authority.
            Backup::atomicJson($root.'/active.json', ['account_id' => $account,'id' => $c['id'],'kind' => 'HardH0','created_at_ms' => $c['cutoff_ms'],'revision' => $this->db->revision($account),'activation_nonce' => $nonce]);
            unlink($root.'/pending.json');
            unlink($path);
            Backup::syncDirectory($root);
            return ['active' => true,'kind' => 'HardH0','id' => $c['id'],'cutoff_ms' => $c['cutoff_ms'],'post_snapshot_history_preserved' => true];
        });
    }
}
