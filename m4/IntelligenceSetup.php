<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M2\ServerApi;

final class IntelligenceSetup
{
    public static function resolve(array $settings, Server $server): array
    {
        $catalog = null;
        foreach ($settings['markets'] as $symbol => &$market) {
            $spec = $market['intelligence'] ?? [];
            $exchange = $spec['model_exchange'] ?? $settings['execution_exchange'];
            $period = $spec['period'] ?? self::period($market['period_ms']);
            $spec += ['period' => $period, 'family' => 'production', 'source' => 'current', 'model_exchange' => $exchange, 'warmup' => 500];
            if (!isset($spec['subscription']) || $spec['subscription'] === 'auto') {
                $catalog ??= ServerApi::validateMarkets($server->request('', 'markets'));
                $matches = array_values(array_filter($catalog, static fn(array $row): bool => $row['is_active'] && $row['exchange'] === $exchange && $row['symbol'] === $symbol && $row['period'] === $period));
                if (count($matches) !== 1) { throw new RuntimeException('Expected one active Server subscription for '.$exchange.' '.$symbol.' '.$period.'; found '.count($matches).'. Check your Server subscriptions.'); }
                $spec['subscription'] = $matches[0]['subscription_id'];
            }
            if (!isset($spec['model_id']) || $spec['model_id'] === 'auto') {
                if ($spec['family'] !== 'production') { throw new RuntimeException('Automatic model selection uses the production model. Explicit shadow models remain available for custom research runs.'); }
                $response = $server->request($spec['subscription'], 'replay/models', query: ['model_exchange' => $exchange, 'period' => $period]);
                if (($response['api_version'] ?? null) !== 1 || ($response['symbol'] ?? null) !== $symbol || !is_array($response['models'] ?? null)) { throw new RuntimeException('Invalid Server model discovery response for '.$symbol.'.'); }
                $matches = array_values(array_filter($response['models'], static fn($row): bool => is_array($row) && ($row['family'] ?? null) === 'production' && ($row['current'] ?? false) === true));
                if (count($matches) !== 1) { throw new RuntimeException('No unique current production model for '.$exchange.' '.$symbol.' '.$period.'. Wait for the Server to publish its intelligence model.'); }
                $spec['model_id'] = $matches[0]['model_id'] ?? null;
            }
            foreach (['subscription', 'model_id'] as $field) {
                if (!is_string($spec[$field]) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $spec[$field])) { throw new RuntimeException('Server returned an invalid '.$field.' for '.$symbol.'.'); }
            }
            $market['intelligence'] = $spec;
        } unset($market);
        return Settings::validate($settings);
    }

    public static function period(int $milliseconds): string
    {
        foreach ([604800000 => 'w', 86400000 => 'd', 3600000 => 'h', 60000 => 'm'] as $unit => $suffix) {
            if ($milliseconds % $unit === 0) { return intdiv($milliseconds, $unit).$suffix; }
        }
        throw new RuntimeException('The paper candle period must be a whole number of minutes.');
    }
}
