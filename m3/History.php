<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Trademinator\Client\M1\{Database,Ledger,Backup};

/** Reconstruct archived cost provenance without ever rewriting a published hard point. */
final class History
{
    public function __construct(private Database $db, private string $dataDir)
    {
    }
    public function hasArchives(): bool
    {
        foreach ($this->db->pdo()->query('SELECT hard_t0_json FROM exchange_accounts')->fetchAll() as $row) {
            if ((Numbers::decode($row['hard_t0_json'])['kind'] ?? '') === 'HardH0') {
                return true;
            }
        }return false;
    }
    public function expand(): Database
    {
        $out = new Database(':memory:');
        $target = new Ledger($out);
        $ledger = new Ledger($this->db);
        foreach ($this->db->pdo()->query('SELECT id FROM exchange_accounts')->fetchAll(\PDO::FETCH_COLUMN) as $account) {
            $current = $ledger->accountSnapshot($account);
            $chain = [$current];
            $baseline = Numbers::decode($current['account']['hard_t0_json']);
            $visited = [];
            while (($baseline['kind'] ?? '') === 'HardH0') {
                $id = $baseline['hardpoint_id'];
                if (isset($visited[$id])) {
                    throw new RuntimeException('Cyclic hard-point ancestry.');
                }$visited[$id] = true;
                $hard = (new \Trademinator\Client\M1\HardPoints($this->db, $this->dataDir))->verify($account, $id);
                $chain[] = $hard['snapshot'];
                $baseline = Numbers::decode($hard['snapshot']['account']['hard_t0_json']);
            }
            $chain = array_reverse($chain);
            $merged = $chain[0];
            $maps = [];
            foreach (['markets','orders','operations','signal_decisions'] as $table) {
                $maps[$table] = [];
            }
            foreach ($chain as $snapshot) {
                foreach (['markets','orders','operations','signal_decisions'] as $table) {
                    foreach ($snapshot[$table] as $row) {
                        if ($table === 'markets' && isset($maps[$table][$row['id']])) {
                            $row['baseline_lots_json'] = $maps[$table][$row['id']]['baseline_lots_json'];
                        }
                        $maps[$table][$row['id']] = $row;
                    }
                }
            }
            foreach ($maps as $table => $rows) {
                $merged[$table] = array_values($rows);
            }
            $merged['buys_vs_sells'] = [];
            $merged['checkpoints'] = [];
            $merged['account']['hard_t0_json'] = null;
            $merged['account']['reconciled_revision'] = null;
            foreach ($merged['operations'] as &$op) {
                $op['lot_consumptions_json'] = '{}';
            }unset($op);
            foreach (['orders','operations'] as $table) {
                foreach ($merged[$table] as &$row) {
                    $meta = Numbers::decode($row['metadata_json']);
                    unset($meta['m3']['inventory_debits']);
                    $row['metadata_json'] = Numbers::json($meta);
                }unset($row);
            }
            $corrections = [];
            foreach ($chain as $snapshot) {
                $point = Numbers::decode($snapshot['account']['hard_t0_json']);
                foreach ($point['cost_corrections'] ?? [] as $correction) {
                    $corrections[] = $correction;
                }
            }
            foreach ($corrections as $correction) {
                foreach ($merged['operations'] as &$op) {
                    if (isset($correction['operations'][$op['id']])) {
                        $op['metadata_json'] = $correction['operations'][$op['id']];
                    }
                }unset($op);
            }
            $target->restoreAccountSnapshot($merged);
            (new Accounting($out))->rebuild($account);
        }
        return $out;
    }
    /** Apply cost-only reconstruction. Wallet amounts and immutable archive hashes must remain identical. */
    public function apply(Database $expanded, string $reason): void
    {
        $source = new Ledger($expanded);
        $real = new Ledger($this->db);
        $math = new Accounting($this->db);
        $this->db->transaction(function () use ($source, $real, $math, $reason): void {
            foreach ($this->db->pdo()->query('SELECT id FROM exchange_accounts')->fetchAll(\PDO::FETCH_COLUMN) as $account) {
                $current = $real->accountSnapshot($account);
                $full = $source->accountSnapshot($account);
                $baseline = Numbers::decode($current['account']['hard_t0_json']);
                $wallet = $real->expectedBalances($account);
                $fullOps = array_column($full['operations'], null, 'id');
                $changes = [];
                if (($baseline['kind'] ?? '') === 'HardH0') {
                    $cutoff = $baseline['cutoff_ms'];
                    $prefix = $full;
                    foreach (['orders','operations','signal_decisions'] as $table) {
                        $prefix[$table] = array_values(array_filter($prefix[$table], static fn ($row) => Numbers::ms($row['occurred_at']) <= $cutoff));
                    }
                    $prefix['buys_vs_sells'] = [];
                    $prefix['checkpoints'] = [];
                    foreach ($prefix['operations'] as &$op) {
                        $op['lot_consumptions_json'] = '{}';
                    }unset($op);
                    foreach (['orders','operations'] as $table) {
                        foreach ($prefix[$table] as &$row) {
                            $meta = Numbers::decode($row['metadata_json']);
                            unset($meta['m3']['inventory_debits']);
                            $row['metadata_json'] = Numbers::json($meta);
                        }unset($row);
                    }
                    $temp = new Database(':memory:');
                    $prefixLedger = new Ledger($temp);
                    $prefixLedger->restoreAccountSnapshot($prefix);
                    (new Accounting($temp))->rebuild($account);
                    if ($prefixLedger->expectedBalances($account) !== Numbers::decode($current['account']['opening_balances_json'])) {
                        throw new RuntimeException('Cost correction changed archived wallet arithmetic.');
                    }
                    foreach ($prefix['markets'] as $market) {
                        $carry = [];
                        foreach ($prefixLedger->marketLots($market['id']) as $lot) {
                            $key = $lot['source_key'];
                            $lot['id'] = hash('sha256', $baseline['hardpoint_id'].'|'.$key);
                            $lot['origin'] = ['kind' => 'hard_point','source_key' => $key,'account_id' => $account,'hardpoint_id' => $baseline['hardpoint_id'],'acquisition' => $lot['origin']];
                            unset($lot['source_key'],$lot['source_type'],$lot['source_id']);
                            $carry[] = $lot;
                        }
                        $math->execute('UPDATE markets SET baseline_lots_json=? WHERE id=?', [Numbers::json($carry),$market['id']]);
                    }
                    foreach ($prefix['operations'] as $op) {
                        $changes[$op['id']] = $op['metadata_json'];
                    }
                    $baseline['cost_corrections'][] = ['at' => gmdate('c'),'reason' => $reason,'archive_unchanged' => true,'operations' => $changes];
                    $math->execute('UPDATE exchange_accounts SET hard_t0_json=? WHERE id=?', [Numbers::json($baseline),$account]);
                }
                foreach ($current['operations'] as $op) {
                    if (isset($fullOps[$op['id']])) {
                        $math->execute('UPDATE operations SET metadata_json=? WHERE id=?', [$fullOps[$op['id']]['metadata_json'],$op['id']]);
                    }
                }
                foreach ($current['markets'] as $market) {
                    $math->touch($account, $market['id']);
                }
                $math->rebuild($account);
                if ($real->expectedBalances($account) !== $wallet) {
                    throw new RuntimeException('Late transfer correction changed live wallet amounts.');
                }
            }
        });
        foreach ($this->db->pdo()->query('SELECT id FROM exchange_accounts')->fetchAll(\PDO::FETCH_COLUMN) as $account) {
            $candidate = $this->dataDir.'/hard-points/'.$account.'/h-candidate.json';
            if (is_file($candidate)) {
                unlink($candidate);
            } // Financial correction requires fresh H1 verification.
        }
    }
}
