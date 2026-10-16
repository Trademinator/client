<?php
declare(strict_types=1);

namespace Trademinator\Client\M2;

use RuntimeException;

/** Authenticated Server contract; injectable transport enables network-free tests. */
final class ServerApi
{
    public function __construct(private readonly object $transport) {}

    public function markets(string $requestId): void { $this->transport->start($requestId, '/api/v1/client/markets'); }
    public function risk(string $requestId): void { $this->transport->start($requestId, '/api/v1/client/risk-factor'); }
    public function market(string $requestId, string $subscriptionId): void
    {
        if (!self::identifier($subscriptionId)) { throw new RuntimeException('Invalid subscription identifier.'); }
        $this->transport->start($requestId, '/api/v1/client/markets/'.$subscriptionId);
    }
    public function pending(): int { return $this->transport->pending(); }
    public function cancelAll(): void { $this->transport->cancelAll(); }

    public function poll(?int $nowMs = null): array
    {
        $nowMs ??= (int) floor(microtime(true) * 1000);
        $completed = [];
        foreach ($this->transport->poll() as $response) {
            $status = (int) ($response['status'] ?? 0);
            $row = ['request_id' => $response['request_id'], 'status' => $status,
                'data' => null, 'error' => null, 'error_code' => null, 'retry_after_ms' => 0];
            if (($response['error'] ?? null) !== null) {
                $row['error'] = in_array($response['error'], ['response_too_large', 'response_headers_too_large'], true)
                    ? $response['error'] : 'transport_error';
            } elseif ($status !== 200) {
                $row['error'] = 'http_'.$status;
                $data = self::decode((string) ($response['body'] ?? ''));
                $code = is_array($data) ? ($data['error']['code'] ?? null) : null;
                if (in_array($code, ['account_unavailable', 'unauthenticated', 'client_api_disabled',
                    'authentication_rate_limited', 'rate_limited', 'subscription_not_found', 'forbidden'], true)) {
                    $row['error_code'] = $code;
                }
                if ($status === 429) {
                    $row['retry_after_ms'] = self::retryAfterMs($response['headers']['retry-after'] ?? null, $nowMs);
                }
            } else {
                $data = self::decode((string) ($response['body'] ?? ''));
                if ($data === null) { $row['error'] = 'invalid_json'; }
                elseif (($data['api_version'] ?? null) !== 1) { $row['error'] = 'unsupported_api_version'; }
                else { $row['data'] = $data; }
            }
            $completed[] = $row;
        }
        return $completed;
    }

    private static function decode(string $body): ?array
    {
        if (strlen($body) > 1048576) { return null; }
        try {
            $object = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) { return null; }
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) { return null; }
    }

    public static function retryAfterMs(mixed $header, int $nowMs): int
    {
        if (!is_string($header) || strlen($header) > 128) { return 60000; }
        $header = trim($header);
        if (preg_match('/^[0-9]{1,9}$/D', $header)) { return max(1000, min(86400000, (int) $header * 1000)); }
        // Retry-After dates are HTTP dates, not arbitrary relative-time expressions.
        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $header, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('D, d M Y H:i:s \G\M\T') !== $header) { return 60000; }
        return max(1000, min(86400000, $date->getTimestamp() * 1000 - $nowMs));
    }

    public static function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) === 1;
    }

    public static function validateMarkets(array $data): array
    {
        if (($data['api_version'] ?? null) !== 1 || !is_array($data['markets'] ?? null)
            || !array_is_list($data['markets']) || count($data['markets']) > 10000) {
            throw new RuntimeException('Invalid Server markets contract.');
        }
        $result = []; $seen = [];
        foreach ($data['markets'] as $market) {
            if (!is_array($market) || !self::identifier($market['subscription_id'] ?? null)
                || isset($seen[$market['subscription_id']]) || !is_bool($market['active'] ?? null)
                || !is_array($market['exchange'] ?? null)
                || !is_string($market['exchange']['class'] ?? null)
                || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $market['exchange']['class']) !== 1
                || !is_string($market['exchange']['name'] ?? null) || strlen($market['exchange']['name']) > 200
                || !is_string($market['symbol'] ?? null) || strlen($market['symbol']) > 100
                || preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._:-]+$/D', $market['symbol']) !== 1
                || !array_key_exists('period', $market)
                || ($market['period'] !== null && (!is_string($market['period']) || preg_match('/^[1-9][0-9]{0,5}[smhdwMy]$/D', $market['period']) !== 1))
                || (isset($market['latest_signal_id']) && !self::identifier($market['latest_signal_id']))) {
                throw new RuntimeException('Invalid Server markets contract.');
            }
            $seen[$market['subscription_id']] = true;
            $result[] = ['subscription_id' => $market['subscription_id'], 'exchange' => $market['exchange']['class'],
                'exchange_name' => $market['exchange']['name'], 'symbol' => $market['symbol'], 'period' => $market['period'],
                'is_active' => $market['active'], 'latest_signal_id' => $market['latest_signal_id'] ?? null];
        }
        return $result;
    }

    public static function validateRisk(array $data): array
    {
        $risk = $data['risk_factor'] ?? null;
        if (($data['api_version'] ?? null) !== 1 || !is_string($risk)
            || preg_match('/^(?:0|1)(?:\.[0-9]{1,32})?$/D', $risk) !== 1
            || self::compareDecimal($risk, '0.01') < 0 || self::compareDecimal($risk, '1') > 0
            || ($data['algorithm_version'] ?? null) !== 'questionnaire-risk-v1'
            || !in_array($data['source'] ?? null, ['default', 'questionnaire'], true)
            || ($data['source'] === 'default' && self::compareDecimal($risk, '0.25') !== 0)) {
            throw new RuntimeException('Invalid Server risk-factor contract.');
        }
        return ['risk_factor' => $risk, 'algorithm_version' => $data['algorithm_version'], 'source' => $data['source']];
    }

    private static function compareDecimal(string $left, string $right): int
    {
        $leftParts = explode('.', $left, 2); $rightParts = explode('.', $right, 2);
        return strcmp($leftParts[0].str_pad($leftParts[1] ?? '', 32, '0'), $rightParts[0].str_pad($rightParts[1] ?? '', 32, '0')) <=> 0;
    }

    public function __debugInfo(): array { return ['pending' => $this->pending()]; }
    public function __serialize(): array { throw new RuntimeException('Server API cannot be serialized.'); }
}
