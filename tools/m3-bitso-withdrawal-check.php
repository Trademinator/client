<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M3\{BitsoReadClient, CredentialError, Settings, SyncDiagnostics, WithdrawalEvidenceError};

use function React\Async\await;

$options = getopt('', ['config:', 'account:', 'help']);
if (isset($options['help']) || !is_string($options['config'] ?? null) || !is_string($options['account'] ?? null)) {
    fwrite(isset($options['help']) ? STDOUT : STDERR,
        "Usage: php tools/m3-bitso-withdrawal-check.php --config=FILE --account=BITSO_ACCOUNT_ID\n"
        ."Read only: checks the most recent 100 withdrawals, including individual lookups when fee evidence is missing.\n"
        ."Does not reconcile the account, scan market trades, or write the local database.\n");
    exit(isset($options['help']) ? 0 : 2);
}

$client = null;
$stage = 'configuration';
$report = ['tool' => 'm3-bitso-withdrawal-check', 'read_only' => true,
    'scope' => 'first_withdrawal_page', 'page_limit' => 100];
try {
    $config = Config::load($options['config']);
    $matches = array_values(array_filter($config->exchange['accounts'],
        static fn(array $account): bool => $account['account_id'] === $options['account']));
    if (count($matches) !== 1) {
        throw new RuntimeException('Configured account not found.');
    }
    $account = $matches[0];
    $report['account_id'] = $account['account_id'];
    $stage = 'credentials';
    $client = new BitsoReadClient(['enableRateLimit' => true, 'timeout' => $config->exchange['timeout_ms'],
        'number' => 'strval', 'options' => ['defaultType' => 'spot']] + Settings::credentials($account));
    $stage = 'fetch_withdrawals';
    $rows = await($client->fetch_withdrawals(null, null, 100));
    $report += ['status' => 'page_checked', 'withdrawals_checked' => count($rows),
        'invoice_verified_zero_fees' => array_values(array_map(
            static fn(array $row): array => WithdrawalEvidenceError::withdrawalContext($row, $row['amount'])
                + ['fee' => '0', 'evidence_source' => $row['m3_fee_evidence']['source'],
                    'payment_hash_source' => $row['m3_fee_evidence']['payment_hash_source']],
            array_filter($rows, static fn(array $row): bool => ($row['m3_fee_evidence']['source'] ?? null) === 'bitso_lightning_invoice'))),
        'documented_spei_zero_fees' => array_values(array_map(
            static fn(array $row): array => WithdrawalEvidenceError::withdrawalContext($row, $row['amount'])
                + ['fee' => '0', 'evidence_source' => 'bitso_spei_contract',
                    'basis' => $row['m3_fee_evidence']['basis'], 'rule' => $row['m3_fee_evidence']['rule'],
                    'protocol' => $row['m3_fee_evidence']['protocol']],
            array_filter($rows, static fn(array $row): bool => ($row['m3_fee_evidence']['source'] ?? null) === 'bitso_spei_contract'))),
        'documented_bitso_transfer_zero_fees' => array_values(array_map(
            static fn(array $row): array => WithdrawalEvidenceError::withdrawalContext($row, $row['amount'])
                + ['fee' => '0', 'evidence_source' => 'bitso_transfer_contract',
                    'basis' => $row['m3_fee_evidence']['basis'], 'rule' => $row['m3_fee_evidence']['rule'],
                    'method' => 'bt', 'network' => 'BT'],
            array_filter($rows, static fn(array $row): bool => ($row['m3_fee_evidence']['source'] ?? null) === 'bitso_transfer_contract'))),
        'note' => 'This checks only the first withdrawal page; full exchange reconciliation is still required.'];
    $exit = 0;
} catch (Throwable $error) {
    $diagnostic = $error instanceof CredentialError ? $error->diagnostic
        : ($stage === 'configuration'
            ? ['reason' => 'configuration_error', 'detail' => 'Check the config file and select its Bitso exchange account_id.']
            : SyncDiagnostics::describe($error, 'bitso'));
    $report += ['status' => 'needs_review', 'stage' => $stage];
    $report += ($client?->failureContext($error) ?? []) + $diagnostic;
    $exit = 1;
} finally {
    $client?->close();
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit($exit);
