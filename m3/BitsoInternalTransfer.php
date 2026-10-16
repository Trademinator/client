<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** Bitso Transfer's fee-free internal transfer format; no blockchain fee applies. */
final class BitsoInternalTransfer
{
    public const RULE = 'bitso_transfer_bt_v1';
    public const DOCUMENTATION = 'https://bitso.com/products/transfer';

    public static function zeroFeeEvidence(array $listed, array $lookup, array $row, string $gross): ?array
    {
        // The caller has verified both responses describe the same completed
        // withdrawal: ID, currency, timestamp and exact total balance debit.
        // Require the observed bt tuple in BOTH responses. A currency, display
        // name, missing network or one isolated bt field is not sufficient.
        foreach ([$listed, $lookup] as $raw) {
            if (($raw['status'] ?? null) !== 'complete') {
                return null;
            }
            foreach (['method', 'network', 'protocol', 'integration'] as $field) {
                if (!is_string($raw[$field] ?? null) || strtolower($raw[$field]) !== 'bt') {
                    return null;
                }
            }
        }
        // Bitso describes transfers between its users as fee-free for any
        // supported currency. Keep this method rule distinct from an explicit
        // fee on the record, which always takes precedence in the adapter.
        return ['source' => 'bitso_transfer_contract', 'basis' => 'exchange_documentation',
            'rule' => self::RULE, 'documentation' => self::DOCUMENTATION,
            'currency' => Normalize::currency($row['currency'] ?? null),
            'method' => 'bt', 'network' => 'BT', 'protocol' => 'bt', 'integration' => 'bt',
            'amount' => Numbers::decimal($gross), 'fee' => '0'];
    }

    public static function normalizeEvidence(array $evidence, array $operation): array
    {
        if (($operation['kind'] ?? null) !== 'withdrawal' || ($operation['network'] ?? null) !== 'BT'
            || ($operation['fees'] ?? null) !== [] || ($evidence['source'] ?? null) !== 'bitso_transfer_contract'
            || ($evidence['basis'] ?? null) !== 'exchange_documentation'
            || ($evidence['rule'] ?? null) !== self::RULE || ($evidence['documentation'] ?? null) !== self::DOCUMENTATION
            || ($evidence['currency'] ?? null) !== $operation['currency']
            || ($evidence['method'] ?? null) !== 'bt' || ($evidence['network'] ?? null) !== 'BT'
            || ($evidence['protocol'] ?? null) !== 'bt' || ($evidence['integration'] ?? null) !== 'bt'
            || ($evidence['amount'] ?? null) !== $operation['amount'] || ($evidence['fee'] ?? null) !== '0'
            || isset($evidence['operator_confirmation'])) {
            throw new \RuntimeException('Invalid Bitso Transfer fee accounting evidence.');
        }
        return array_intersect_key($evidence, array_flip([
            'source', 'basis', 'rule', 'documentation', 'currency', 'method', 'network',
            'protocol', 'integration', 'amount', 'fee',
        ]));
    }
}
