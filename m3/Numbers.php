<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

use RuntimeException;
use Trademinator\Client\M1\Ledger;

/** Boundary conversion only. All persisted amounts and arithmetic use BCMath strings. */
final class Numbers
{
    public static function decimal(mixed $value): string
    {
        if (is_float($value)) {
            if (!is_finite($value) || $value < 0) {
                throw new RuntimeException('Invalid CCXT amount.');
            }
            // CCXT PHP returns floats on some endpoints; stringify once, without inventing precision.
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (is_string($value) && preg_match('/^(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/D', $value, $m)) {
            $exponent = (int) $m[3];
            if (abs($exponent) > 40) {
                throw new RuntimeException('CCXT amount exceeds decimal range.');
            }
            $digits = $m[1].($m[2] ?? '');
            $point = strlen($m[1]) + $exponent;
            $value = $point <= 0 ? '0.'.str_repeat('0', -$point).$digits
                : ($point >= strlen($digits) ? $digits.str_repeat('0', $point - strlen($digits)) : substr($digits, 0, $point).'.'.substr($digits, $point));
        }
        return Ledger::decimal($value);
    }
    public static function iso(int $ms): string
    {
        if ($ms < 0) {
            throw new RuntimeException('Invalid exchange timestamp.');
        }
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)).sprintf('.%03d000Z', $ms % 1000);
    }
    public static function ms(string $iso): int
    {
        return (int) round((float) (new \DateTimeImmutable($iso))->format('U.u') * 1000);
    }
    public static function add(string $a, string $b): string
    {
        return self::decimal(bcadd($a, $b, 24));
    }
    public static function sub(string $a, string $b): string
    {
        return self::decimal(bcsub($a, $b, 24));
    }
    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 24) <= 0 ? $a : $b;
    }
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    public static function decode(?string $value): array
    {
        return $value === null ? [] : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
