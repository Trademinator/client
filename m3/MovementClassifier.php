<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** Review classification only. A proposed source never creates financial inventory. */
final class MovementClassifier
{
    /** Only allow public category names into review metadata. */
    public static function safeKind(mixed $kind): string
    {
        $kind = is_string($kind) ? strtolower($kind) : 'unknown';
        return in_array($kind, ['unknown','transfer','transaction','deposit','withdrawal','trade','buy','sell',
            'conversion','swap','fee','rebate','reward','airdrop','interest','referral','payout','dividend',
            'staking','adjustment','loan','borrow','repayment','cashback'], true) ? $kind : 'unknown';
    }

    public static function supportsUserTransfers(string $exchange): bool
    {
        // CCXT's `transfer` capability describes wallet/subaccount methods, not
        // whether the exchange supports payments between different users.
        return in_array(strtolower($exchange), ['bitso', 'ndax', 'coinbase'], true);
    }

    public static function classify(string $exchange, string $kind): array
    {
        $kind = strtolower($kind);
        $transfer = in_array($kind, ['deposit', 'withdrawal'], true);
        $trade = in_array($kind, ['trade', 'buy', 'sell', 'conversion', 'swap'], true);
        if ($transfer || $trade) {
            return ['decision' => 'recognized', 'label' => null, 'status' => 'known',
                'reason' => $transfer ? 'known_deposit_or_withdrawal' : 'known_trade'];
        }
        if (self::supportsUserTransfers($exchange)) {
            return ['decision' => 'soft_label', 'label' => 'user_deposit',
                'status' => 'pending_confirmation', 'reason' => 'unclassified_movement_user_transfer_supported'];
        }
        return ['decision' => 'reject', 'label' => null, 'status' => 'rejected',
            'reason' => 'unclassified_movement_user_transfer_unsupported', 'red_flag' => true];
    }
}
