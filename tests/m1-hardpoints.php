<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/m1/bootstrap.php';

use Trademinator\Client\M1\Backup;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\HardPoints;
use Trademinator\Client\M1\Ledger;

$assertions = 0;
$assert = static function (bool $value, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$value) { throw new RuntimeException($message); }
};
$throws = static function (callable $fn, string $message) use ($assert): void {
    try { $fn(); } catch (Throwable) { $assert(true, $message); return; }
    $assert(false, $message);
};
$root = sys_get_temp_dir() . '/trademinator-m1-hardpoints-' . bin2hex(random_bytes(8));
Backup::ensureDirectory($root);
$db = new Database($root . '/client.sqlite');
$ledger = new Ledger($db);
$account = $ledger->createAccount('fixture', 'Hard-point fixture', null, ['CAD' => '1000']);
$market = $ledger->registerMarket($account, 'XRP/CAD', 'XRP', 'CAD', null, ['min_quantity' => '1', 'min_notional' => '10', 'reference_price' => '10']);
$other = $ledger->createAccount('fixture', 'Other account', null, ['USD' => '50']);
$hard = new HardPoints($db, $root);
$throws(fn() => $hard->createT0($account), 'T0 must require approval');
// No reconciliation or checkpoint is required for the user-selected initial baseline.
$t0 = $hard->createT0($account, true);
$assert($t0['active'] === true, 'T0 did not activate');
$source = $hard->verify($account, $t0['id']);
$assert($source['state']['wallet_balances']['CAD'] === '1000', 'T0 omitted wallet');
$archive = new PDO('sqlite:' . $t0['path'] . '/archive.sqlite');
$assert((int) $archive->query('SELECT COUNT(*) FROM exchange_accounts')->fetchColumn() === 1, 'Account archive leaked another account');
$archive = null;
$assert($hard->proposeT1($account)['eligible'] === false, 'T1 allowed unreconciled account');
$ledger->reconcileAccount($account, ['CAD' => '1000']);
$ledger->createCheckpoint($market);
$proposal = $hard->proposeT1($account);
$assert($proposal['eligible'], 'Ready T1 not proposed');
$assert($hard->proposeT1($account)['token'] === $proposal['token'], 'Idle proposal changed approval token');
$throws(fn() => $hard->promoteT1($account, 'wrong'), 'T1 accepted wrong token');

// A real financial mutation invalidates the exact candidate that was reviewed.
$ledger->recordOperation(['account_id' => $account, 'exchange_operation_id' => 'deposit-1', 'kind' => 'deposit', 'currency' => 'CAD', 'amount' => '1']);
$throws(fn() => $hard->promoteT1($account, $proposal['token']), 'T1 accepted stale revision');
$ledger->reconcileAccount($account, ['CAD' => '1001']);
$proposal = $hard->proposeT1($account);
$before = $ledger->accountSnapshot($account);
$failing = new HardPoints($db, $root, static function (string $stage): void {
    if ($stage === 'hardpoint.before_commit') { throw new RuntimeException('injected before commit'); }
});
$throws(fn() => $failing->promoteT1($account, $proposal['token']), 'Precommit fault did not fire');
$assert($ledger->accountSnapshot($account) === $before, 'Precommit fault changed account rows');
$recovered = $hard->recover($account);
$assert($recovered[0]['action'] === 'rolled_back', 'Precommit recovery did not roll back pointer intent');
$assert($hard->active($account)['id'] === $t0['id'], 'Rollback lost previous active point');

// A row written without a financial revision must not disappear in an archival race.
$proposal = $hard->proposeT1($account);
$racing = new HardPoints($db, $root, static function (string $stage) use ($ledger, $account, $market): void {
    if ($stage === 'hardpoint.after_archive') {
        $ledger->recordSignalDecision(['account_id' => $account, 'market_id' => $market, 'decision_key' => 'archive-race', 'signal' => 'HOLD']);
    }
});
$throws(fn() => $racing->promoteT1($account, $proposal['token']), 'Concurrent informational row escaped archive guard');
$hard->recover($account);
$assert((int) $db->pdo()->query('SELECT COUNT(*) FROM signal_decisions')->fetchColumn() === 1, 'Archive race deleted unaudited decision');

$proposal = $hard->proposeT1($account);
$failing = new HardPoints($db, $root, static function (string $stage): void {
    if ($stage === 'hardpoint.after_commit') { throw new RuntimeException('injected after commit'); }
});
$throws(fn() => $failing->promoteT1($account, $proposal['token']), 'Postcommit fault did not fire');
$assert($hard->active($account)['id'] === $t0['id'], 'Postcommit fixture unexpectedly published pointer');
$recovered = $hard->recover($account);
$assert($recovered[0]['action'] === 'completed', 'Committed hard point not completed on recovery');
$active = $hard->active($account);
$assert($active['id'] !== $t0['id'], 'New hard point did not become active');
$assert($hard->proposeT1($account)['eligible'] === false, 'Idle account immediately reproposed another T1');
$assert(is_file($t0['path'] . '/archive.sqlite'), 'T1 pruned permanent T0');
$assert((int) $db->pdo()->query('SELECT COUNT(*) FROM operations')->fetchColumn() === 0, 'T1 failed to archive historical operations');
$assert($ledger->accountState($account)['wallet_balances']['CAD'] === '1001', 'T1 changed wallet balance');
$assert($ledger->accountState($other)['wallet_balances']['USD'] === '50', 'T1 changed another account');

$unknown = $ledger->createAccount('fixture', 'Unknown-cost initial holding', null, ['XRP' => '0.01']);
$unknownMarket = $ledger->registerMarket($unknown, 'XRP/CAD', 'XRP', 'CAD', null, ['min_quantity' => '1', 'min_notional' => '10', 'reference_price' => '10']);
$unknownPoint = $hard->createT0($unknown, true);
$unknownState = $hard->verify($unknown, $unknownPoint['id'])['state'];
$assert($unknownState['markets'][0]['dust'], 'Fixture did not contain initial dust');
$assert(!$unknownState['markets'][0]['acquisition_cost_known'], 'T0 invented an acquisition cost');
$ledger->recordOrder(['account_id' => $unknown, 'market_id' => $unknownMarket, 'exchange_order_id' => 'open-initial', 'side' => 'BUY', 'quantity' => '1', 'price' => '10']);
$throws(fn() => $hard->createT0($unknown, true), 'T0 accepted an open order');

// Reconstruct this account into an empty database from permanent off-database files.
$freshDir = $root . '/fresh';
Backup::ensureDirectory($freshDir);
$fresh = new Database($freshDir . '/client.sqlite');
$restorer = new HardPoints($fresh, $root);
$restored = $restorer->restore($account, $active['id'], true);
$freshLedger = new Ledger($fresh);
$assert($restored['restored'], 'From-scratch restore failed');
$assert($freshLedger->accountState($account)['wallet_balances']['CAD'] === '1001', 'Rebuild lost wallet balance');
$assert(!$freshLedger->accountState($account)['reconciled'], 'Historical reconstruction was incorrectly treated as current');
$assert($fresh->integrityCheck()['ok'], 'Rebuilt database invalid');
unset($restorer, $freshLedger, $fresh);

$backup = new Backup($db, $root);
$day = 1_800_000_000_000;
for ($i = 0; $i < 10; ++$i) {
    $backup->tick($day + $i * 86_400_000, $i * 1000);
}
$assert(count($backup->list('daily')) === 7, 'Daily backups did not retain exactly seven');
$assert(count($backup->list('trades')) === 7, 'Trade backups did not retain exactly seven independently');
$assert($backup->tick($day + 9 * 86_400_000, 9000) === [], 'Idle tick created duplicate backup');
$assert($backup->completedTradesIfDue(10500, $day + 9 * 86_400_000 + 1) !== null, 'Observed trade threshold did not trigger');
$assert($backup->completedTradesIfDue(11000, $day + 9 * 86_400_000 + 2) !== null, 'Trade threshold drifted after an observed overshoot');
$migration = $backup->preMigration($day + 10 * 86_400_000);
$assert(Backup::verifySnapshot($migration['path'])['integrity_check'] === 'ok', 'Pre-migration backup not verified');
$beforePaths = array_column($backup->list('daily'), 'path');
$failedBackup = new Backup($db, $root, static function (string $stage): void {
    if ($stage === 'backup.before_publish') { throw new RuntimeException('injected publish failure'); }
});
$throws(fn() => $failedBackup->create('daily', $day + 11 * 86_400_000), 'Backup fault did not fire');
$assert(array_column($backup->list('daily'), 'path') === $beforePaths, 'Failed backup pruned existing good copies');

// Corrupt the newest candidate; recovery must actually use the previous verified file.
$latest = $backup->create('manual', $day + 12 * 86_400_000);
file_put_contents($latest['path'], 'broken sqlite');
$assert($backup->list('manual') === [], 'Corrupt snapshot counted as usable');
unset($backup, $failedBackup, $failing, $racing, $hard, $ledger, $db);
gc_collect_cycles();
file_put_contents($root . '/client.sqlite', 'corrupt primary');
$fallback = Backup::restoreLatest($root, true);
$assert($fallback['source'] === $migration['path'], 'Recovery did not skip newest corrupt snapshot');
$assert(count($fallback['skipped']) >= 1, 'Recovery did not report corrupt fallback');
$reopened = new Database($root . '/client.sqlite');
$assert($reopened->integrityCheck()['ok'], 'Recovered primary failed integrity check');
$assert(!(new Ledger($reopened))->accountState($account)['reconciled'], 'Recovered state incorrectly marked current');
unset($reopened);
Backup::removeTree($root);
echo json_encode(['suite' => 'm1-hardpoints', 'status' => 'passed', 'assertions' => $assertions], JSON_PRETTY_PRINT) . PHP_EOL;
