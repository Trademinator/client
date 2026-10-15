<?php
declare(strict_types=1);

require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Backup;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\HardPoints;
use Trademinator\Client\M1\Ledger;
use Trademinator\Client\M1\Paths;

/** All prices, identifiers, balances, clocks and account approvals here are synthetic. */
function m1FixtureCheck(bool $condition, string $name, array &$checks): void
{
    if (!$condition) { throw new RuntimeException('Fixture check failed: '.$name); }
    $checks[$name] = 'passed';
}

function m1FixtureReject(callable $callback, string $name, array &$checks): void
{
    try { $callback(); } catch (Throwable) { $checks[$name] = 'passed'; return; }
    throw new RuntimeException('Fixture expected rejection: '.$name);
}

function m1FixtureEqualBalances(array $expected, array $actual): bool
{
    foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $currency) {
        if (bccomp((string) ($expected[$currency] ?? '0'), (string) ($actual[$currency] ?? '0'), 24) !== 0) { return false; }
    }
    return true;
}

function m1FixtureOrder(string $account, string $market, string $key, string $side, string $quantity,
    string $filled, string $price, string $fee, int $time): array
{
    return ['account_id' => $account, 'market_id' => $market, 'exchange_order_id' => $key,
        'side' => $side, 'quantity' => $quantity, 'filled_quantity' => $filled,
        'remaining_quantity' => bcsub($quantity, $filled, 24), 'price' => $price, 'average_price' => $price,
        'fee_amount' => $fee, 'fee_currency' => 'USD',
        'status' => bccomp($quantity, $filled, 24) === 0 ? 'filled' : 'partially_filled',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', intdiv($time, 1000)),
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z', intdiv($time, 1000))];
}

function m1FixtureSetup(string $directory, string $label = 'Fixture', array $bases = ['XRP', 'ATOM', 'BTC']): array
{
    $database = new Database($directory.'/client.sqlite');
    $ledger = new Ledger($database);
    $account = $ledger->createAccount('fixture', $label, '11111111-1111-4111-8111-111111111111', ['USD' => '1000000']);
    $markets = [];
    foreach ($bases as $base) {
        $markets[$base] = $ledger->registerMarket($account, $base.'/USD', $base, 'USD', 'fixture-'.strtolower($base),
            ['min_quantity' => '1', 'min_notional' => '1', 'reference_price' => '10']);
    }
    $ledger->reconcileAccount($account, ['USD' => '1000000']);
    $hardPoints = new HardPoints($database, $directory);
    $hardPoints->createT0($account, true);
    return [$database, $ledger, $account, $markets, $hardPoints];
}

function m1FixtureLoad(string $directory, int $orders, array &$checks): array
{
    [$database, $ledger, $account, $markets, $hardPoints] = m1FixtureSetup($directory);
    $backup = new Backup($database, $directory);
    $startMs = 1767225600000; // 2026-01-01T00:00:00Z
    $wallet = ['USD' => '1000000'];
    $pairs = intdiv($orders, 2);
    $completed = 0;
    $checkpointCount = 0;
    $observations = 0;
    $allocations = 0;
    $createdBackups = ['daily' => 0, 'trades' => 0];
    $fixturePromotions = 0;
    $lastBackupDay = null;
    $baseNames = array_keys($markets);
    for ($pair = 0; $pair < $pairs; $pair++) {
        $base = $baseNames[$pair % count($baseNames)];
        $market = $markets[$base];
        $time = $startMs + intdiv($pair * 10, $pairs) * 86400000 + ($pair % 86400) * 1000;
        $buyKey = 'buy-'.str_pad((string) $pair, 7, '0', STR_PAD_LEFT);
        $sellKey = 'sell-'.str_pad((string) $pair, 7, '0', STR_PAD_LEFT);
        $buy = $ledger->recordOrder(m1FixtureOrder($account, $market, $buyKey, 'BUY', '10', '4', '10', '0.04', $time));
        $observations++;
        if ($pair === 0) {
            m1FixtureCheck(!$hardPoints->proposeT1($account)['eligible'], 'Hard T1 rejects an open order', $checks);
            m1FixtureReject(fn() => $ledger->createCheckpoint($market), 'An open order prevents a checkpoint', $checks);
        }
        $ledger->recordOrder(m1FixtureOrder($account, $market, $buyKey, 'BUY', '10', '10', '10', '0.10', $time + 100));
        $observations++;
        $completed++;
        $wallet['USD'] = bcsub($wallet['USD'], '100.10', 24);
        $wallet[$base] = bcadd($wallet[$base] ?? '0', '10', 24);
        $ledger->reconcileAccount($account, $wallet);
        if ($ledger->createCheckpoint($market) === null) { throw new RuntimeException('Expected checkpoint after finalized BUY.'); }
        $checkpointCount++;
        if ($pair === 0) {
            m1FixtureCheck($ledger->createCheckpoint($market) === null, 'Idle synchronization does not duplicate a checkpoint', $checks);
        }
        $sell = $ledger->recordOrder(m1FixtureOrder($account, $market, $sellKey, 'SELL', '10', '4', '12', '0.048', $time + 200));
        $observations++;
        $ledger->allocate(['account_id' => $account, 'market_id' => $market, 'allocation_key' => $sellKey.'-partial',
            'buy_order_id' => $buy, 'sell_order_id' => $sell, 'quantity' => '4']);
        $allocations++;
        $ledger->recordOrder(m1FixtureOrder($account, $market, $sellKey, 'SELL', '10', '10', '12', '0.12', $time + 300));
        $observations++;
        $ledger->allocate(['account_id' => $account, 'market_id' => $market, 'allocation_key' => $sellKey.'-final',
            'buy_order_id' => $buy, 'sell_order_id' => $sell, 'quantity' => '6']);
        $allocations++;
        $completed++;
        $wallet['USD'] = bcadd($wallet['USD'], '119.88', 24);
        $wallet[$base] = bcsub($wallet[$base], '10', 24);
        $ledger->reconcileAccount($account, $wallet);
        if ($ledger->createCheckpoint($market) === null) { throw new RuntimeException('Expected checkpoint after matched SELL.'); }
        $checkpointCount++;
        $day = intdiv($time, 86400000);
        if ($day !== $lastBackupDay || $completed % 1000 === 0) {
            foreach ($backup->tick($time, $completed) as $reason => $unused) { $createdBackups[$reason]++; }
            $lastBackupDay = $day;
        }
        // These explicit approvals are confined to synthetic fixture accounts.
        // Every earlier checkpoint remains in its permanent Hard T1 archive.
        if ($completed % 500 === 0 && $completed < $orders) {
            $candidate = $hardPoints->proposeT1($account);
            if (!$candidate['eligible']) { throw new RuntimeException('Fixture archival boundary is ineligible.'); }
            $hardPoints->promoteT1($account, $candidate['token']);
            $fixturePromotions++;
        }
        if ($completed % 1000 === 0) { fwrite(STDERR, 'M1 synthetic completed orders: '.$completed.'/'.$orders."\n"); }
    }
    $state = $ledger->accountState($account);
    $expectedUsd = bcadd('1000000', bcmul((string) $pairs, '19.78', 24), 24);
    m1FixtureCheck(m1FixtureEqualBalances(['USD' => $expectedUsd], $state['wallet_balances']), 'Shared quote wallet equals independent round-trip calculation', $checks);
    m1FixtureCheck(m1FixtureEqualBalances($wallet, $ledger->expectedBalances($account)), 'Ledger replay equals independent fixture balances', $checks);
    m1FixtureCheck($state['reconciled'] && (int) $state['open_orders'] === 0, 'All completed orders reconcile with no open order', $checks);
    m1FixtureCheck($state['all_markets_checkpointed'], 'Every market finishes with a current checkpoint', $checks);
    m1FixtureCheck((int) $state['completed_trades'] === $orders, 'Partial observations do not inflate completed-trade count', $checks);
    m1FixtureCheck($database->integrityCheck()['ok'], 'SQLite and financial foreign keys remain valid', $checks);
    foreach ($state['markets'] as $marketState) {
        m1FixtureCheck(!$marketState['dust'] && bccomp((string) $marketState['unmatched_sell_quantity'], '0', 24) === 0,
            $marketState['symbol'].' has no dust or unmatched SELL quantity', $checks);
    }
    $daily = count($backup->list('daily'));
    $trades = count($backup->list('trades'));
    m1FixtureCheck($daily === min(7, min(10, $pairs)), 'Daily backups retain the latest seven independently', $checks);
    m1FixtureCheck($trades === min(7, intdiv($orders, 1000)), 'Every 1000 completed trades retain seven independent backups', $checks);
    m1FixtureCheck($backup->tick($time, $completed) === [], 'Idle backup tick creates no duplicate daily or trade backup', $checks);
    return ['completed_orders' => $completed, 'order_observations' => $observations, 'partial_allocations' => $allocations,
        'round_trips' => $pairs, 'account_id' => $account, 'markets' => count($markets), 'shared_quote_currency' => 'USD',
        'expected_final_usd' => $expectedUsd, 'checkpoint_events' => $checkpointCount,
        'virtual_days' => min(10, $pairs), 'backups_created' => $createdBackups,
        'backups_retained' => ['daily' => $daily, 'trades' => $trades],
        'fixture_approved_hard_t1_promotions' => $fixturePromotions,
        'current_hardpoint' => array_intersect_key($hardPoints->active($account), array_flip(['account_id', 'id', 'kind', 'revision'])),
        'database_path' => $database->path()];
}

function m1FixtureDust(string $directory, array &$checks): array
{
    [$database, $ledger, $account, $markets, $hardPoints] = m1FixtureSetup($directory, 'Dust fixture', ['XRP']);
    $market = $markets['XRP'];
    $time = 1767225600000;
    $buy = $ledger->recordOrder(m1FixtureOrder($account, $market, 'dust-buy', 'BUY', '0.1', '0.1', '10', '0.001', $time));
    $wallet = ['USD' => '999998.999', 'XRP' => '0.1'];
    $ledger->reconcileAccount($account, $wallet);
    $state = $ledger->accountState($account);
    m1FixtureCheck($state['markets'][0]['dust'], 'Residual quantity below minimum is dust', $checks);
    m1FixtureReject(fn() => $ledger->createCheckpoint($market), 'Dust prevents a market checkpoint', $checks);
    m1FixtureCheck(!$hardPoints->proposeT1($account)['eligible'], 'Dust prevents a Hard T1 candidate', $checks);
    // A synthetic inventory top-up permits selling the combined amount; this is
    // accounting coverage, not an M1 trading or autonomous dedusting strategy.
    $second = $ledger->recordOrder(m1FixtureOrder($account, $market, 'dust-top-up', 'BUY', '0.9', '0.9', '10', '0.009', $time + 1000));
    $sell = $ledger->recordOrder(m1FixtureOrder($account, $market, 'dust-sell', 'SELL', '1', '1', '12', '0.012', $time + 2000));
    foreach ([[$buy, '0.1', 'dust-match'], [$second, '0.9', 'top-up-match']] as [$source, $quantity, $key]) {
        $ledger->allocate(['account_id' => $account, 'market_id' => $market, 'allocation_key' => $key,
            'buy_order_id' => $source, 'sell_order_id' => $sell, 'quantity' => $quantity]);
    }
    $ledger->reconcileAccount($account, ['USD' => '1000001.978']);
    m1FixtureCheck($ledger->createCheckpoint($market) !== null, 'Fully allocated dedusting permits a new checkpoint', $checks);
    m1FixtureCheck(!$ledger->accountState($account)['markets'][0]['dust'], 'Dust clears after both acquisition lots are consumed', $checks);
    return ['initial_dust_quantity' => '0.1', 'dedusted_quantity' => '1'];
}

function m1FixtureCopyTree(string $source, string $destination): void
{
    Backup::ensureDirectory($destination);
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $from = $source.'/'.$entry;
        $to = $destination.'/'.$entry;
        if (is_link($from)) { throw new RuntimeException('Unexpected symbolic link in generated fixture.'); }
        if (is_dir($from)) { m1FixtureCopyTree($from, $to); }
        elseif (!copy($from, $to)) { throw new RuntimeException('Cannot copy fixture archive.'); }
    }
}

function m1FixtureHardPoints(string $directory, array &$checks): array
{
    [$database, $ledger, $account, $markets, $hardPoints] = m1FixtureSetup($directory, 'Hard-point fixture', ['XRP', 'ATOM']);
    $original = $hardPoints->active($account);
    m1FixtureReject(fn() => $hardPoints->createT0($account), 'Hard T0 requires explicit approval', $checks);
    $time = 1767225600000;
    $market = $markets['XRP'];
    $ledger->recordOrder(m1FixtureOrder($account, $market, 'carry-buy', 'BUY', '10', '4', '10', '0.04', $time));
    m1FixtureReject(fn() => $hardPoints->createT0($account, true), 'Hard T0 rejects an account with an open order', $checks);
    $buy = $ledger->recordOrder(m1FixtureOrder($account, $market, 'carry-buy', 'BUY', '10', '10', '10', '0.10', $time + 1000));
    $sell = $ledger->recordOrder(m1FixtureOrder($account, $market, 'carry-sell', 'SELL', '4', '4', '12', '0.048', $time + 2000));
    $ledger->allocate(['account_id' => $account, 'market_id' => $market, 'allocation_key' => 'carry-match',
        'buy_order_id' => $buy, 'sell_order_id' => $sell, 'quantity' => '4']);
    $wallet = ['USD' => '999947.852', 'XRP' => '6'];
    $ledger->reconcileAccount($account, $wallet);
    $ledger->createCheckpoint($market);
    m1FixtureCheck(!$hardPoints->proposeT1($account)['eligible'], 'Every market needs a checkpoint before Hard T1', $checks);
    $ledger->createCheckpoint($markets['ATOM']);
    $candidate = $hardPoints->proposeT1($account);
    m1FixtureCheck($candidate['eligible'] && $hardPoints->active($account)['id'] === $original['id'],
        'Eligible Hard T1 remains a candidate until explicitly approved', $checks);
    $ledger->recordOperation(['account_id' => $account, 'exchange_operation_id' => 'candidate-invalidation',
        'kind' => 'deposit', 'currency' => 'USD', 'amount' => '1', 'occurred_at' => '2026-01-01T00:00:03Z']);
    $wallet['USD'] = '999948.852';
    $ledger->reconcileAccount($account, $wallet);
    foreach ($markets as $id) { $ledger->createCheckpoint($id); }
    m1FixtureReject(fn() => $hardPoints->promoteT1($account, $candidate['token']), 'Financial activity invalidates a previous approval token', $checks);
    $candidate = $hardPoints->proposeT1($account);
    m1FixtureCheck($candidate['eligible'], 'Reconciled activity can produce a fresh Hard T1 candidate', $checks);
    $beforeRevision = $database->revision($account);
    $faulted = new HardPoints($database, $directory, static function (string $stage): void {
        if ($stage === 'hardpoint.before_commit') { throw new RuntimeException('Injected archive failure before SQLite commit.'); }
    });
    m1FixtureReject(fn() => $faulted->promoteT1($account, $candidate['token']), 'Interrupted archival reports failure before commit', $checks);
    m1FixtureCheck($database->revision($account) === $beforeRevision && $hardPoints->active($account)['id'] === $original['id'],
        'Interrupted archival preserves the previous active baseline', $checks);
    $recovery = $hardPoints->recover($account);
    m1FixtureCheck(($recovery[0]['action'] ?? null) === 'rolled_back', 'Precommit archival intent rolls back on recovery', $checks);
    $candidate = $hardPoints->proposeT1($account);
    $faulted = new HardPoints($database, $directory, static function (string $stage): void {
        if ($stage === 'hardpoint.after_commit') { throw new RuntimeException('Injected archive failure after SQLite commit.'); }
    });
    m1FixtureReject(fn() => $faulted->promoteT1($account, $candidate['token']), 'Interrupted archival reports failure after commit', $checks);
    $recovery = $hardPoints->recover($account);
    m1FixtureCheck(($recovery[0]['action'] ?? null) === 'completed', 'Committed archival intent completes on recovery', $checks);
    $active = $hardPoints->active($account);
    $hard = $hardPoints->verify($account, $active['id']);
    m1FixtureCheck($active['id'] !== $original['id'] && $hard['kind'] === 'HardT1', 'Approved Hard T1 becomes the new active baseline', $checks);
    m1FixtureCheck($hardPoints->verify($account, $original['id'])['kind'] === 'HardT0', 'Previous Hard T0 remains permanently reconstructable', $checks);
    m1FixtureCheck(count($hard['snapshot']['orders']) === 2 && count($hard['snapshot']['buys_vs_sells']) === 1
        && count($hard['snapshot']['operations']) === 1, 'Archive preserves original orders partial matching and operations', $checks);
    m1FixtureCheck((int) $database->pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0,
        'Promoted source orders leave the active database only after archival', $checks);
    $lots = $ledger->marketLots($market);
    m1FixtureCheck(count($lots) === 1 && bccomp((string) $lots[0]['quantity'], '6', 24) === 0
        && bccomp((string) $lots[0]['unit_cost'], '10.01', 24) === 0, 'Remaining BUY quantity and acquisition cost survive promotion', $checks);
    m1FixtureCheck(m1FixtureEqualBalances($wallet, $ledger->expectedBalances($account)), 'Promotion preserves the shared wallet exactly', $checks);
    $rebuiltDir = $directory.'/rebuilt';
    m1FixtureCopyTree($directory.'/hard-points', $rebuiltDir.'/hard-points');
    $rebuiltDatabase = new Database($rebuiltDir.'/client.sqlite');
    $rebuiltHardPoints = new HardPoints($rebuiltDatabase, $rebuiltDir);
    $rebuiltHardPoints->restore($account, $active['id'], true);
    $rebuiltLedger = new Ledger($rebuiltDatabase);
    $rebuiltLots = $rebuiltLedger->marketLots($market);
    m1FixtureCheck(m1FixtureEqualBalances($wallet, $rebuiltLedger->expectedBalances($account))
        && count($rebuiltLots) === 1 && bccomp((string) $rebuiltLots[0]['unit_cost'], '10.01', 24) === 0
        && bccomp((string) $rebuiltLots[0]['quantity'], '6', 24) === 0,
        'Empty-database rebuild restores wallet inventory and acquisition costs', $checks);
    return ['account_id' => $account, 'original_hard_t0' => $original['id'], 'promoted_hard_t1' => $active['id'],
        'carried_quantity' => '6', 'carried_unit_cost' => '10.01', 'rebuilt_database' => $rebuiltDatabase->path()];
}

function m1FixtureBackups(string $directory, array &$checks): array
{
    [$database, $ledger, $account, $markets, $hardPoints] = m1FixtureSetup($directory, 'Backup recovery fixture', ['XRP']);
    $backup = new Backup($database, $directory);
    $first = $backup->create('manual', 1767225600000, 0);
    $faulted = new Backup($database, $directory, static function (string $stage): void {
        if ($stage === 'backup.before_publish') { throw new RuntimeException('Injected backup publication failure.'); }
    });
    m1FixtureReject(fn() => $faulted->create('manual', 1767225601000, 0), 'Interrupted backup publication reports failure', $checks);
    m1FixtureCheck(count($backup->list('manual')) === 1 && $database->integrityCheck()['ok'],
        'Interrupted backup leaves the previous verified snapshot and primary intact', $checks);
    $ledger->recordOperation(['account_id' => $account, 'exchange_operation_id' => 'backup-deposit',
        'kind' => 'deposit', 'currency' => 'USD', 'amount' => '1', 'occurred_at' => '2026-01-01T00:00:02Z']);
    $ledger->reconcileAccount($account, ['USD' => '1000001']);
    $second = $backup->create('manual', 1767225602000, 0);
    file_put_contents($second['path'], 'deliberately corrupt synthetic SQLite snapshot');
    m1FixtureReject(fn() => Backup::verifySnapshot($second['path'], $second['database']['sha256']),
        'Corrupted backup fails hash and integrity verification', $checks);
    unset($faulted, $backup, $hardPoints, $ledger, $database);
    $restored = Backup::restoreLatest($directory, true);
    m1FixtureCheck($restored['status'] === 'restored' && count($restored['skipped']) >= 1,
        'Recovery skips the corrupt newest backup and uses a verified predecessor', $checks);
    $database = new Database($directory.'/client.sqlite');
    $ledger = new Ledger($database);
    m1FixtureCheck(m1FixtureEqualBalances(['USD' => '1000000'], $ledger->expectedBalances($account)),
        'Backup fallback reconstructs the selected snapshot balances', $checks);
    m1FixtureCheck(!$ledger->accountState($account)['reconciled'], 'Backup restore requires fresh authoritative reconciliation', $checks);
    return ['valid_predecessor' => $first['path'], 'corrupt_snapshot' => $second['path'], 'restored' => $restored];
}

function m1FixtureIsolation(string $directory, array &$checks): array
{
    [$leftDatabase, $leftLedger, $leftAccount] = m1FixtureSetup($directory.'/user-a', 'Synthetic user A', ['XRP']);
    [$rightDatabase, $rightLedger, $rightAccount] = m1FixtureSetup($directory.'/user-b', 'Synthetic user B', ['XRP']);
    $leftLedger->recordOperation(['account_id' => $leftAccount, 'exchange_operation_id' => 'isolated-deposit',
        'kind' => 'deposit', 'currency' => 'USD', 'amount' => '10', 'occurred_at' => '2026-01-01T00:00:01Z']);
    $leftLedger->reconcileAccount($leftAccount, ['USD' => '1000010']);
    m1FixtureCheck(m1FixtureEqualBalances(['USD' => '1000000'], $rightLedger->expectedBalances($rightAccount))
        && m1FixtureEqualBalances(['USD' => '1000010'], $leftLedger->expectedBalances($leftAccount)),
        'Separate user data directories isolate identical account identifiers', $checks);
    $other = $leftLedger->createAccount('fixture', 'Another exchange account', '22222222-2222-4222-8222-222222222222', ['USD' => '7']);
    $leftLedger->registerMarket($other, 'ATOM/USD', 'ATOM', 'USD', 'fixture-other-market');
    $leftLedger->reconcileAccount($other, ['USD' => '7']);
    m1FixtureCheck(m1FixtureEqualBalances(['USD' => '1000010'], $leftLedger->expectedBalances($leftAccount))
        && m1FixtureEqualBalances(['USD' => '7'], $leftLedger->expectedBalances($other)),
        'Exchange accounts isolate wallets within one user directory', $checks);
    return ['data_directories' => 2, 'accounts_within_one_directory' => 2];
}

$options = ['orders' => '10000', 'scenario' => 'all'];
$explicit = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo "Usage: php tools/m1-simulate.php --data-dir=NEW_DIRECTORY [--orders=10000] [--scenario=all|load]\n";
        echo "Uses synthetic data only; refuses any existing destination. Orders must be even and at least 6.\n";
        exit(0);
    }
    if (!preg_match('/^--(data-dir|orders|scenario)=(.+)$/D', $argument, $match) || isset($explicit[$match[1]])) {
        fwrite(STDERR, "Invalid or duplicate argument: {$argument}\n"); exit(2);
    }
    $options[$match[1]] = $match[2];
    $explicit[$match[1]] = true;
}
if (!isset($options['data-dir']) || !ctype_digit($options['orders']) || (int) $options['orders'] < 6
    || (int) $options['orders'] > 100000 || (int) $options['orders'] % 2 !== 0
    || !in_array($options['scenario'], ['all', 'load'], true)) {
    fwrite(STDERR, "Provide --data-dir=NEW_DIRECTORY, an even --orders from 6 to 100000, and --scenario=all|load.\n"); exit(2);
}
$report = ['milestone' => 'M1', 'mode' => 'synthetic', 'status' => 'failed', 'scenario' => $options['scenario'],
    'network_requests' => 0, 'exchange_orders_submitted' => 0, 'checks' => []];
$reportPath = null;
$started = microtime(true);
try {
    $directory = Paths::absolute($options['data-dir']);
    if (file_exists($directory) || is_link($directory)) { throw new RuntimeException('Simulator refuses an existing data directory. Choose a new destination.'); }
    foreach (['pdo_sqlite', 'bcmath'] as $extension) {
        if (!extension_loaded($extension)) { throw new RuntimeException('Required PHP extension is missing: '.$extension); }
    }
    umask(0077);
    if (!mkdir($directory, 0700, true)) { throw new RuntimeException('Cannot create fixture directory.'); }
    $reportPath = $directory.'/simulation-report.json';
    $report['workload'] = m1FixtureLoad($directory.'/load', (int) $options['orders'], $report['checks']);
    if ($options['scenario'] === 'all') {
        $report['dust'] = m1FixtureDust($directory.'/dust', $report['checks']);
        $report['hard_points'] = m1FixtureHardPoints($directory.'/hard-points-scenario', $report['checks']);
        $report['backup_recovery'] = m1FixtureBackups($directory.'/backup-recovery', $report['checks']);
        $report['isolation'] = m1FixtureIsolation($directory.'/isolation', $report['checks']);
    }
    $report['status'] = 'passed';
} catch (Throwable $error) {
    $report['error'] = $error->getMessage();
}
$report['seconds'] = round(microtime(true) - $started, 3);
$report['peak_memory_bytes'] = memory_get_peak_usage(true);
$report['report_path'] = $reportPath;
if ($reportPath !== null) {
    try { Backup::atomicJson($reportPath, $report); }
    catch (Throwable $error) { $report['status'] = 'failed'; $report['report_write_error'] = $error->getMessage(); }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($report['status'] === 'passed' ? 0 : 1);
