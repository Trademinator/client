<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use InvalidArgumentException;
use function Trademinator\BcMath\bcconv;

final class Decimal
{
    public static function nonnegative(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || (is_string($value) && strlen($value) > 100)) {
            throw new InvalidArgumentException('Expected a bounded decimal value.');
        }
        if (is_string($value)) {
            if (!preg_match('/^\+?\d+(?:\.\d+)?(?:[eE]([+-]?\d{1,3}))?$/D', $value, $parts)
                || (isset($parts[1]) && abs((int) $parts[1]) > 64)) {
                throw new InvalidArgumentException('Decimal syntax or exponent exceeds the probe limits.');
            }
        }
        $number = bcconv($value);
        if (!preg_match('/^\+?\d+(?:\.\d+)?$/D', $number) || strlen($number) > 100) {
            throw new InvalidArgumentException('Expected a nonnegative decimal.');
        }
        return ltrim($number, '+');
    }

    public static function positive(mixed $value): string
    {
        $number = self::nonnegative($value);
        if (!preg_match('/[1-9]/', $number)) {
            throw new InvalidArgumentException('Expected a positive decimal.');
        }
        return $number;
    }
}
