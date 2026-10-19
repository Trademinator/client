<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Trademinator\Client\M1\Database;

/** Review unknown movements and funding without manufacturing financial operations. */
final class MovementReviews
{
    private const EVIDENCE = ['movement_not_deposit_or_withdrawal', 'movement_not_trade',
        'exchange_supports_user_transfers'];

    public function __construct(private Database $db) {}

    /** Call outside any failed financial transaction so the review survives its rollback. */
    public function observeInventoryGap(string $account, InventoryEvidenceError $error): ?array
    {
        $diagnostic = $error->diagnostic;
        if (($diagnostic['reason'] ?? null) !== 'inventory_shortfall'
            || bccomp(Numbers::decimal($diagnostic['pending_execution_acquisition_quantity'] ?? null), '0', 24) !== 0) {
            return null;
        }
        $exchange = $this->exchange($account);
        if ($exchange === null) { return null; }
        $sourceRef = $diagnostic['outflow_ref'] ?? null;
        if (!is_string($sourceRef) || !preg_match('/^[a-f0-9]{16}$/D', $sourceRef)) {
            throw new RuntimeException('Invalid unresolved-movement reference.');
        }
        // The outflow is already classified. It is the missing funding source
        // that has no deposit/withdrawal or BUY/SELL record to explain it.
        $source = ['source_kind' => 'inventory_shortfall', 'source_ref' => $sourceRef,
            'currency' => Normalize::currency($diagnostic['outflow_currency'] ?? null),
            'amount' => self::amount($diagnostic['inventory_shortfall'] ?? null),
            'at' => Numbers::iso(Numbers::ms($diagnostic['outflow_at'])),
            'movement_kind' => null, 'direction' => null];
        return $this->db->transaction(fn (): array => $this->persist($account, $exchange, $source, 'unknown'));
    }

    /** Only complete snapshots supply observed, normalized ledger-movement evidence. */
    public function observeLedgerMovements(string $account, array $batch): array
    {
        if (($batch['complete'] ?? false) !== true || empty($batch['unclassified_movements'])) { return []; }
        if (!is_array($batch['unclassified_movements']) || !array_is_list($batch['unclassified_movements'])) {
            throw new RuntimeException('Invalid unclassified movement batch.');
        }
        $exchange = $this->exchange($account);
        if ($exchange === null) { return []; }
        return $this->db->transaction(function () use ($account, $exchange, $batch): array {
            $observed = [];
            foreach ($batch['unclassified_movements'] as $movement) {
                if (!is_array($movement)) { throw new RuntimeException('Invalid unclassified movement.'); }
                $kind = MovementClassifier::safeKind($movement['kind'] ?? null);
                if (MovementClassifier::classify($exchange, $kind)['decision'] === 'recognized') { continue; }
                $sourceRef = $movement['source_ref'] ?? null;
                if (!is_string($sourceRef) || !preg_match('/^[a-f0-9]{64}$/D', $sourceRef)) {
                    throw new RuntimeException('Invalid unclassified movement reference.');
                }
                $direction = $movement['direction'] ?? null;
                if ($direction !== null && !in_array($direction, ['in','out'], true)) {
                    throw new RuntimeException('Invalid unclassified movement direction.');
                }
                $source = ['source_kind' => 'ledger_movement', 'source_ref' => $sourceRef,
                    'currency' => Normalize::currency($movement['currency'] ?? null),
                    'amount' => self::amount($movement['amount'] ?? null),
                    'at' => Numbers::iso(Normalize::timestamp($movement)),
                    'movement_kind' => $kind, 'direction' => $direction];
                $review = $this->persist($account, $exchange, $source, $kind);
                // Duplicate history pages remain one review and one notification.
                $review['changed'] = $review['changed'] || ($observed[$review['ref']]['changed'] ?? false);
                $observed[$review['ref']] = $review;
            }
            return array_values($observed);
        });
    }

    private function exchange(string $account): ?string
    {
        $q = $this->db->pdo()->prepare('SELECT exchange FROM exchange_accounts WHERE id=?');
        $q->execute([$account]);
        $exchange = $q->fetchColumn();
        return is_string($exchange) ? $exchange : null;
    }

    private static function amount(mixed $value): string
    {
        $amount = Numbers::decimal($value);
        if (bccomp($amount, '0', 24) <= 0) { throw new RuntimeException('Invalid unresolved-movement quantity.'); }
        return $amount;
    }

    /** Caller holds the review transaction; these rows never participate in wallet arithmetic. */
    private function persist(string $account, string $exchange, array $source, string $kind): array
    {
        $classification = MovementClassifier::classify($exchange, $kind);
        $decision = $classification['decision'] ?? null;
        $reason = $classification['reason'] ?? null;
        if (!in_array($decision, ['soft_label', 'reject'], true)
            || !is_string($reason) || !preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $reason)) {
            throw new RuntimeException('Invalid unresolved-movement classification.');
        }
        $evidence = Numbers::json([
            'movement_not_deposit_or_withdrawal' => true,
            'movement_not_trade' => true,
            'exchange_supports_user_transfers' => MovementClassifier::supportsUserTransfers($exchange),
        ]);
        $id = hash('sha256', Numbers::json([$account, $source['source_kind'], $source['source_ref'], $source['currency']]));
        $q = $this->db->pdo()->prepare('SELECT * FROM movement_reviews WHERE id=?');
        $q->execute([$id]);
        $previous = $q->fetch();
        $values = ['amount' => $source['amount'], 'at' => $source['at'],
            'movement_kind' => $source['movement_kind'], 'direction' => $source['direction'],
            'decision' => $decision, 'label' => $decision === 'soft_label' ? 'user_deposit' : null,
            'status' => $decision === 'soft_label' ? 'pending_confirmation' : 'rejected',
            'reason' => $reason, 'evidence_json' => $evidence];
        if ($previous !== false) {
            if ($source['source_kind'] === 'ledger_movement') {
                foreach (['amount','at','direction'] as $field) {
                    if ($previous[$field] !== $source[$field]) {
                        throw new RuntimeException('Conflicting unclassified movement financial evidence.');
                    }
                }
            }
            // A repeated observation cannot overwrite a future human decision.
            if (in_array($previous['status'], ['confirmed', 'explained'], true)) {
                return self::project($previous) + ['changed' => false];
            }
            $changed = false;
            foreach ($values as $key => $value) {
                if ($previous[$key] !== $value) { $changed = true; break; }
            }
            if (!$changed) { return self::project($previous) + ['changed' => false]; }
            $now = gmdate('c');
            $this->db->pdo()->prepare('UPDATE movement_reviews SET amount=?,at=?,movement_kind=?,direction=?,decision=?,label=?,status=?,reason=?,evidence_json=?,updated_at=? WHERE id=?')
                ->execute([...array_values($values), $now, $id]);
            return self::project(array_replace($previous, $values, ['updated_at' => $now])) + ['changed' => true];
        }
        $now = gmdate('c');
        $row = ['id' => $id, 'account_id' => $account, 'source_kind' => $source['source_kind'],
            'source_ref' => $source['source_ref'], 'currency' => $source['currency']]
            + $values + ['first_observed_at' => $now, 'updated_at' => $now];
        $this->db->pdo()->prepare('INSERT INTO movement_reviews('.implode(',', array_keys($row)).') VALUES('.implode(',', array_fill(0, count($row), '?')).')')
            ->execute(array_values($row));
        return self::project($row) + ['changed' => true];
    }

    /** A bounded projection for CLI status and later confirmation/explanation interfaces. */
    public function pending(string $account, int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $where = "account_id=? AND status IN ('pending_confirmation','rejected')";
        $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM movement_reviews WHERE '.$where);
        $q->execute([$account]);
        $count = (int) $q->fetchColumn();
        $q = $this->db->pdo()->prepare('SELECT * FROM movement_reviews WHERE '.$where.' ORDER BY at,id LIMIT '.$limit);
        $q->execute([$account]);
        $items = array_map(self::project(...), $q->fetchAll());
        return ['count' => $count, 'items' => $items, 'truncated' => $count > count($items)];
    }

    /** Successful attribution supersedes gap proposals, never observed movements or human decisions. */
    public function resolveInventoryGaps(string $account): void
    {
        $this->db->pdo()->prepare("UPDATE movement_reviews SET status='superseded',updated_at=? WHERE account_id=? AND source_kind='inventory_shortfall' AND status IN ('pending_confirmation','rejected')")
            ->execute([gmdate('c'), $account]);
    }

    private static function project(array $row): array
    {
        $stored = Numbers::decode($row['evidence_json']);
        $evidence = [];
        foreach (self::EVIDENCE as $key) {
            if (!is_bool($stored[$key] ?? null)) { throw new RuntimeException('Invalid unresolved-movement evidence.'); }
            $evidence[$key] = $stored[$key];
        }
        $source = $row['source_kind'] === 'inventory_shortfall'
            ? ['related_outflow_ref' => $row['source_ref'], 'minimum_unexplained_amount' => $row['amount'], 'required_by' => $row['at']]
            : ['source_ref' => $row['source_ref'], 'amount' => $row['amount'], 'occurred_at' => $row['at'],
                'movement_kind' => $row['movement_kind'], 'direction' => $row['direction']];
        return ['ref' => $row['id'], 'source_kind' => $row['source_kind'], 'currency' => $row['currency']]
            + $source + ['decision' => $row['decision'], 'label' => $row['label'],
                'status' => $row['status'], 'reason' => $row['reason'],
                'red_flag' => $row['decision'] === 'reject', 'evidence' => $evidence];
    }
}
