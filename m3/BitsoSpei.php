<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** Interpret the documented MXN SPEI response, whose details omit fee/net fields. */
final class BitsoSpei
{
    public const RULE = 'mxn_spei_praxis_v1';
    public const DOCUMENTATION = 'https://docs.bitso.com/bitso-payouts-funding/docs/mxn-withdrawals';

    public static function zeroFeeEvidence(array $listed, array $lookup, string $gross): ?array
    {
        // The caller has verified the same completed withdrawal's identity,
        // currency, timestamp and total debit in both authenticated responses.
        // Do not apply current /fees values, currency-only defaults, or this
        // contract to Juno, MXNB or an unrecognized withdrawal method.
        $taxonomy = [];
        foreach ([$listed, $lookup] as $raw) {
            if (strtolower((string)($raw['currency'] ?? '')) !== 'mxn' || ($raw['status'] ?? null) !== 'complete') {
                return null;
            }
            foreach (['asset', 'method', 'network', 'protocol', 'integration'] as $field) {
                $value = $raw[$field] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (!is_string($value)) {
                    return null;
                }
                $value = strtolower($value);
                if (isset($taxonomy[$field]) && $taxonomy[$field] !== $value) {
                    throw new \RuntimeException('Bitso individual withdrawal payment method differs from the list record.');
                }
                $taxonomy[$field] = $value;
            }
        }
        if (($taxonomy['method'] ?? null) !== 'praxis' || ($taxonomy['network'] ?? null) !== 'spei'
            || !in_array($taxonomy['protocol'] ?? null, ['clabe', 'debitcard', 'phonenum'], true)
            || ($taxonomy['asset'] ?? 'mxn') !== 'mxn' || ($taxonomy['integration'] ?? 'praxis') !== 'praxis') {
            return null;
        }
        // This is a documented method rule, not a fee reported on the record.
        // Preserve that distinction in the financial audit evidence.
        return ['source' => 'bitso_spei_contract', 'basis' => 'exchange_documentation',
            'rule' => self::RULE, 'documentation' => self::DOCUMENTATION,
            'method' => 'praxis', 'network' => 'SPEI', 'protocol' => $taxonomy['protocol'],
            'amount' => Numbers::decimal($gross), 'fee' => '0'];
    }

    public static function normalizeEvidence(array $evidence, array $operation): array
    {
        if (($operation['kind'] ?? null) !== 'withdrawal' || ($operation['currency'] ?? null) !== 'MXN'
            || ($operation['network'] ?? null) !== 'SPEI' || ($operation['fees'] ?? null) !== []
            || ($evidence['source'] ?? null) !== 'bitso_spei_contract'
            || ($evidence['basis'] ?? null) !== 'exchange_documentation'
            || ($evidence['rule'] ?? null) !== self::RULE || ($evidence['documentation'] ?? null) !== self::DOCUMENTATION
            || ($evidence['method'] ?? null) !== 'praxis' || ($evidence['network'] ?? null) !== 'SPEI'
            || !in_array($evidence['protocol'] ?? null, ['clabe', 'debitcard', 'phonenum'], true)
            || ($evidence['amount'] ?? null) !== $operation['amount'] || ($evidence['fee'] ?? null) !== '0'
            || isset($evidence['operator_confirmation'])) {
            throw new \RuntimeException('Invalid Bitso SPEI fee accounting evidence.');
        }
        return array_intersect_key($evidence, array_flip([
            'source', 'basis', 'rule', 'documentation', 'method', 'network', 'protocol', 'amount', 'fee',
        ]));
    }
}
