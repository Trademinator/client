<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\Paths;
use Trademinator\Client\M2\ServerApi;

/** Builds reproducible paper accounts from authenticated subscriptions and public rules. */
final class AutomaticSetup
{
    public static function discover(Config $config, Server $server, ?callable $feedFactory = null, ?callable $keepRunning = null): array
    {
        $catalog = ServerApi::validateMarkets($server->request('', 'markets'));
        $groups = []; $warnings = []; $plans = []; $feeds = []; $risk = null; $created = [];
        $feedFactory ??= static fn(string $exchange): object => new LiveFeed($exchange);
        foreach ($catalog as $row) {
            if (!$row['is_active']) { continue; }
            if ($row['period'] === null || !preg_match('/^[A-Z0-9]{1,16}\/[A-Z0-9]{1,16}$/D', $row['symbol'])) {
                $warnings[] = ['exchange' => $row['exchange'], 'symbol' => $row['symbol'], 'reason' => 'Server has not selected a supported spot candle period.']; continue;
            }
            $groups[$row['exchange']][] = $row;
        }
        ksort($groups);
        $store = new Store($config->dataDir.'/paper');
        try {
            foreach ($groups as $exchange => $subscriptions) {
                if ($keepRunning !== null && !$keepRunning()) { throw new RuntimeException('Automatic paper setup stopped.'); }
                try {
                    $identities = array_map(static fn(array $row): string => $row['subscription_id'].'|'.$row['symbol'].'|'.$row['period'], $subscriptions);
                    sort($identities, SORT_STRING);
                    $run = 'auto-'.$exchange.'-'.substr(hash('sha256', implode("\n", $identities)), 0, 16);
                    if ($store->exists($run)) {
                        // Fee, funding and model changes cannot reset an existing paper account.
                        $settings = $store->run($run)['config'];
                    } else {
                        $risk ??= ServerApi::validateRisk($server->request('', 'risk-factor'));
                        $feeds[$exchange] ??= $feedFactory($exchange);
                        $markets = []; $balances = []; $quotes = [];
                        foreach ($subscriptions as $subscription) {
                            $symbol = $subscription['symbol']; $period = $subscription['period'];
                            if (isset($markets[$symbol])) { throw new RuntimeException('Duplicate active subscriptions for '.$exchange.' '.$symbol.'. Review subscriptions on the Server.'); }
                            $periodMs = Application::periodMs($period);
                            $response = $server->request($subscription['subscription_id'], 'market'); $detail = $response['market'] ?? [];
                            if (($response['api_version'] ?? null) !== 1 || ($detail['subscription_id'] ?? null) !== $subscription['subscription_id']
                                || ($detail['exchange']['class'] ?? null) !== $exchange || ($detail['symbol'] ?? null) !== $symbol
                                || ($detail['period'] ?? null) !== $period || ($detail['active'] ?? false) !== true) {
                                throw new RuntimeException('Server market changed during setup; restart to refresh subscriptions.');
                            }
                            $initial = Decimal::value($detail['settings']['paper_initial_quote'] ?? null, true);
                            [$base, $quote] = explode('/', $symbol);
                            // One shared budget per quote currency, never one budget per pair.
                            $balances[$quote] = Decimal::max($balances[$quote] ?? '0', $initial);
                            $balances[$base] ??= '0'; $quotes[$quote] = ($quotes[$quote] ?? 0) + 1;
                            $markets[$symbol] = [...$feeds[$exchange]->rules($symbol), 'period_ms' => $periodMs,
                                'evaluation_ms' => max(1000, intdiv($periodMs, $config->checksPerCandle)), 'risk_factor' => $risk['risk_factor'],
                                'minimum_profit' => '0', 'volume_participation' => '0.01',
                                'intelligence' => ['subscription' => $subscription['subscription_id'], 'period' => $period,
                                    'family' => 'production', 'source' => 'current', 'model_exchange' => $exchange, 'warmup' => 500]];
                        }
                        ksort($quotes); arsort($quotes, SORT_NUMERIC); ksort($markets); ksort($balances);
                        $settings = IntelligenceSetup::resolve(['account' => 'paper-'.$exchange, 'source_exchange' => $exchange,
                            'execution_exchange' => $exchange, 'reporting_currency' => array_key_first($quotes), 'balances' => $balances,
                            'opening_lots' => [], 'policy' => 'two_order', 'markets' => $markets], $server);
                        if ($keepRunning !== null && !$keepRunning()) { throw new RuntimeException('Automatic paper setup stopped.'); }
                        $store->ensure($run, $settings);
                        $created[] = $run;
                    }
                    $plans[] = ['enabled' => true, 'run_id' => $run, ...$settings];
                } catch (\Throwable $error) {
                    $warnings[] = ['exchange' => $exchange, 'reason' => $error->getMessage()];
                }
            }
        } finally { foreach ($feeds as $feed) { $feed->close(); } }
        if ($keepRunning !== null && !$keepRunning()) { throw new RuntimeException('Automatic paper setup stopped.'); }
        if ($groups === [] && $warnings === []) { $warnings[] = ['reason' => 'No active Server subscriptions. Activate your markets on the Server; UUIDs are discovered automatically.']; }
        Paths::atomicJson($config->dataDir.'/paper/automatic-runs.json', ['runs' => array_column($plans, 'run_id')]);
        return ['plans' => $plans, 'warnings' => $warnings, 'created' => $created];
    }
}
