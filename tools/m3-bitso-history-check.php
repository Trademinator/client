<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M3\{BitsoHistoryProbe, CredentialError, Settings};

$options = getopt('', ['config:', 'account:', 'from:', 'to:', 'max-pages:', 'help']);
$valid = true;
foreach (['config', 'account', 'from', 'to'] as $key) { $valid = $valid && is_string($options[$key] ?? null); }
if (isset($options['help']) || !$valid) {
    fwrite(isset($options['help']) ? STDOUT : STDERR,
        "Usage: php tools/m3-bitso-history-check.php --config=FILE --account=BITSO_ACCOUNT_ID --from=ISO8601 --to=ISO8601 [--max-pages=N]\n"
        ."Use explicit timezones, e.g. --from=2022-01-01T00:00:00Z --to=2022-01-02T00:00:00Z.\n"
        ."Read only: inspects trades, deposits, withdrawals and RFQ conversions within [from,to).\n"
        ."Outputs a sanitized JSON timeline; progress goes to stderr. Does not open or change the local database.\n");
    exit(isset($options['help']) ? 0 : 2);
}

$client = null;
$stage = 'window';
$report = ['tool' => 'm3-bitso-history-check', 'read_only' => true];
try {
    $from = BitsoHistoryProbe::time($options['from']);
    $to = BitsoHistoryProbe::time($options['to']);
    if ($from >= $to) { throw new RuntimeException('invalid_window'); }
    $stage = 'configuration';
    $config = Config::load($options['config']);
    $accounts = array_values(array_filter($config->exchange['accounts'],
        static fn(array $account): bool => $account['account_id'] === $options['account']));
    if (count($accounts) !== 1) { throw new RuntimeException('Account not found.'); }
    $account = $accounts[0];
    $maxPages = $config->exchange['history_max_pages'];
    if (isset($options['max-pages'])) {
        if (!is_string($options['max-pages']) || !preg_match('/^[1-9][0-9]{0,4}$/D', $options['max-pages'])
            || (int)$options['max-pages'] > 10000) { throw new RuntimeException('Invalid page budget.'); }
        $maxPages = (int)$options['max-pages'];
    }
    $report['account_id'] = $account['account_id'];
    $stage = 'credentials';
    $client = new BitsoHistoryProbe(['enableRateLimit' => true, 'timeout' => $config->exchange['timeout_ms'],
        'number' => 'strval', 'options' => ['defaultType' => 'spot']] + Settings::credentials($account));
    $stage = 'history';
    $lastSource = null; $lastProgress = 0.0;
    $progress = static function(string $source, int $page, int $rows) use (&$lastSource, &$lastProgress): void {
        $now = microtime(true);
        if ($source !== $lastSource || $now - $lastProgress >= 30) {
            fwrite(STDERR, "Checking $source: page $page; $rows records scanned.\n");
            $lastSource = $source; $lastProgress = $now;
        }
    };
    $report += $client->inspect($from, $to, $account['history_since_ms'], $maxPages, 100, $progress);
    $exit = $report['status'] === 'window_checked' ? 0 : 1;
} catch (Throwable $error) {
    $report += ['status' => 'needs_review', 'stage' => $stage, 'error' => $error instanceof CredentialError ? $error->diagnostic
        : ($stage === 'configuration'
            ? ['reason' => 'configuration_error', 'detail' => 'Check the config file, Bitso account_id and optional page budget (1 through 10000).']
            : BitsoHistoryProbe::diagnostic($error))];
    $exit = 1;
} finally { $client?->close(); }

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit($exit);
