<?php

declare(strict_types=1);

namespace Trademinator\Client\M1;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** One bounded, credential-safe event shared by local log and console sinks. */
final class EventRecord
{
    private static array $secrets = [];

    public static function hideSecret(string $secret): void
    {
        if ($secret !== '') { self::$secrets[$secret] = '[REDACTED]'; }
    }

    public static function create(string $level, string $event, array $context, string $runId, string $message = ''): array
    {
        if (!in_array($level, ['debug', 'info', 'warning', 'error'], true)) { throw new RuntimeException('Unknown event level.'); }
        $nodes = 128;
        $bytes = 16384;
        return [
            'schema_version' => 1,
            'at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'event' => self::safeText($event, 128),
            'message' => self::safeText($message !== '' ? $message : ucfirst(str_replace('_', ' ', $event))),
            'run_id' => self::safeText($runId, 128),
            'context' => self::value($context, 0, $nodes, $bytes),
        ];
    }

    /** Printable text only: literal escapes cannot move a cursor or add a line. */
    public static function safeText(string $value, int $limit = 2048): string
    {
        if (self::$secrets !== []) { $value = strtr($value, self::$secrets); }
        $value = json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $value = preg_replace_callback('/[\x{0000}-\x{001f}\x{007f}-\x{009f}\p{Cf}\x{2028}\x{2029}]/u',
            static fn(array $match): string => substr(json_encode($match[0], JSON_THROW_ON_ERROR), 1, -1), $value) ?? '[invalid text]';
        if (strlen($value) > $limit) {
            $value = substr($value, 0, max(0, $limit - 14));
            // The input was normalized above, so at most three trailing bytes
            // need removal when truncation splits a UTF-8 character.
            while ($value !== '' && !preg_match('//u', $value)) { $value = substr($value, 0, -1); }
            $value .= '...[truncated]';
        }
        return $value;
    }

    private static function value(mixed $value, int $depth, int &$nodes, int &$bytes): mixed
    {
        if ($nodes-- <= 0 || $bytes <= 0) { return '[truncated]'; }
        if (is_array($value)) {
            if ($depth >= 6) { return '[maximum depth]'; }
            $result = [];
            foreach ($value as $key => $item) {
                if ($nodes <= 0 || $bytes <= 0) { $result['__truncated__'] = true; break; }
                $safeKey = is_int($key) ? $key : self::safeText($key, 128);
                $bytes -= is_string($safeKey) ? strlen($safeKey) : 0;
                if (is_string($key) && self::sensitiveKey($key)) {
                    $result[$safeKey] = '[REDACTED]';
                    $nodes--;
                    $bytes -= 10;
                } else {
                    $result[$safeKey] = self::value($item, $depth + 1, $nodes, $bytes);
                }
            }
            return $result;
        }
        if (is_string($value)) {
            $text = self::safeText($value, min(2048, max(14, $bytes)));
            $bytes -= strlen($text);
            return $text;
        }
        if (is_object($value)) { return '[object omitted]'; }
        if (is_resource($value) || gettype($value) === 'resource (closed)') { return '[resource omitted]'; }
        if (is_float($value) && !is_finite($value)) { return '[non-finite number]'; }
        return $value;
    }

    private static function sensitiveKey(string $key): bool
    {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? '');
        return $key === 'key' || (bool) preg_match('/password|passwd|passphrase|secret|token|authorization|cookie|credential|apikey|accesskey|privatekey|mnemonic|seedphrase|signature/', $key);
    }
}
