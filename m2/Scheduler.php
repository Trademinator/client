<?php
declare(strict_types=1);
namespace Trademinator\Client\M2;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** RAM-only UTC candle timetable. An expired window is discarded, never replayed. */
final class Scheduler
{
    private const PERIODS = ['1m', '3m', '5m', '15m', '30m', '45m', '1h', '2h', '3h', '4h', '6h', '8h', '12h', '1d', '3d', '7d', '1w', '2w', '1M', '3M', '4M', '1y'];
    private array $markets = [];
    private int $sequence = 0;

    /** Calendar periods use the actual length of the UTC candle containing nowMs. */
    public static function periodMilliseconds(string $period, ?int $nowMs = null): int
    {
        $bounds = self::candleBounds($period, $nowMs ?? (int) floor(microtime(true) * 1000));
        return $bounds['end'] - $bounds['start'];
    }

    public static function checks(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]?$/D', $value)) { $value = (int) $value; }
        if (!is_int($value) || $value < 1 || $value > 59) { throw new InvalidArgumentException('checks must be an integer from 1 through 59.'); }
        return $value;
    }

    public static function offsets(string $period, int $checks, ?int $nowMs = null): array
    {
        return self::durationOffsets(self::periodMilliseconds($period, $nowMs), self::checks($checks));
    }

    private static function durationOffsets(int $duration, int $checks): array
    {
        $offsets = [];
        for ($i = 1; $i < $checks; ++$i) { $offsets[] = intdiv($i * $duration, $checks); }
        $offsets[] = $duration - intdiv($duration, 60);
        return $offsets;
    }

    private static function floorDivide(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);
        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }

    /** Each candle has a stable ordinal, including variable-length calendar candles. */
    private static function candleBounds(string $period, int $nowMs): array
    {
        if (!in_array($period, self::PERIODS, true)) { throw new InvalidArgumentException('Unsupported server candle period: '.$period); }
        $unit = substr($period, -1);
        $count = (int) substr($period, 0, -1);
        if ($unit !== 'M' && $unit !== 'y') {
            $duration = $count * ['m' => 60000, 'h' => 3600000, 'd' => 86400000, 'w' => 604800000][$unit];
            // Week candles start on Monday; day candles retain Unix UTC alignment.
            $anchor = $unit === 'w' ? 345600000 : 0;
            $index = self::floorDivide($nowMs - $anchor, $duration);
            $start = $anchor + $index * $duration;
            return ['start' => $start, 'end' => $start + $duration, 'index' => $index];
        }
        $utc = new DateTimeZone('UTC');
        $date = (new DateTimeImmutable('@'.self::floorDivide($nowMs, 1000)))->setTimezone($utc);
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        if ($unit === 'y') {
            $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $utc);
            $end = $start->modify('+1 year');
            $index = $year;
        } else {
            $index = self::floorDivide($year * 12 + $month - 1, $count);
            $firstMonth = $index * $count;
            $startYear = self::floorDivide($firstMonth, 12);
            $startMonth = $firstMonth - $startYear * 12 + 1;
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $startYear, $startMonth), $utc);
            $end = $start->modify('+'.$count.' months');
        }
        return ['start' => $start->getTimestamp() * 1000, 'end' => $end->getTimestamp() * 1000, 'index' => $index];
    }

    public function configure(string $id, string $period, int $checks, int $nowMs): void
    {
        self::periodMilliseconds($period, $nowMs);
        self::checks($checks);
        $old = $this->markets[$id] ?? null;
        if ($old !== null && $old['period'] === $period && $old['checks'] === $checks) { return; }
        $this->markets[$id] = ['period' => $period, 'checks' => $checks,
            'last_claimed' => $old['last_claimed'] ?? null, 'last_served' => $old['last_served'] ?? 0,
            'high_water_ms' => max($nowMs, $old['high_water_ms'] ?? $nowMs),
            'missed_slots' => 0, 'next_at' => null, 'next_deadline' => null, 'next_rank' => null, 'tolerance_ms' => 0];
        // Restart/activation only considers live windows and future slots.
        $this->advance($id, $nowMs, false);
    }

    public function remove(string $id): void { unset($this->markets[$id]); }
    public function reset(): void { $this->markets = []; $this->sequence = 0; }

    private function advance(string $id, int $nowMs, bool $countMissed = true): void
    {
        $entry = &$this->markets[$id];
        // A backwards wall-clock correction cannot revive already expired or claimed slots.
        $reference = max($nowMs, $entry['high_water_ms']);
        $entry['high_water_ms'] = $reference;
        $current = self::candleBounds($entry['period'], $reference);
        $previous = self::candleBounds($entry['period'], $current['start'] - 1);
        $next = self::candleBounds($entry['period'], $current['end']);
        // The preceding candle's final window remains live through close + tolerance.
        foreach ([$previous, $current, $next] as $candle) {
            $duration = $candle['end'] - $candle['start'];
            $tolerance = intdiv($duration, 60);
            foreach (self::durationOffsets($duration, $entry['checks']) as $index => $offset) {
                $slot = $candle['start'] + $offset;
                $final = $index === $entry['checks'] - 1;
                $deadline = $final ? $candle['end'] + $tolerance : $slot + $tolerance;
                if (($entry['last_claimed'] !== null && $slot <= $entry['last_claimed']) || $deadline < $reference) { continue; }
                $rank = $candle['index'] * $entry['checks'] + $index;
                if ($countMissed && $entry['next_rank'] !== null && $rank > $entry['next_rank']) {
                    $claimedPrevious = $entry['next_at'] === $entry['last_claimed'] ? 1 : 0;
                    $entry['missed_slots'] += max(0, $rank - $entry['next_rank'] - $claimedPrevious);
                }
                $entry['next_at'] = $slot;
                $entry['next_deadline'] = $deadline;
                $entry['next_rank'] = $rank;
                $entry['tolerance_ms'] = $tolerance;
                return;
            }
        }
        throw new \LogicException('Cannot locate the next candle check.');
    }

    public function due(int $nowMs): array
    {
        $due = [];
        foreach (array_keys($this->markets) as $id) {
            $this->advance($id, $nowMs);
            $entry = $this->markets[$id];
            if ($entry['next_at'] <= $nowMs) {
                $due[] = ['market_id' => $id, 'at_ms' => $entry['next_at'],
                    'deadline_ms' => $entry['next_deadline'],
                    'last_served' => $entry['last_served'], 'period' => $entry['period']];
            }
        }
        // Earliest deadline first; equally urgent markets rotate instead of following database order.
        usort($due, static fn(array $a, array $b): int => [$a['deadline_ms'], $a['last_served'], $a['market_id']] <=> [$b['deadline_ms'], $b['last_served'], $b['market_id']]);
        return $due;
    }

    public function claim(string $id, int $nowMs): ?array
    {
        if (!isset($this->markets[$id])) { return null; }
        $this->advance($id, $nowMs);
        $entry = &$this->markets[$id];
        if ($entry['next_at'] > $nowMs) { return null; }
        $slot = ['at_ms' => $entry['next_at'], 'deadline_ms' => $entry['next_deadline']];
        $entry['last_claimed'] = $entry['next_at'];
        $entry['last_served'] = ++$this->sequence;
        $this->advance($id, $nowMs);
        return $slot;
    }

    public function status(string $id, int $nowMs): ?array
    {
        if (!isset($this->markets[$id])) { return null; }
        $this->advance($id, $nowMs);
        $entry = $this->markets[$id];
        return ['period' => $entry['period'], 'checks_per_candle' => $entry['checks'],
            'tolerance_ms' => $entry['tolerance_ms'], 'next_check_at_ms' => $entry['next_at'],
            'next_check_deadline_ms' => $entry['next_deadline'], 'missed_windows' => $entry['missed_slots']];
    }
}
