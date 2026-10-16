<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;

final class Transfers
{
    private Accounting $accounting;
    public function __construct(private Database $db, private array $settings, private ?string $dataDir = null)
    {
        $this->accounting = new Accounting($db);
    }
    private function operations(): array
    {
        if ($this->dataDir !== null) {
            $history = new History($this->db, $this->dataDir);
            if ($history->hasArchives()) {
                return (new Accounting($history->expand()))->rows('SELECT * FROM operations ORDER BY occurred_at,id');
            }
        }
        return $this->accounting->rows('SELECT * FROM operations ORDER BY occurred_at,id');
    }
    public function candidates(): array
    {
        $rows = $this->operations();
        $pairs = [];
        foreach ($rows as $w) {
            if ($w['kind'] !== 'withdrawal' || isset(Numbers::decode($w['metadata_json'])['m3']['transfer'])) {
                continue;
            }
            foreach ($rows as $d) {
                if ($d['kind'] !== 'deposit' || $d['account_id'] === $w['account_id'] || $d['currency'] !== $w['currency'] || isset(Numbers::decode($d['metadata_json'])['m3']['transfer'])) {
                    continue;
                }
                $score = $this->score($w, $d);
                if ($score >= $this->settings['transfer_medium_score']) {
                    $pairs[] = ['withdrawal' => $w['id'],'deposit' => $d['id'],'score' => $score];
                }
            }
        }
        foreach ($pairs as &$p) {
            $conflicts = array_filter($pairs, static fn ($q) => $q['withdrawal'] === $p['withdrawal'] || $q['deposit'] === $p['deposit']);
            $p['confidence'] = $p['score'] >= $this->settings['transfer_high_score'] && count($conflicts) === 1 ? 'high' : 'medium';
        }
        unset($p);
        return $pairs;
    }
    private function score(array $w, array $d): int
    {
        $a = Numbers::decode($w['metadata_json'])['m3']['evidence'] ?? [];
        $b = Numbers::decode($d['metadata_json'])['m3']['evidence'] ?? [];
        $score = 0;
        $strong = false;
        foreach (['txid' => 80,'address' => 60,'network' => 5,'tag' => 5] as $key => $weight) {
            if (empty($a[$key]) || empty($b[$key])) {
                continue;
            }
            if ($a[$key] !== $b[$key]) {
                return 0;
            }
            $score += $weight;
            if (in_array($key, ['txid','address'], true)) {
                $strong = true;
            }
        }
        if (abs(Numbers::ms($w['occurred_at']) - Numbers::ms($d['occurred_at'])) <= $this->settings['transfer_window_ms']) {
            $score += 10;
        } else {
            return 0;
        }
        $difference = bcsub($w['amount'], $d['amount'], 24);
        if (str_starts_with($difference, '-')) {
            $difference = substr($difference, 1);
        }
        if (bccomp($difference, bcmul($w['amount'], $this->settings['transfer_amount_tolerance'], 24), 24) <= 0) {
            $score += 10;
        } else {
            return 0;
        }
        // Amount and time alone must never auto-link a transfer.
        return $strong ? min(100, $score) : min($this->settings['transfer_medium_score'] - 1, $score);
    }
    public function autoLink(): array
    {
        $linked = [];
        foreach ($this->candidates() as $candidate) {
            if ($candidate['confidence'] !== 'high') {
                continue;
            }
            try {
                $linked[] = $this->link($candidate['withdrawal'], $candidate['deposit'], false);
            } catch (RuntimeException $error) {
                $linked[] = $candidate + ['linked' => false,'reason' => $error->getMessage()];
            }
        }
        return $linked;
    }
    private function refreshLinked(): void
    {
        $pairs = [];
        foreach ($this->accounting->rows("SELECT * FROM operations WHERE kind='withdrawal' ORDER BY occurred_at,id") as $op) {
            $link = Numbers::decode($op['metadata_json'])['m3']['transfer'] ?? null;
            if ($link !== null) {
                $pairs[] = [$op['id'],$link['counterpart'],(bool)$link['approved']];
            }
        }
        for ($pass = 0;$pass <= count($pairs);$pass++) {
            $changed = false;
            foreach ($pairs as [$withdrawal,$deposit,$approved]) {
                $result = $this->link($withdrawal, $deposit, $approved, true);
                $changed = $changed || !($result['idempotent'] ?? false);
            }
            if (!$changed) {
                return;
            }
        }
        throw new RuntimeException('Transfer cost provenance contains an unresolved dependency cycle.');
    }
    public function link(string $withdrawal, string $deposit, bool $approved, bool $refresh = false): array
    {
        if ($this->dataDir !== null && (new History($this->db, $this->dataDir))->hasArchives()) {
            $history = new History($this->db, $this->dataDir);
            $expanded = $history->expand();
            $result = (new self($expanded, $this->settings))->link($withdrawal, $deposit, $approved);
            if (!($result['idempotent'] ?? false)) {
                $history->apply($expanded, 'late_transfer:'.$withdrawal.':'.$deposit);
            }
            return $result + ['immutable_archives_preserved' => true];
        }
        return $this->db->transaction(function () use ($withdrawal, $deposit, $approved, $refresh): array {
            $w = $this->accounting->rows('SELECT * FROM operations WHERE id=?', [$withdrawal])[0] ?? throw new RuntimeException('Unknown withdrawal.');
            $d = $this->accounting->rows('SELECT * FROM operations WHERE id=?', [$deposit])[0] ?? throw new RuntimeException('Unknown deposit.');
            if ($w['kind'] !== 'withdrawal' || $d['kind'] !== 'deposit' || $w['account_id'] === $d['account_id'] || $w['currency'] !== $d['currency']) {
                throw new RuntimeException('Incompatible transfer operations.');
            }
            $wm = Numbers::decode($w['metadata_json']);
            $dm = Numbers::decode($d['metadata_json']);
            $alreadyLinked = ($wm['m3']['transfer']['counterpart'] ?? null) === $deposit && ($dm['m3']['transfer']['counterpart'] ?? null) === $withdrawal;
            if ($alreadyLinked && !$refresh) {
                return ['linked' => true,'idempotent' => true];
            }
            if ($alreadyLinked && $refresh) {
                unset($wm['m3']['transfer'],$dm['m3']['transfer']);
            }
            if (isset($wm['m3']['transfer']) || isset($dm['m3']['transfer'])) {
                throw new RuntimeException('An operation is already linked.');
            }
            if (!$approved && !$alreadyLinked) {
                $matches = array_filter($this->candidates(), static fn ($c) => $c['withdrawal'] === $withdrawal && $c['deposit'] === $deposit && $c['confidence'] === 'high');
                if (count($matches) !== 1) {
                    throw new RuntimeException('Ambiguous transfer requires explicit approval.');
                }
            }
            // Canonical amount is principal; same-currency fee is a separate additional debit.
            if (bccomp($w['amount'], $d['amount'], 24) !== 0) {
                throw new RuntimeException('Unexplained transfer amount difference; confirmed fee evidence is required.');
            }
            if ($w['market_id'] === null || $d['market_id'] === null) {
                throw new RuntimeException('Transfer cost attribution requires registered inventory markets.');
            }
            $sourceMarket = $this->accounting->rows('SELECT * FROM markets WHERE id=?', [$w['market_id']])[0];
            $targetMarket = $this->accounting->rows('SELECT * FROM markets WHERE id=?', [$d['market_id']])[0];
            $rate = null;
            if ($sourceMarket['quote_currency'] !== $targetMarket['quote_currency']) {
                $rate = $dm['m3']['fx_rates'][$sourceMarket['quote_currency']] ?? null;
                if ($rate === null || ($rate['quote'] ?? null) !== $targetMarket['quote_currency']) {
                    throw new RuntimeException('Transfer quote currencies differ; an audited historical conversion is required.');
                }
                $rate['price'] = Ledger::decimal($rate['price'], true);
            }
            $this->accounting->rebuild($w['account_id']);
            $w = $this->accounting->rows('SELECT * FROM operations WHERE id=?', [$withdrawal])[0];
            $sources = [];
            foreach (Numbers::decode($sourceMarket['baseline_lots_json']) as $lot) {
                $sources['baseline:'.$lot['id']] = $lot;
            }
            foreach (['orders','operations'] as $table) {
                foreach ($this->accounting->rows('SELECT * FROM '.$table.' WHERE market_id=?', [$w['market_id']]) as $row) {
                    if (($row['side'] ?? 'BUY') !== 'BUY' || ($row['kind'] ?? 'deposit') !== 'deposit') {
                        continue;
                    }
                    $lots = Lots::acquisition($row, $sourceMarket);
                    if ($lots === null) {
                        // Existing M1 acquisitions retain their exact original ledger representation.
                        $this->db->pdo()->exec('SAVEPOINT legacy_transfer_lots');
                        try {
                            $snapshot = (function () use ($w, $row, $table): array {
                                $this->accounting->execute('DELETE FROM buys_vs_sells WHERE market_id=?', [$w['market_id']]);
                                $this->accounting->execute("UPDATE operations SET lot_consumptions_json='{}' WHERE market_id=?", [$w['market_id']]);
                                return (new Ledger($this->db))->marketLots($w['market_id']);
                            })();
                        } finally {
                            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT legacy_transfer_lots');
                            $this->db->pdo()->exec('RELEASE SAVEPOINT legacy_transfer_lots');
                        }
                        foreach ($snapshot as $lot) {
                            $sources[$lot['source_key']] = $lot;
                        }
                    } else {
                        foreach ($lots as $lot) {
                            $sources[$lot['source_key']] = $lot;
                        }
                    }
                }
            }
            $left = Numbers::sub($d['amount'], Lots::fee($d, $d['currency']));
            $lots = [];
            foreach (Numbers::decode($w['lot_consumptions_json']) as $key => $quantity) {
                $lot = $sources[$key] ?? throw new RuntimeException('Transfer source lot is missing.');
                $take = Numbers::min($quantity, $left);
                if (bccomp($take, '0', 24) === 0) {
                    continue;
                }
                $lots[] = ['id' => hash('sha256', $withdrawal.'|'.$key),'quantity' => $take,'unit_cost' => $lot['unit_cost'] === null ? null : ($rate === null ? $lot['unit_cost'] : Numbers::decimal(bcmul($lot['unit_cost'], $rate['price'], 24))),
                    'acquired_at' => $lot['acquired_at'],'origin' => ['kind' => 'transfer','source_account' => $w['account_id'],'source_key' => $key,
                        'withdrawal_id' => $withdrawal,'quote_currency' => $sourceMarket['quote_currency'],'original_unit_cost' => $lot['unit_cost'],'conversion' => $rate,'acquisition' => $lot['origin'] ?? []]];
                $left = Numbers::sub($left, $take);
                if (bccomp($left, '0', 24) === 0) {
                    break;
                }
            }
            if (bccomp($left, '0', 24) !== 0) {
                throw new RuntimeException('Transfer inventory is incomplete.');
            }
            if ($alreadyLinked && ($dm['m3']['acquisition_lots'] ?? null) === $lots) {
                return ['linked' => true,'idempotent' => true];
            }
            $transferId = hash('sha256', $withdrawal.'|'.$deposit);
            $at = gmdate('c');
            $wm['m3']['transfer'] = ['id' => $transferId,'counterpart' => $deposit,'approved' => $approved,'at' => $at];
            $dm['m3']['transfer'] = ['id' => $transferId,'counterpart' => $withdrawal,'approved' => $approved,'at' => $at];
            $dm['m3']['basis_audit'][] = ['at' => $at,'previous_basis' => $d['price_basis'],'previous_lots' => $dm['m3']['acquisition_lots'] ?? null,'reason' => 'linked_transfer'];
            $dm['m3']['acquisition_lots'] = $lots;
            foreach ([[$w,$wm],[$d,$dm]] as [$row,$meta]) {
                $this->accounting->execute('UPDATE operations SET metadata_json=? WHERE id=?', [Numbers::json($meta),$row['id']]);
                $this->accounting->touch($row['account_id'], $row['market_id']);
            }
            $this->accounting->rebuild($d['account_id']);
            if (!$refresh) {
                $this->refreshLinked();
            }
            return ['linked' => true,'transfer_id' => $transferId,'withdrawal' => $withdrawal,'deposit' => $deposit,'lots' => count($lots)];
        });
    }
}
