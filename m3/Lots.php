<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** Acquisition fragments retain original time, unit BUY cost and provenance. */
final class Lots
{
    public static function fees(array $row): array
    {
        $meta = Numbers::decode($row['metadata_json'] ?? '{}');
        return $meta['m3']['fees'] ?? ($row['fee_currency'] === null ? [] : [['currency' => $row['fee_currency'],'cost' => $row['fee_amount']]]);
    }
    public static function fee(array $row, string $currency): string
    {
        $n = '0';
        foreach (self::fees($row) as $fee) {
            if ($fee['currency'] === $currency) {
                $n = Numbers::add($n, $fee['cost']);
            }
        } return $n;
    }
    public static function acquisition(array $row, array $market): ?array
    {
        $m = Numbers::decode($row['metadata_json'])['m3'] ?? null;
        if ($m === null) {
            return null;
        }
        $isOrder = isset($row['exchange_order_id']);
        if ($isOrder && !array_key_exists('fills', $m)) { return null; }
        $type = $isOrder ? 'order' : 'operation';
        $prefix = $type.':'.$row['id'];
        $lots = [];
        $parts = $isOrder ? ($m['fills'] ?? []) : ($m['acquisition_lots'] ?? null);
        if ($parts === null) {
            $parts = [['id' => 'deposit','quantity' => Numbers::sub($row['amount'], self::fee($row, $market['base_currency'])),
                'unit_cost' => $row['price_basis'],'acquired_at' => $row['occurred_at'],
                'origin' => ['kind' => 'deposit','operation_id' => $row['id'],'estimated' => true,'basis' => $m['basis'] ?? null]]];
        }
        foreach ($parts as $part) {
            if ($isOrder) {
                $baseFee = '0';
                $quoteFee = '0';
                foreach ($part['fees'] as $fee) {
                    if ($fee['currency'] === $market['base_currency']) {
                        $baseFee = Numbers::add($baseFee, $fee['cost']);
                    }
                    if ($fee['currency'] === $market['quote_currency']) {
                        $quoteFee = Numbers::add($quoteFee, $fee['cost']);
                    }
                }
                $quantity = Numbers::sub($part['amount'], $baseFee);
                $cost = Numbers::add($part['cost'], $quoteFee);
                if (bccomp($quantity, '0', 24) === 0) {
                    continue;
                }
                $part = ['id' => $part['id'],'quantity' => $quantity,'unit_cost' => Numbers::decimal(bcdiv($cost, $quantity, 24)),
                    'cost_amount' => $cost,'acquired_at' => Numbers::iso($part['timestamp']),
                    'origin' => ['kind' => 'fill','order_id' => $row['id'],'trade_id' => $part['id'],'buy_price' => $part['price'],'quote_currency' => $market['quote_currency']]];
            }
            if (bccomp($part['quantity'], '0', 24) === 0) {
                continue;
            }
            $part['cost_amount'] ??= $part['unit_cost'] === null ? null : Numbers::decimal(bcmul($part['quantity'], $part['unit_cost'], 24));
            $part['source_key'] = $prefix.':'.$part['id'];
            $part['source_type'] = $type;
            $part['source_id'] = $row['id'];
            $part['available_at'] = $isOrder ? $part['acquired_at'] : $row['occurred_at'];
            $part['acquisition_quantity'] = $part['quantity'];
            $part['acquisition_cost'] = $part['cost_amount'];
            $lots[] = $part;
        }
        return $lots;
    }

    /** The acquired quote leg of a real SELL; never a fabricated BUY or deposit. */
    public static function quoteAcquisitions(array $order, array $sourceMarket, array $inventoryMarket): array
    {
        if ($order['side'] !== 'SELL' || $sourceMarket['quote_currency'] !== $inventoryMarket['base_currency']) { return []; }
        $meta = Numbers::decode($order['metadata_json']);
        $fills = $meta['m3']['fills'] ?? [['id' => 'legacy:'.$order['exchange_order_id'], 'timestamp' => Numbers::ms($order['occurred_at']),
            'amount' => $order['filled_quantity'], 'cost' => $order['cost_amount'], 'fees' => self::fees($order)]];
        $lots = [];
        foreach ($fills as $fill) {
            $quantity = $fill['cost'];
            $spent = $fill['amount'];
            foreach ($fill['fees'] as $fee) {
                if ($fee['currency'] === $sourceMarket['quote_currency']) { $quantity = Numbers::sub($quantity, $fee['cost']); }
                if ($fee['currency'] === $sourceMarket['base_currency']) { $spent = Numbers::add($spent, $fee['cost']); }
            }
            if (bccomp($quantity, '0', 24) === 0) { continue; }
            // The fill directly prices the quote in the sold base only. Never
            // reuse that price as a cost in an unrelated valuation currency.
            $cost = $inventoryMarket['quote_currency'] === $sourceMarket['base_currency'] ? $spent : null;
            $id = 'quote:'.$fill['id'];
            $at = Numbers::iso($fill['timestamp']);
            $lots[] = ['id' => $id, 'source_key' => 'order:'.$order['id'].':'.$id,
                'source_type' => 'order', 'source_id' => $order['id'], 'quantity' => $quantity,
                'unit_cost' => $cost === null ? null : Numbers::decimal(bcdiv($cost, $quantity, 24)),
                'cost_amount' => $cost, 'acquisition_quantity' => $quantity, 'acquisition_cost' => $cost,
                'acquired_at' => $at, 'available_at' => $at,
                'origin' => ['kind' => 'quote_fill', 'order_id' => $order['id'], 'trade_id' => $fill['id'],
                    'symbol' => $sourceMarket['symbol'], 'side' => 'SELL',
                    'received_currency' => $sourceMarket['quote_currency'], 'spent_currency' => $sourceMarket['base_currency'],
                    'gross_received' => $fill['cost'], 'net_received' => $quantity, 'spent_amount' => $spent,
                    'fees' => $fill['fees'], 'quote_currency' => $inventoryMarket['quote_currency'],
                    'basis_source' => $cost === null ? 'unknown_conversion' : 'trade_quote_leg']];
        }
        return $lots;
    }
}
