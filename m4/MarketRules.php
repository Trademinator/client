<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;

/** Public CCXT market metadata, normalized without rounding financial values through PHP floats. */
final class MarketRules
{
    public static function from(array $market, int $precisionMode, array $fees = []): array
    {
        if (($market['spot'] ?? false) !== true || ($market['active'] ?? true) === false) { throw new RuntimeException('An active exact spot market is required.'); }
        $step = static function (mixed $precision) use ($precisionMode): string {
            if ($precision === null) { throw new RuntimeException('Exchange did not publish the market precision.'); }
            if ($precisionMode === \ccxt\TICK_SIZE) { return Decimal::value(LiveFeed::decimal($precision), true); }
            if ($precisionMode === \ccxt\DECIMAL_PLACES && is_int($precision) && $precision >= 0 && $precision <= Decimal::SCALE) {
                return $precision === 0 ? '1' : '0.'.str_repeat('0', $precision - 1).'1';
            }
            throw new RuntimeException('Exchange precision cannot be represented by a fixed paper trading step.');
        };
        $amountStep = $step($market['precision']['amount'] ?? null);
        $side = $market['feeSide'] ?? $fees['feeSide'] ?? 'quote';
        if (!in_array($side, ['base', 'quote', 'get', 'give'], true)) { throw new RuntimeException('Unsupported exchange fee currency rule.'); }
        $result = ['tick_size' => $step($market['precision']['price'] ?? null), 'amount_step' => $amountStep,
            'minimum_amount' => Decimal::max($amountStep, LiveFeed::decimal($market['limits']['amount']['min'] ?? '0')),
            'minimum_cost' => LiveFeed::decimal($market['limits']['cost']['min'] ?? '0'), 'fee_asset' => $side,
            'rules_source' => 'public_ccxt_market_metadata', 'missing_minimum_cost' => !isset($market['limits']['cost']['min'])];
        foreach (['maker', 'taker'] as $kind) {
            $rate = $market[$kind] ?? $fees[$kind] ?? null;
            if ($rate === null || !is_numeric($rate) || bccomp(LiveFeed::decimal($rate), '0', 24) < 0) { throw new RuntimeException('Exchange did not publish a supported '.$kind.' fee.'); }
            $result[$kind.'_fee'] = Decimal::value(LiveFeed::decimal($rate));
        }
        foreach (['maximum_amount' => ['amount','max'], 'maximum_cost' => ['cost','max'], 'minimum_price' => ['price','min'], 'maximum_price' => ['price','max']] as $field => [$kind,$bound]) {
            $value = $market['limits'][$kind][$bound] ?? null;
            if ($value !== null && Decimal::cmp(LiveFeed::decimal($value), '0') > 0) { $result[$field] = Decimal::value(LiveFeed::decimal($value), true); }
        }
        return $result;
    }
}
