<?php
declare(strict_types=1);

namespace Trademinator\Client\M2;

use RuntimeException;

/** Validates read-only Server evidence for local evaluation; it never authorizes a trade. */
final class Intelligence
{
    public static function validate(
        array $data,
        string $subscriptionId,
        ?string $period,
        int $nowMs,
        int $clockSkewMs = 5000,
        ?string $exchange = null,
        ?string $symbol = null,
    ): array {
        $market = $data['market'] ?? null;
        if (($data['api_version'] ?? null) !== 1 || !is_array($market)
            || !ServerApi::identifier($market['subscription_id'] ?? null)
            || $market['subscription_id'] !== $subscriptionId || !is_bool($market['active'] ?? null)
            || !is_array($market['exchange'] ?? null)
            || !self::text($market['exchange']['class'] ?? null, 64)
            || preg_match('/^[A-Za-z0-9_-]+$/D', $market['exchange']['class']) !== 1
            || !self::text($market['symbol'] ?? null, 100)
            || preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._:-]+$/D', $market['symbol']) !== 1
            || ($exchange !== null && $market['exchange']['class'] !== $exchange)
            || ($symbol !== null && $market['symbol'] !== $symbol)
            || !array_key_exists('period', $market)
            || ($market['period'] !== null && !self::period($market['period']))
            || !array_key_exists('signal', $market)
            || ($market['signal'] !== null && !is_array($market['signal']))
            || $nowMs <= 0 || $clockSkewMs < 0 || $clockSkewMs > 60000) {
            throw new RuntimeException('Invalid Server market intelligence contract.');
        }
        $signal = null;
        if (is_array($market['signal'])) {
            $source = $market['signal'];
            if (!ServerApi::identifier($source['id'] ?? null) || !self::period($source['period'] ?? null)
                || !array_key_exists('model_id', $source)
                || ($source['model_id'] !== null && !ServerApi::identifier($source['model_id']))
                || !in_array($source['action'] ?? null, ['buy', 'sell', 'hold'], true)
                || !self::text($source['reason'] ?? null, 200)
                || !self::text($source['evidence_status'] ?? null, 100)
                || !is_bool($source['is_current_model'] ?? null) || !is_bool($source['is_fresh'] ?? null)
                || !array_key_exists('expires_at_ms', $source)
                || ($source['expires_at_ms'] !== null && (!is_int($source['expires_at_ms']) || $source['expires_at_ms'] <= 0))
                || !array_key_exists('decision_at_ms', $source)
                || ($source['decision_at_ms'] !== null && (!is_int($source['decision_at_ms']) || $source['decision_at_ms'] <= 0))
                || !is_int($source['recorded_at_ms'] ?? null) || $source['recorded_at_ms'] <= 0
                || $source['recorded_at_ms'] > $nowMs + $clockSkewMs
                || ($source['decision_at_ms'] !== null && ($source['decision_at_ms'] > $nowMs + $clockSkewMs
                    || $source['recorded_at_ms'] < $source['decision_at_ms']))
                || ($source['expires_at_ms'] !== null && $source['decision_at_ms'] !== null && $source['expires_at_ms'] < $source['decision_at_ms'])
                || ($source['is_current_model'] && $source['model_id'] === null)
                || ($source['is_fresh'] && ($source['expires_at_ms'] === null || $source['decision_at_ms'] === null
                    || $source['expires_at_ms'] <= $source['decision_at_ms']))
                || !array_key_exists('confidence', $source)
                || ($source['confidence'] !== null && ((!is_float($source['confidence']) && !is_int($source['confidence']))
                    || !is_finite((float) $source['confidence']) || $source['confidence'] < 0 || $source['confidence'] > 1))) {
                throw new RuntimeException('Invalid Server intelligence signal.');
            }
            $evidence = match ($source['reason']) {
                'supported' => 'supported', 'degraded_action_only' => 'degraded', default => 'abstaining',
            };
            if ($source['evidence_status'] !== $evidence
                || ($evidence === 'abstaining' && $source['action'] !== 'hold')) {
                throw new RuntimeException('Inconsistent Server signal semantics.');
            }
            $signal = array_intersect_key($source, array_flip(['id', 'period', 'model_id', 'action', 'reason', 'evidence_status',
                'is_current_model', 'is_fresh', 'expires_at_ms', 'action_meaning', 'confidence', 'decision_at_ms',
                'recorded_at_ms', 'reference_price', 'reference_price_source', 'prediction_input_basis', 'scoring']));
        }
        // Keep only read-only evidence and identity; legacy Server sizing/settings are not Client instructions.
        $detail = ['api_version' => 1, 'market' => ['subscription_id' => $subscriptionId, 'active' => $market['active'],
            'exchange' => ['class' => $market['exchange']['class']], 'symbol' => $market['symbol'],
            'period' => $market['period'], 'signal' => $signal]];
        $reason = null;
        if (!$market['active']) { $reason = 'inactive_subscription'; }
        elseif ($market['period'] !== $period) { $reason = 'candle_period_changed'; }
        elseif ($signal === null) { $reason = 'signal_unavailable'; }
        elseif ($period === null || $signal['period'] !== $period) { $reason = 'signal_period_mismatch'; }
        elseif (!$signal['is_current_model']) { $reason = 'signal_superseded'; }
        elseif (!$signal['is_fresh']) { $reason = 'signal_stale'; }
        elseif ($signal['expires_at_ms'] === null || $signal['expires_at_ms'] <= $nowMs) { $reason = 'intelligence_expired'; }
        elseif (!in_array($signal['reason'], ['supported', 'degraded_action_only'], true)) { $reason = $signal['reason']; }
        elseif ($signal['action'] === 'hold') { $reason = 'server_hold'; }
        $eligible = $reason === null;
        return ['eligible' => $eligible, 'trade_authorized' => false, 'reason' => $reason ?? $signal['reason'],
            'action' => $eligible ? $signal['action'] : 'hold', 'signal' => $signal, 'detail' => $detail];
    }

    private static function period(mixed $value): bool
    {
        return self::text($value, 16) && preg_match('/^[1-9][0-9]{0,5}[smhdwMy]$/D', $value) === 1;
    }

    private static function text(mixed $value, int $limit): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $limit && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
