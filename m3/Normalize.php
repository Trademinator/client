<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;

/** Only CCXT unified fields cross this boundary; raw info may contain private data. */
final class Normalize
{
    public static function fees(array $row): array
    {
        $fees = $row['fees'] ?? null;
        if (!$fees) {
            $fees = empty($row['fee']) ? [] : [$row['fee']];
        }
        $result = [];
        foreach ($fees as $fee) {
            if (!isset($fee['cost'])) {
                throw new RuntimeException('Exchange fee amount is missing.');
            }
            $amount = Numbers::decimal($fee['cost']);
            if (bccomp($amount, '0', 24) === 0) {
                continue;
            }
            $currency = self::currency($fee['currency'] ?? null);
            $result[$currency] = Numbers::add($result[$currency] ?? '0', $amount);
        }
        ksort($result);
        return array_map(static fn ($c, $n) => ['currency' => $c,'cost' => $n], array_keys($result), array_values($result));
    }
    public static function currency(mixed $s): string
    {
        if (!is_string($s) || !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/D', $s)) {
            throw new RuntimeException('Exchange currency is missing or invalid.');
        } return $s;
    }
    public static function id(mixed $id): string
    {
        if ((!is_string($id) && !is_int($id)) || (string)$id === '' || strlen((string)$id) > 200) {
            throw new RuntimeException('Stable exchange identity is required.');
        } return (string)$id;
    }
    public static function timestamp(array $row): int
    {
        if (!is_int($row['timestamp'] ?? null) || $row['timestamp'] < 0) {
            throw new RuntimeException('Exchange timestamp is required.');
        } return $row['timestamp'];
    }
    public static function trade(array $row): array
    {
        $symbol = self::symbol($row['symbol'] ?? null);
        $side = strtoupper($row['side'] ?? '');
        if (!in_array($side, ['BUY','SELL'], true)) {
            throw new RuntimeException('Unknown exchange trade side.');
        }
        $amount = Numbers::decimal($row['amount'] ?? null);
        $price = Numbers::decimal($row['price'] ?? null);
        $cost = Numbers::decimal($row['cost'] ?? null);
        if (bccomp($amount, '0', 24) <= 0 || bccomp($price, '0', 24) <= 0 || bccomp($cost, '0', 24) <= 0) {
            throw new RuntimeException('Trade amount, price and cost must be positive.');
        }
        return ['id' => self::id($row['id'] ?? null),'order' => self::id($row['order'] ?? null),'symbol' => $symbol,'side' => $side,
            'timestamp' => self::timestamp($row),'amount' => $amount,'price' => $price,'cost' => $cost,'fees' => self::fees($row)];
    }
    public static function symbol(mixed $s): string
    {
        if (!is_string($s) || count(explode('/', $s)) !== 2 || str_contains($s, ':')) {
            throw new RuntimeException('M3 requires a unified spot BASE/QUOTE symbol.');
        }
        foreach (explode('/', $s) as $c) {
            self::currency($c);
        } return $s;
    }
    public static function order(array $row): array
    {
        $status = match($row['status'] ?? null) {
            'open' => 'open','closed' => 'filled','canceled','cancelled' => 'cancelled','expired' => 'expired','rejected' => 'rejected',default => throw new RuntimeException('Unknown exchange order status.')
        };
        $filled = Numbers::decimal($row['filled'] ?? null);
        $amount = Numbers::decimal($row['amount'] ?? null);
        if (bccomp($filled, $amount, 24) > 0 || bccomp($amount, '0', 24) <= 0) {
            throw new RuntimeException('Invalid cumulative order quantity.');
        }
        if ($status === 'open' && bccomp($filled, '0', 24) > 0) {
            $status = 'partially_filled';
        }
        if ($status === 'filled' && bccomp($filled, $amount, 24) !== 0) {
            throw new RuntimeException('Closed order is not fully filled; exchange status is ambiguous.');
        }
        $side = strtoupper($row['side'] ?? '');
        if (!in_array($side, ['BUY','SELL'], true)) {
            throw new RuntimeException('Missing order side.');
        }
        return ['id' => self::id($row['id'] ?? null),'symbol' => self::symbol($row['symbol'] ?? null),'side' => $side,
            'type' => strtoupper($row['type'] ?? 'LIMIT'),'status' => $status,'amount' => $amount,'filled' => $filled,
            'price' => isset($row['price']) ? Numbers::decimal($row['price']) : null,
            'cost' => isset($row['cost']) ? Numbers::decimal($row['cost']) : null,
            'timestamp' => self::timestamp($row),'client_order_id' => isset($row['clientOrderId']) ? self::id($row['clientOrderId']) : null];
    }
    public static function operation(array $row, string $kind): ?array
    {
        if (($row['status'] ?? '') !== 'ok') {
            return null;
        } // Pending/failed transfers cannot change confirmed inventory.
        $currency = self::currency($row['currency'] ?? null);
        $amount = Numbers::decimal($row['amount'] ?? null);
        if (bccomp($amount, '0', 24) <= 0) {
            throw new RuntimeException('Transfer amount must be positive.');
        }
        $operation = ['id' => $kind.':'.self::id($row['id'] ?? null),'kind' => $kind,'currency' => $currency,'amount' => $amount,
            'fees' => self::fees($row),'timestamp' => self::timestamp($row),
            'txid' => self::optional($row['txid'] ?? null),'address' => self::optional($row['address'] ?? null),
            'tag' => self::optional($row['tag'] ?? null),'network' => self::optional($row['network'] ?? null)];
        if (isset($row['m3_fee_evidence'])) {
            $evidence = $row['m3_fee_evidence'];
            if (is_array($evidence) && ($evidence['source'] ?? null) === 'bitso_spei_contract') {
                $operation['fee_evidence'] = BitsoSpei::normalizeEvidence($evidence, $operation);
                return $operation;
            }
            if (is_array($evidence) && ($evidence['source'] ?? null) === 'bitso_transfer_contract') {
                $operation['fee_evidence'] = BitsoInternalTransfer::normalizeEvidence($evidence, $operation);
                return $operation;
            }
            if (!is_array($evidence) || ($evidence['source'] ?? null) !== 'bitso_lightning_invoice'
                || !in_array($evidence['payment_hash_source'] ?? 'exchange', ['exchange', 'invoice'], true)
                || isset($evidence['operator_confirmation'])
                || $kind !== 'withdrawal' || $currency !== 'BTC' || $operation['fees'] !== []
                || ($evidence['fee'] ?? null) !== '0' || ($evidence['amount'] ?? null) !== $amount
                || !is_string($evidence['invoice_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $evidence['invoice_sha256'])
                || !is_string($evidence['payment_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $evidence['payment_hash'])
                || $evidence['payment_hash'] !== strtolower($operation['txid'] ?? '') || $operation['network'] !== 'LIGHTNING') {
                throw new RuntimeException('Invalid Lightning fee accounting evidence.');
            }
            $operation['fee_evidence'] = ['source' => $evidence['source'], 'invoice_sha256' => $evidence['invoice_sha256'],
                'payment_hash' => $evidence['payment_hash'], 'amount' => $amount, 'fee' => '0',
                'payment_hash_source' => $evidence['payment_hash_source'] ?? 'exchange'];
        }
        return $operation;
    }
    private static function optional(mixed $v): ?string
    {
        return is_scalar($v) && (string)$v !== '' ? substr((string)$v, 0, 512) : null;
    }
    public static function quote(array $row): array
    {
        $out = ['symbol' => self::symbol($row['symbol'] ?? null),'timestamp' => isset($row['timestamp']) ? (int)$row['timestamp'] : null,'observed_at_ms' => (int)floor(microtime(true) * 1000)];
        foreach (['bid','ask','last'] as $field) {
            $out[$field] = isset($row[$field]) ? Numbers::decimal($row[$field]) : null;
        }
        return $out;
    }
    public static function balances(array $row): array
    {
        $out = [];
        if (!isset($row['total']) || !is_array($row['total'])) {
            throw new RuntimeException('Complete account totals are missing.');
        }
        foreach ($row['total'] as $c => $total) {
            self::currency($c);
            if ($total === null) {
                throw new RuntimeException('Unknown wallet total.');
            }
            $total = Numbers::decimal($total);
            $free = Numbers::decimal($row['free'][$c] ?? null);
            $used = Numbers::decimal($row['used'][$c] ?? null);
            if (bccomp(Numbers::add($free, $used), $total, 24) !== 0) {
                throw new RuntimeException('Wallet free + used differs from total.');
            }
            $out[$c] = ['total' => $total,'free' => $free,'used' => $used];
        }
        ksort($out);
        return $out;
    }
}
