<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use InvalidArgumentException;

/** Daemon switches wrap the same immutable settings used by standalone replay. */
final class PaperConfig
{
    public static function validate(?array $input): array
    {
        if ($input === null) { return ['enabled' => false]; }
        $enabled = $input['enabled'] ?? true;
        if (array_key_exists('enabled', $input) && !is_bool($input['enabled'])) {
            throw new InvalidArgumentException('paper_trading.enabled must be true or false.');
        }
        $allowed = ['enabled', 'run_id', 'account', 'source_exchange', 'execution_exchange', 'reporting_currency',
            'balances', 'opening_lots', 'policy', 'submission_delay_ms', 'cancellation_delay_ms', 'stale_ms', 'fx_max_age_ms', 'markets'];
        if (array_diff(array_keys($input), $allowed)) { throw new InvalidArgumentException('Unknown paper_trading field.'); }
        if (!$enabled && array_diff(array_keys($input), ['enabled']) === []) { return ['enabled' => false]; }
        if (!isset($input['markets'])) {
            if (array_diff(array_keys($input), ['enabled'])) { throw new InvalidArgumentException('Automatic paper setup needs only enabled. For custom runs, supply the complete markets configuration.'); }
            return ['enabled' => $enabled];
        }
        if (!is_string($input['run_id'] ?? null)) { throw new InvalidArgumentException('paper_trading.run_id is required.'); }
        Store::id($input['run_id']);
        $settings = self::settings($input);
        if ($enabled) { self::live($settings); }
        return ['enabled' => $enabled, 'run_id' => $input['run_id'], ...$settings];
    }

    public static function settings(array $section): array
    {
        unset($section['enabled'], $section['run_id']);
        if (!$section) { throw new InvalidArgumentException('Supply a paper_trading section or --paper-config=FILE.'); }
        return Settings::validate($section);
    }

    public static function live(array $settings): void
    {
        foreach (['source_exchange', 'execution_exchange'] as $field) {
            if (!preg_match('/^[a-z][a-z0-9]{1,40}$/D', $settings[$field])) { throw new InvalidArgumentException('Invalid paper '.$field.'. Use a lowercase CCXT exchange ID.'); }
        }
        foreach ($settings['markets'] as $symbol => $market) {
            $spec = $market['intelligence'] ?? null;
            if ($spec === null) { $spec = ['period' => IntelligenceSetup::period($market['period_ms'])]; }
            if (!is_array($spec)) { throw new InvalidArgumentException('paper_trading.markets.'.$symbol.'.intelligence must be an object.'); }
            foreach (['subscription', 'model_id'] as $field) {
                if (!isset($spec[$field]) || $spec[$field] === 'auto') { continue; }
                if (!is_string($spec[$field] ?? null) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $spec[$field])) {
                    throw new InvalidArgumentException('Set paper_trading.markets.'.$symbol.'.intelligence.'.$field.' to a real UUID before enabling paper trading.');
                }
            }
            if (!in_array($spec['family'] ?? 'production', ['production', 'shadow'], true)
                || !in_array($spec['source'] ?? 'current', ['current', 'historical'], true)) {
                throw new InvalidArgumentException('Invalid paper intelligence family or source.');
            }
            if (isset($spec['model_exchange']) && (!is_string($spec['model_exchange']) || !preg_match('/^[a-z][a-z0-9]{1,40}$/D', $spec['model_exchange']))) {
                throw new InvalidArgumentException('Invalid intelligence.model_exchange.');
            }
            $warmup = $spec['warmup'] ?? 500;
            if (!is_int($warmup) || $warmup < 1 || $warmup > 50000) { throw new InvalidArgumentException('intelligence.warmup must be 1 through 50000.'); }
        }
    }

    public static function fingerprint(array $values): string
    {
        $sort = static function (array $value) use (&$sort): array {
            if (!array_is_list($value)) { ksort($value); }
            foreach ($value as &$item) { if (is_array($item)) { $item = $sort($item); } } unset($item);
            return $value;
        };
        return hash('sha256', json_encode($sort($values), JSON_THROW_ON_ERROR));
    }
}
