<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;

/** Execution evidence is financial authority; it does not reveal a missing order's original terms. */
final class HistoricalOrder
{
    public static function summarize(string $id, string $symbol, array $observed, array $confirmed, int $now): array
    {
        if ($observed === [] || $confirmed === []) {
            throw new RuntimeException('Historical order requires matching account and order trade evidence.');
        }
        $fills = [];
        $side = null;
        $amount = $cost = '0';
        $first = PHP_INT_MAX;
        $last = 0;
        foreach ($confirmed as $fill) {
            if ($fill['order'] !== $id || $fill['symbol'] !== $symbol || ($side !== null && $side !== $fill['side'])) {
                throw new RuntimeException('Historical order trade identity, market or side differs.');
            }
            if (isset($fills[$fill['id']])) {
                if ($fills[$fill['id']] !== $fill) {
                    throw new RuntimeException('Conflicting exchange trade identity.');
                }
                continue;
            }
            $fills[$fill['id']] = $fill;
            $side = $fill['side'];
            $amount = Numbers::add($amount, $fill['amount']);
            $cost = Numbers::add($cost, $fill['cost']);
            $first = min($first, $fill['timestamp']);
            $last = max($last, $fill['timestamp']);
        }
        foreach ($observed as $fill) {
            if (($fills[$fill['id']] ?? null) !== $fill) {
                throw new RuntimeException('Order trade history does not confirm all observed account trades.');
            }
        }
        // Bitso retains terminal order details for approximately one hour. Recent
        // fills need another snapshot; this path must not hide an in-flight order.
        if ($last > $now - 3600000) {
            throw new RuntimeException('Missing order details with recent fills require another authoritative snapshot.');
        }
        return ['order' => ['id' => $id, 'symbol' => $symbol, 'side' => $side,
            'type' => 'UNKNOWN', 'status' => 'history_only', 'amount' => null,
            'filled' => $amount, 'cost' => $cost, 'price' => Numbers::decimal(bcdiv($cost, $amount, 24)),
            'timestamp' => $first, 'client_order_id' => null,
            'history_evidence' => ['source' => 'bitso:fetch_order_trades', 'order_details' => 'unavailable',
                'terminal_status' => null, 'original_quantity' => null, 'order_type' => null,
                'order_price' => null, 'price_basis' => 'executed_average', 'last_trade_ms' => $last]],
            'trades' => array_values($fills)];
    }
}
