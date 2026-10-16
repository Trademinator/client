<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** A locally constructed message identifying the withdrawal the operator can inspect. */
final class WithdrawalEvidenceError extends \RuntimeException
{
    public readonly array $diagnostic;

    public function __construct(array $row, string $gross, array $listed = [], ?array $lookup = null, array $credentials = [])
    {
        $this->diagnostic = self::withdrawalContext($row, $gross) + self::taxonomyContext($listed, $lookup, $credentials) + [
            'withdrawal_lookup_attempted' => true,
            'list_has_fee' => false, 'list_has_amount_to_send' => false,
            'lookup_has_fee' => false, 'lookup_has_amount_to_send' => false,
        ];
        parent::__construct('Completed withdrawal at '.$this->diagnostic['withdrawal_created_at']
            .' totals '.$this->diagnostic['withdrawal_total_debit'].' '.$this->diagnostic['withdrawal_currency']
            .' (balance debit). Fee and net amount are absent from both list and individual lookup, and no supported automatic fee rule applies; check this withdrawal in Bitso.');
    }

    public static function withdrawalContext(array $row, string $gross): array
    {
        return [
            'withdrawal_ref' => substr(hash('sha256', Normalize::id($row['id'] ?? null)), 0, 16),
            'withdrawal_created_at' => Numbers::iso(Normalize::timestamp($row)),
            'withdrawal_currency' => Normalize::currency($row['currency'] ?? null),
            'withdrawal_total_debit' => Numbers::decimal($gross),
        ];
    }

    /** Bounded method codes and presence flags only; never include private details. */
    public static function taxonomyContext(array $listed, ?array $lookup = null, array $credentials = []): array
    {
        $out = [];
        foreach (['list' => $listed, 'lookup' => $lookup] as $source => $raw) {
            if ($raw === null) {
                continue;
            }
            $taxonomy = [];
            foreach (['method', 'network', 'protocol', 'integration'] as $field) {
                $taxonomy[$field] = self::methodCode($raw[$field] ?? null, $credentials);
            }
            $details = is_array($raw['details'] ?? null) ? $raw['details'] : [];
            $taxonomy['spei_tracking_present'] = isset($details['clave_de_rastreo']);
            $taxonomy['spei_receipt_present'] = isset($details['cep_link']);
            $out[$source] = $taxonomy;
        }
        return ['withdrawal_taxonomy' => $out];
    }

    private static function methodCode(mixed $value, array $credentials): string
    {
        if ($value === null || $value === '') {
            return 'missing';
        }
        if (!is_string($value)) {
            return 'invalid_type';
        }
        // These four API fields contain public machine codes. Recognition for
        // accounting is separate: a new code must be visible for diagnosis, but
        // does not make its fee zero or authorize reconciliation.
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/iD', $value)
            || preg_match('/^[a-f0-9]{16,}$/iD', $value)
            || preg_match('/^ln(?:bc|tb|bcrt)/i', $value)) {
            return 'redacted';
        }
        foreach ($credentials as $credential) {
            if (is_string($credential) && $credential !== '' && stripos($value, $credential) !== false) {
                return 'redacted';
            }
        }
        return strtolower($value);
    }
}
