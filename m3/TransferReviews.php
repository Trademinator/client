<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Trademinator\Client\M1\Database;

/** Durable review proposals, independent of the confirmed financial ledger. */
final class TransferReviews
{
    private const REASONS = ['bitso_user_transfer_route', 'ndax_transfer_reference', 'coinbase_off_blockchain',
        'exchange_internal_flag', 'supported_exchange_without_external_evidence'];
    private const EVIDENCE = ['exchange_supports_user_transfers', 'internal_flag', 'peer_route',
        'counterparty_present', 'external_evidence_present'];

    public function __construct(private Database $db) {}

    /** Observe before financial validation, which can fail or roll back for missing history. */
    public function observe(string $account, array $batch): void
    {
        if (($batch['complete'] ?? false) !== true || empty($batch['transfer_labels'])) { return; }
        $q = $this->db->pdo()->prepare('SELECT exchange FROM exchange_accounts WHERE id=?');
        $q->execute([$account]);
        if (!in_array($q->fetchColumn(), ['bitso','ndax','coinbase'], true)) { return; }
        $this->db->transaction(function () use ($account, $batch): void {
            foreach ($batch['operations'] ?? [] as $op) {
                $proposal = $batch['transfer_labels'][$op['id']] ?? null;
                if ($proposal === null) { continue; }
                $kind = $op['kind'] ?? null;
                if (!in_array($kind, ['deposit','withdrawal'], true)) { throw new RuntimeException('Invalid transfer label evidence.'); }
                $label = self::safeLabel($proposal, $kind);
                // Normalize::operation prefixes an already validated exchange ID;
                // its full length can exceed the 200-byte raw-ID limit.
                $identity = $op['id'];
                if (!is_string($identity) || !str_starts_with($identity, $kind.':')) {
                    throw new RuntimeException('Invalid transfer label identity.');
                }
                Normalize::id(substr($identity, strlen($kind) + 1));
                $currency = Normalize::currency($op['currency'] ?? null);
                $amount = Numbers::decimal($op['amount'] ?? null);
                if (bccomp($amount, '0', 24) <= 0) { throw new RuntimeException('Invalid transfer label amount.'); }
                $at = Normalize::timestamp($op);
                $fingerprint = hash('sha256', Numbers::json([$identity, $kind, $currency, $amount,
                    Normalize::fees($op), $at]));
                $q = $this->db->pdo()->prepare('SELECT * FROM transfer_labels WHERE account_id=? AND exchange_operation_id=?');
                $q->execute([$account, $identity]);
                $previous = $q->fetch();
                if ($previous !== false && $previous['financial_fingerprint'] !== $fingerprint) {
                    throw new RuntimeException('Conflicting transfer financial evidence.');
                }
                $evidence = Numbers::json($label['evidence']);
                if ($previous !== false) {
                    // Neither repeated observations nor future enrichment can undo a human decision.
                    if ($previous['status'] === 'pending_confirmation'
                        && ($previous['reason'] !== $label['reason'] || $previous['evidence_json'] !== $evidence)) {
                        $this->db->pdo()->prepare('UPDATE transfer_labels SET reason=?,evidence_json=?,updated_at=? WHERE id=?')
                            ->execute([$label['reason'], $evidence, gmdate('c'), $previous['id']]);
                    }
                    continue;
                }
                $now = gmdate('c');
                $this->db->pdo()->prepare('INSERT INTO transfer_labels(id,account_id,exchange_operation_id,kind,currency,amount,occurred_at,financial_fingerprint,label,status,reason,evidence_json,first_observed_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([hash('sha256', Numbers::json([$account, $identity])), $account, $identity, $kind, $currency,
                        $amount, Numbers::iso($at), $fingerprint, $label['label'], 'pending_confirmation', $label['reason'], $evidence, $now, $now]);
            }
        });
    }

    /** A bounded, redacted projection for today's CLI and the future user interfaces. */
    public function pending(string $account, int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $q = $this->db->pdo()->prepare("SELECT COUNT(*) FROM transfer_labels WHERE account_id=? AND status='pending_confirmation'");
        $q->execute([$account]);
        $count = (int) $q->fetchColumn();
        $q = $this->db->pdo()->prepare("SELECT * FROM transfer_labels WHERE account_id=? AND status='pending_confirmation' ORDER BY occurred_at,id LIMIT ".$limit);
        $q->execute([$account]);
        $items = [];
        foreach ($q->fetchAll() as $row) {
            $safe = self::safeLabel(['label' => $row['label'], 'status' => 'pending_confirmation',
                'reason' => $row['reason'], 'evidence' => Numbers::decode($row['evidence_json'])], $row['kind']);
            $items[] = ['ref' => $row['id'], 'kind' => $row['kind'], 'currency' => $row['currency'],
                'amount' => $row['amount'], 'occurred_at' => $row['occurred_at']] + $safe;
        }
        return ['count' => $count, 'items' => $items, 'truncated' => $count > count($items)];
    }

    private static function safeLabel(array $proposal, string $kind): array
    {
        if (($proposal['label'] ?? null) !== 'user_'.$kind || ($proposal['status'] ?? null) !== 'pending_confirmation'
            || !in_array($proposal['reason'] ?? null, self::REASONS, true) || !is_array($proposal['evidence'] ?? null)) {
            throw new RuntimeException('Invalid transfer label evidence.');
        }
        $evidence = [];
        foreach (self::EVIDENCE as $key) {
            if (!is_bool($proposal['evidence'][$key] ?? null)) { throw new RuntimeException('Invalid transfer label evidence.'); }
            $evidence[$key] = $proposal['evidence'][$key];
        }
        if (!$evidence['exchange_supports_user_transfers'] || $evidence['external_evidence_present']) {
            throw new RuntimeException('Invalid transfer label evidence.');
        }
        return ['label' => 'user_'.$kind, 'status' => 'pending_confirmation', 'reason' => $proposal['reason'], 'evidence' => $evidence];
    }
}
