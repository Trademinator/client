<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use InvalidArgumentException;
use Trademinator\Client\M1\Ledger;

final class Decimal
{
    public const SCALE = 24;
    public static function value(mixed $v, bool $positive = false): string { return Ledger::decimal($v, $positive); }
    public static function add(string $a, string $b): string { return bcadd($a,$b,self::SCALE); }
    public static function sub(string $a, string $b): string { return bcsub($a,$b,self::SCALE); }
    public static function mul(string $a, string $b): string { return bcmul($a,$b,self::SCALE); }
    public static function div(string $a, string $b): string { return bcdiv($a,$b,self::SCALE); }
    public static function cmp(string $a, string $b): int { return bccomp($a,$b,self::SCALE); }
    public static function min(string ...$values): string { $n=array_shift($values); foreach($values as $v) { if(self::cmp($v,$n)<0) { $n=$v; } } return $n; }
    public static function max(string ...$values): string { $n=array_shift($values); foreach($values as $v) { if(self::cmp($v,$n)>0) { $n=$v; } } return $n; }
    public static function step(string $v, string $step, bool $up = false): string
    {
        if(self::cmp($v,'0')<0 || self::cmp($step,'0')<=0) { throw new InvalidArgumentException('Invalid rounding input.'); }
        $n=bcdiv($v,$step,0); $rounded=self::mul($n,$step);
        return $up && self::cmp($rounded,$v)<0 ? self::add($rounded,$step) : $rounded;
    }
}
