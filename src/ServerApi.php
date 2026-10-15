<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use React\Http\Browser;
use React\Socket\Connector;
use RuntimeException;
use function React\Async\await;

final class ServerApi
{
    private Browser $browser;

    public function __construct(private string $baseUrl, private string $token, int $timeoutSeconds)
    {
        Config::validateServerUrl($baseUrl);
        if ($token === '' || preg_match('/[\r\n]/', $token)) {
            throw new RuntimeException('Set TRADEMINATOR_API_KEY to a Client API key.');
        }
        $connector = PHP_OS_FAMILY === 'Windows'
            ? new Connector(['dns' => new SystemResolver(), 'happy_eyeballs' => false])
            : null;
        $this->browser = (new Browser($connector))->withTimeout($timeoutSeconds)->withFollowRedirects(false)
            ->withRejectErrorResponse(false)->withResponseBuffer(1048576);
    }

    public function snapshot(): array
    {
        $markets = $this->get('/api/v1/client/markets');
        if (!is_array($markets['markets'] ?? null) || !array_is_list($markets['markets'])) {
            throw new RuntimeException('Invalid Server markets contract.');
        }
        $risk = self::validateRisk($this->get('/api/v1/client/risk-factor'));
        // Store only the aggregate needed for the probe, never questionnaire answers.
        return ['status' => 'available', 'market_count' => count($markets['markets']),
            'risk_factor' => $risk['risk_factor'], 'algorithm_version' => $risk['algorithm_version'],
            'source' => $risk['source'], 'fetched_at_ms' => (int) floor(microtime(true) * 1000)];
    }

    private function get(string $path): array
    {
        try {
            $response = await($this->browser->get($this->baseUrl.$path,
                ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']));
        } catch (\Throwable) {
            // Remote exceptions can contain URLs/headers; do not expose them.
            throw new RuntimeException('Server request failed or timed out.');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Server returned HTTP '.$response->getStatusCode().'.');
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Server returned invalid JSON.');
        }
        if (!is_array($data) || ($data['api_version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported Server API version.');
        }
        return $data;
    }

    public static function validateRisk(array $data): array
    {
        $risk = $data['risk_factor'] ?? null;
        if (($data['api_version'] ?? null) !== 1 || !is_string($risk)
            || !preg_match('/^(?:0|1)(?:\.[0-9]{1,32})?$/D', $risk)
            || bccomp($risk, '0', 32) < 0 || bccomp($risk, '1', 32) > 0
            || ($data['algorithm_version'] ?? null) !== 'questionnaire-risk-v1'
            || !in_array($data['source'] ?? null, ['default', 'questionnaire'], true)
            || ($data['source'] === 'default' && bccomp($risk, '0.25', 32) !== 0)) {
            throw new RuntimeException('Invalid Server risk-factor contract.');
        }
        return $data;
    }
}
