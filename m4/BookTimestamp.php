<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

/**
 * Normalize only bounded future-dated NDAX websocket and REST book timestamps.
 *
 * A local receipt event cannot predate its source timestamp in the paper Engine.
 * Store the unmodified exchange timestamp in the event payload for audit/replay,
 * while using local receipt time for future-dated NDAX observations up to 100 ms.
 * This is not a timestamp correction for other exchanges or older NDAX books.
 * Every other observation continues through the Engine's strict validation.
 */
final class BookTimestamp
{
    public const MAX_FUTURE_JITTER_MS = 100;

    /** @return array<string,mixed> Fields to include in a durable book event. */
    public static function normalize(string $exchange, array $book, int $localTimeMs): array
    {
        $sourceTime = $book['timestamp'] ?? $localTimeMs;
        if ($exchange !== 'ndax' || !in_array($book['transport'] ?? null, ['websocket', 'rest'], true)
            || !is_int($sourceTime) || $sourceTime <= $localTimeMs
            || $sourceTime - $localTimeMs > self::MAX_FUTURE_JITTER_MS) {
            return ['observed_at_ms' => $sourceTime];
        }
        return [
            'observed_at_ms' => $localTimeMs,
            'source_observed_at_ms' => $sourceTime,
            'timestamp_adjustment_ms' => $sourceTime - $localTimeMs,
        ];
    }
}
