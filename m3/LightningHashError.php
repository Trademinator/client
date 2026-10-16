<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** Safe local diagnostics: fixed field names, states and digests, never raw API values. */
final class LightningHashError extends \RuntimeException
{
    public readonly array $diagnostic;

    public function __construct(array $row, string $gross, public readonly string $state, array $fields)
    {
        [$reason, $message] = match ($state) {
            'invalid_format' => ['withdrawal_payment_hash_invalid',
                'Bitso withdrawal payment hash has an invalid format; expected 64 hexadecimal characters.'],
            'mismatch' => ['withdrawal_payment_hash_mismatch',
                'Bitso supplied a payment hash different from the verified Lightning invoice; inspect hash_fields.'],
            default => throw new \InvalidArgumentException('Invalid Lightning hash diagnostic state.'),
        };
        $this->diagnostic = ['reason' => $reason]
            + WithdrawalEvidenceError::withdrawalContext($row, $gross) + $fields;
        parent::__construct('Completed withdrawal at '.$this->diagnostic['withdrawal_created_at']
            .' totals '.$this->diagnostic['withdrawal_total_debit'].' '.$this->diagnostic['withdrawal_currency']
            .' (balance debit). '.$message);
    }
}
