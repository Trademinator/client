<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use RuntimeException;
use Trademinator\BcMath\PrecisionContext;
use Trademinator\BcMath\PrecisionPolicy;
use Trademinator\Indicators\Indicators;
use function Trademinator\BcMath\bcconv;
use function Trademinator\BcMath\bcmin;
use function Trademinator\Indicators\normalize_ticker;

final class MathProbe
{
    public static function run(): array
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $price = bcdiv((string) $i, '100000000', 24);
            $rows[] = [1700000000000 + $i * 60000, $price, $price, $price, $price, '10'];
        }
        normalize_ticker($rows, true, 'milliseconds');
        $indicators = new Indicators(new PrecisionContext(24, new PrecisionPolicy()));
        $ema = $indicators->ema($rows, 3);
        $sma = $indicators->sma($rows, 3);
        $last = end($rows);
        // Hand-computable sequences protect against float collapse/truncation.
        if (bccomp($last[$ema], '0.00000004', 24) !== 0 || bccomp($last[$sma], '0.00000004', 24) !== 0
            || bccomp(bcconv('1e-20'), '0.00000000000000000001', 24) !== 0) {
            throw new RuntimeException('Shared decimal/indicator probe failed.');
        }
        // Fixture R is a Server value. There is no Client questionnaire calculation.
        $riskQuantity = bcmul('5000', '0.25', 24);
        $buyQuantity = bcmin('8000', $riskQuantity);
        if (bccomp((string) $buyQuantity, '1250', 24) !== 0) {
            throw new RuntimeException('Shared minimum/sizing fixture failed.');
        }
        return ['status' => 'passed', 'scale' => 24, 'tiny_ema' => $last[$ema],
            'tiny_sma' => $last[$sma], 'risk_quantity_fixture' => $riskQuantity,
            'buy_quantity_fixture' => $buyQuantity];
    }
}
