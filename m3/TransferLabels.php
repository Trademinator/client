<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/**
 * Provisional presentation metadata only; never creates or changes a financial event.
 * Suggestions establish neither counterparty ownership, fees, cost basis nor history completeness.
 */
final class TransferLabels
{
    private const EXCHANGES = ['bitso', 'ndax', 'coinbase'];
    private const EXTERNAL_FIELDS = [
        'txid', 'tx_hash', 'payment_hash', 'transaction_hash', 'hash', 'TXId', 'TxId',
        'address', 'addressFrom', 'addressTo', 'tag', 'tagFrom', 'tagTo',
        'withdrawal_address', 'receiving_address', 'ExternalAddress', 'ToAddress', 'FromAddress',
        'invoice', 'payment_request', 'bank_account', 'bank_account_id', 'bank_account_number',
        'account_number', 'clabe', 'iban', 'swift', 'clave_de_rastreo', 'tracking_key', 'comprobante',
        'transaction_url', 'network_name',
    ];
    private const ROUTE_FIELDS = [
        'method', 'network', 'protocol', 'integration', 'payment_method',
        'WithdrawType', 'TemplateFormType', 'TemplateType', 'AccountProviderName',
    ];

    public static function suggest(string $exchange, array $row, string $kind): ?array
    {
        $exchange = strtolower($exchange);
        if (!in_array($exchange, self::EXCHANGES, true)
            || !in_array($kind, ['deposit', 'withdrawal'], true) || ($row['status'] ?? null) !== 'ok') {
            return null;
        }
        // An explicit false is evidence against an internal transfer, not an absent flag.
        if (($row['internal'] ?? null) === false
            || (isset($row['internal']) && !is_bool($row['internal']))
            || (isset($row['info']) && !is_array($row['info']))) {
            return null;
        }
        $raw = $row['info'] ?? [];
        $scopes = [$row, $raw];
        if (isset($raw['details'])) {
            if (!is_array($raw['details'])) {
                return null;
            }
            $scopes[] = $raw['details'];
        }
        // NDAX embeds payment routing in JSON strings. Inspect it in memory; never retain it.
        if ($exchange === 'ndax') {
            foreach (['TemplateForm', 'DepositInfo'] as $field) {
                if (!self::present($raw[$field] ?? null)) {
                    continue;
                }
                $value = $raw[$field];
                if (is_string($value) && strlen($value) <= 65536) {
                    $value = json_decode($value, true, 32);
                }
                if (!is_array($value)) {
                    return null;
                }
                $scopes[] = $value;
            }
        }
        if (self::otherActivity($scopes, $exchange, $kind)) {
            return null;
        }

        $bitsoRoute = $exchange === 'bitso';
        foreach (['method', 'network', 'protocol', 'integration'] as $field) {
            $bitsoRoute = $bitsoRoute && self::category($raw[$field] ?? null) === 'bt';
        }
        $network = is_array($raw['network'] ?? null) ? $raw['network'] : [];
        $coinbaseRoute = $exchange === 'coinbase'
            && in_array(self::category($raw['type'] ?? null), ['send', 'receive'], true)
            && self::category($network['status'] ?? null) === 'off_blockchain';
        $counterparty = $exchange === 'ndax'
            ? self::identityPresent($raw['Counterparty'] ?? null)
            : ($exchange === 'coinbase' && self::coinbaseUser($raw));
        $ndaxRoute = $exchange === 'ndax'
            && self::category($raw['ReferenceType'] ?? null) === 'transfer' && $counterparty;
        if ($exchange === 'ndax' && self::category($raw['ReferenceType'] ?? null) === 'transfer'
            && (!$counterparty || (self::identityPresent($raw['AccountId'] ?? null)
                && (string)$raw['Counterparty'] === (string)$raw['AccountId']))) {
            return null;
        }

        foreach ($scopes as $index => $scope) {
            foreach (self::EXTERNAL_FIELDS as $field) {
                if (!self::present($scope[$field] ?? null)) {
                    continue;
                }
                // Pinned CCXT Coinbase uses the transaction ID when network.hash is absent.
                // Only explicit native off-blockchain evidence can override that placeholder.
                if ($index === 0 && $field === 'txid' && $coinbaseRoute
                    && self::identityPresent($row['id'] ?? null)
                    && is_scalar($row['txid']) && (string)$row['txid'] === (string)$row['id']) {
                    continue;
                }
                return null;
            }
            foreach (self::ROUTE_FIELDS as $field) {
                if (!self::present($scope[$field] ?? null)) {
                    continue;
                }
                if ($bitsoRoute && in_array($field, ['method', 'network', 'protocol', 'integration'], true)
                    && self::category($scope[$field]) === 'bt') {
                    continue;
                }
                if ($coinbaseRoute && $index === 1 && $field === 'network') {
                    continue;
                }
                // Unknown routes stay unknown rather than being guessed from exchange capability.
                return null;
            }
        }
        if ($exchange === 'coinbase') {
            foreach (self::EXTERNAL_FIELDS as $field) {
                if (self::present($network[$field] ?? null)) {
                    return null;
                }
            }
            foreach (['from', 'to'] as $direction) {
                $party = $raw[$direction] ?? null;
                if ($party === null) {
                    continue;
                }
                if (!is_array($party) || (self::present($party['resource'] ?? null)
                    && self::category($party['resource']) !== 'user') || self::present($party['address'] ?? null)) {
                    return null;
                }
            }
        }
        $internal = ($row['internal'] ?? null) === true;
        $reason = $bitsoRoute ? 'bitso_user_transfer_route'
            : ($ndaxRoute ? 'ndax_transfer_reference'
            : ($coinbaseRoute ? 'coinbase_off_blockchain'
            : ($internal ? 'exchange_internal_flag' : 'supported_exchange_without_external_evidence')));
        return [
            'label' => $kind === 'deposit' ? 'user_deposit' : 'user_withdrawal',
            'status' => 'pending_confirmation',
            'reason' => $reason,
            'evidence' => [
                'exchange_supports_user_transfers' => true,
                'internal_flag' => $internal,
                'peer_route' => $bitsoRoute || $ndaxRoute || $coinbaseRoute,
                'counterparty_present' => $counterparty,
                'external_evidence_present' => false,
            ],
        ];
    }

    private static function otherActivity(array $scopes, string $exchange, string $kind): bool
    {
        $categories = ['deposit', 'withdrawal', 'funding', 'send', 'receive', 'transaction', 'transfer'];
        foreach ($scopes as $index => $scope) {
            if ($exchange === 'coinbase' && $index === 1 && self::present($scope['resource'] ?? null)
                && !in_array(self::category($scope['resource']), ['transaction', 'deposit', 'withdrawal'], true)) {
                return true;
            }
            foreach (['type', 'kind', 'category', 'operation', 'ReferenceType', 'transaction_type', 'TransactionType'] as $field) {
                if (!self::present($scope[$field] ?? null)) {
                    continue;
                }
                $category = self::category($scope[$field]);
                if ($field === 'TransactionType' && $category === 'other') {
                    continue;
                }
                if (!in_array($category, $categories, true)
                    || ($index === 0 && in_array($category, ['deposit', 'withdrawal'], true) && $category !== $kind)
                    || ($exchange === 'coinbase' && $index === 1 && $category === 'transfer')) {
                    return true;
                }
            }
            foreach (['buy', 'sell', 'trade', 'trade_id', 'TradeId', 'order', 'order_id', 'OrderId', 'conversion', 'conversion_id',
                'from_currency', 'to_currency', 'fromAccount', 'toAccount', 'from_portfolio', 'to_portfolio',
                'source_portfolio_uuid', 'target_portfolio_uuid'] as $field) {
                if (self::present($scope[$field] ?? null)) {
                    return true;
                }
            }
            if (($scope['instant_exchange'] ?? null) === true) {
                return true;
            }
        }
        return false;
    }

    private static function coinbaseUser(array $raw): bool
    {
        foreach (['from', 'to'] as $direction) {
            $party = $raw[$direction] ?? null;
            if (is_array($party) && self::category($party['resource'] ?? null) === 'user') {
                return true;
            }
        }
        return false;
    }

    private static function category(mixed $value): ?string
    {
        return is_string($value) ? strtolower($value) : null;
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private static function identityPresent(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && (string)$value !== '' && (string)$value !== '0';
    }
}
