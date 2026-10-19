<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/m1/bootstrap.php';

use Trademinator\Client\M1\Backup;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\HardPoints;
use Trademinator\Client\M1\Ledger;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
};
$reject = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); } catch (Throwable) { $assert(true, $message); return; }
    $assert(false, $message);
};
$raw = static fn(string $path): PDO => new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$withoutM2 = static function (array $snapshot): array {
    foreach ($snapshot['buys_vs_sells'] as &$allocation) { unset($allocation['buy_lot_key'], $allocation['inventory_market_id']); } unset($allocation);
    foreach ($snapshot['markets'] as &$market) { unset($market['state'], $market['subscription_id'], $market['checks_per_candle']); }
    unset($market);
    return $snapshot;
};
$downgradeFixture = static function (PDO $pdo): void {
    // Restore M1 allocation ownership before dropping the later additive columns.
    $pdo->exec('DROP TABLE movement_reviews');
    $pdo->exec('DROP TABLE transfer_labels');
    $pdo->exec(<<<'SQL'
CREATE TABLE buys_vs_sells_v1 (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL, market_id TEXT NOT NULL,
 allocation_key TEXT NOT NULL, buy_order_id TEXT, buy_operation_id TEXT, baseline_lot_id TEXT,
 sell_order_id TEXT NOT NULL, quantity TEXT NOT NULL, created_at TEXT NOT NULL,
 CHECK((buy_order_id IS NOT NULL)+(buy_operation_id IS NOT NULL)+(baseline_lot_id IS NOT NULL)=1),
 UNIQUE(account_id,allocation_key),
 FOREIGN KEY(market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE,
 FOREIGN KEY(buy_order_id,account_id,market_id) REFERENCES orders(id,account_id,market_id),
 FOREIGN KEY(buy_operation_id,account_id,market_id) REFERENCES operations(id,account_id,market_id),
 FOREIGN KEY(sell_order_id,account_id,market_id) REFERENCES orders(id,account_id,market_id)
);
INSERT INTO buys_vs_sells_v1 SELECT id,account_id,market_id,allocation_key,buy_order_id,buy_operation_id,baseline_lot_id,sell_order_id,quantity,created_at FROM buys_vs_sells;
DROP TABLE buys_vs_sells;
ALTER TABLE buys_vs_sells_v1 RENAME TO buys_vs_sells;
CREATE INDEX allocations_buy_order ON buys_vs_sells(buy_order_id);
CREATE INDEX allocations_buy_operation ON buys_vs_sells(buy_operation_id);
CREATE INDEX allocations_baseline ON buys_vs_sells(baseline_lot_id);
CREATE INDEX allocations_sell ON buys_vs_sells(sell_order_id);
DROP INDEX orders_account_identity;
DROP INDEX operations_account_identity;
SQL);
    $pdo->exec('ALTER TABLE markets DROP COLUMN state');
    $pdo->exec('ALTER TABLE markets DROP COLUMN subscription_id');
    $pdo->exec('ALTER TABLE markets DROP COLUMN checks_per_candle');
    $pdo->exec('DELETE FROM schema_migrations WHERE version>=2');
};

$root = sys_get_temp_dir() . '/trademinator-m2-storage-' . bin2hex(random_bytes(8));
Backup::ensureDirectory($root);
try {
    $db = new Database($root . '/client.sqlite');
    $ledger = new Ledger($db);
    $account = $ledger->createAccount('fixture', 'Migration fixture', null, ['USD' => '1000']);
    $market = $ledger->registerMarket($account, 'ATOM/USD', 'ATOM', 'USD', null, ['min_quantity' => '0.01', 'min_notional' => '1', 'reference_price' => '10']);
    $ledger->reconcileAccount($account, ['USD' => '1000']);
    $ledger->createCheckpoint($market);
    $buy = $ledger->recordOrder(['account_id' => $account, 'market_id' => $market, 'exchange_order_id' => 'buy-1', 'side' => 'BUY', 'quantity' => '2', 'filled_quantity' => '2', 'price' => '10.123456789012345678901234', 'status' => 'filled']);
    $sell = $ledger->recordOrder(['account_id' => $account, 'market_id' => $market, 'exchange_order_id' => 'sell-1', 'side' => 'SELL', 'quantity' => '1', 'filled_quantity' => '1', 'price' => '12', 'status' => 'filled']);
    $ledger->allocate(['account_id' => $account, 'market_id' => $market, 'allocation_key' => 'buy-sell-1', 'buy_order_id' => $buy, 'sell_order_id' => $sell, 'quantity' => '1']);
    $ledger->recordOperation(['account_id' => $account, 'exchange_operation_id' => 'deposit-1', 'kind' => 'deposit', 'currency' => 'USD', 'amount' => '0.000000000000000000000001']);
    $ledger->recordSignalDecision(['account_id' => $account, 'market_id' => $market, 'decision_key' => 'signal-1', 'signal' => 'HOLD', 'reason' => 'fixture']);
    $ledger->reconcileAccount($account, $ledger->expectedBalances($account));
    $ledger->createCheckpoint($market);
    $hard = new HardPoints($db, $root);
    $t0 = $hard->createT0($account, true);
    $before = $withoutM2($ledger->accountSnapshot($account));
    $m1AppliedAt = $db->pdo()->query('SELECT applied_at FROM schema_migrations WHERE version=1')->fetchColumn();
    unset($hard, $ledger, $db);
    gc_collect_cycles();

    // Make the database and permanent hard point genuine schema-v1 fixtures.
    $pdo = $raw($root . '/client.sqlite');
    $downgradeFixture($pdo);
    $pdo = null;
    $archive = $raw($t0['path'] . '/archive.sqlite');
    $downgradeFixture($archive);
    $archive = null;
    $historical = Backup::readJson($t0['path'] . '/hard.json');
    $historical['snapshot'] = $withoutM2($historical['snapshot']);
    Backup::atomicJson($t0['path'] . '/hard.json', $historical);
    $manifest = Backup::readJson($t0['path'] . '/manifest.json');
    $manifest['archive'] = Backup::verifySnapshot($t0['path'] . '/archive.sqlite');
    $manifest['hard_json_sha256'] = hash_file('sha256', $t0['path'] . '/hard.json');
    Backup::atomicJson($t0['path'] . '/manifest.json', $manifest);

    $db = new Database($root . '/client.sqlite');
    $ledger = new Ledger($db);
    $assert(Database::schemaVersion($db->pdo()) === Database::SCHEMA_VERSION, 'M1 did not migrate to current schema.');
    $assert($withoutM2($ledger->accountSnapshot($account)) === $before, 'Migration changed financial records, decimal amounts, or historical JSON.');
    $assert($db->pdo()->query('SELECT applied_at FROM schema_migrations WHERE version=1')->fetchColumn() === $m1AppliedAt, 'Migration rewrote M1 migration history.');
    $state = $db->pdo()->query('SELECT state,subscription_id,checks_per_candle FROM markets')->fetch();
    $assert($state === ['state' => 'STOPPED', 'subscription_id' => null, 'checks_per_candle' => null], 'M1 markets did not receive conservative lifecycle defaults.');
    $backup = new Backup($db, $root);
    $copies = $backup->list('pre-migration');
    $assert(count($copies) === 1, 'Migration did not publish one discoverable verified backup.');
    $assert(Backup::verifySnapshot($copies[0]['path'])['schema_version'] === 1, 'Pre-migration backup already contains the new schema.');
    $pdo = $raw($copies[0]['path']);
    $assert($pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll() === $before['orders'], 'Pre-migration backup changed order data.');
    $pdo = null;
    $db->migrate();
    $assert(count($backup->list('pre-migration')) === 1, 'Reopening schema 2 created a redundant migration backup.');

    foreach (['UNKNOWN', 'active', '', 'DEACTIVATED'] as $badState) {
        $reject(fn() => $db->pdo()->exec('UPDATE markets SET state=' . $db->pdo()->quote($badState)), 'Invalid market state was accepted.');
    }
    foreach (['0', '-1', '60', '1.5', "'three'"] as $badChecks) {
        $reject(fn() => $db->pdo()->exec('UPDATE markets SET checks_per_candle=' . $badChecks), 'Invalid check count was accepted.');
    }
    $db->pdo()->exec("UPDATE markets SET state='ACTIVE',subscription_id='subscription-1',checks_per_candle=59");
    unset($backup, $ledger, $db);
    gc_collect_cycles();
    $db = new Database($root . '/client.sqlite');
    $row = $db->pdo()->query('SELECT state,subscription_id,checks_per_candle FROM markets')->fetch();
    $assert($row === ['state' => 'ACTIVE', 'subscription_id' => 'subscription-1', 'checks_per_candle' => 59], 'Lifecycle and selection did not persist on reopen.');
    $assert($db->integrityCheck()['ok'], 'Migration failed SQLite integrity checks.');

    // A post-ALTER failure must roll back the complete migration and leave a usable M1 backup.
    $failureDir = $root . '/migration-failure';
    Backup::ensureDirectory($failureDir);
    copy($copies[0]['path'], $failureDir . '/client.sqlite');
    $pdo = $raw($failureDir . '/client.sqlite');
    $pdo->exec("CREATE TRIGGER reject_m2 BEFORE INSERT ON schema_migrations WHEN NEW.version=2 BEGIN SELECT RAISE(ABORT,'migration fault'); END");
    $pdo = null;
    $reject(fn() => new Database($failureDir . '/client.sqlite'), 'Migration fault did not stop startup.');
    gc_collect_cycles();
    $pdo = $raw($failureDir . '/client.sqlite');
    $assert(Database::schemaVersion($pdo) === 1, 'Failed migration advanced the schema version.');
    $assert(!in_array('state', array_column($pdo->query('PRAGMA table_info(markets)')->fetchAll(), 'name'), true), 'Failed migration leaked partially added columns.');
    $assert($pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll() === $before['orders'], 'Failed migration changed financial rows.');
    $pdo = null;
    $assert(count(glob($failureDir . '/backups/pre-migration/*/manifest.json') ?: []) === 1, 'Failed migration lost its pre-migration backup.');

    // The existing backup-recovery command can recover from the actual migration backup.
    $recoveryDir = $root . '/pre-migration-recovery';
    Backup::ensureDirectory($recoveryDir . '/backups/pre-migration/' . $copies[0]['id']);
    copy($copies[0]['path'], $recoveryDir . '/backups/pre-migration/' . $copies[0]['id'] . '/database.sqlite');
    copy(dirname($copies[0]['path']) . '/manifest.json', $recoveryDir . '/backups/pre-migration/' . $copies[0]['id'] . '/manifest.json');
    file_put_contents($recoveryDir . '/client.sqlite', 'corrupt primary');
    $recovered = Backup::restoreLatest($recoveryDir, true);
    $assert($recovered['status'] === 'restored', 'Pre-migration recovery failed.');
    $recovery = new Database($recoveryDir . '/client.sqlite');
    $assert($recovery->pdo()->query('SELECT * FROM orders ORDER BY id')->fetchAll() === $before['orders'], 'Recovery lost exact order amounts.');
    $assert(!(new Ledger($recovery))->accountState($account)['reconciled'], 'Recovery treated historical balances as current.');
    unset($recovery);

    // Legacy hard points have no M2 fields and must restore using defaults.
    $freshDir = $root . '/legacy-hardpoint';
    $fresh = new Database($freshDir . '/client.sqlite');
    $restorer = new HardPoints($fresh, $root);
    $restorer->restore($account, $t0['id'], true);
    $assert($fresh->pdo()->query('SELECT state FROM markets')->fetchColumn() === 'STOPPED', 'Legacy hard point activated a market.');
    $assert((new Ledger($fresh))->expectedBalances($account) === (new Ledger($db))->expectedBalances($account), 'Legacy hard point lost balances.');
    unset($restorer, $fresh);

    // Schema-2 hard points and full backups must not revive old subscription authorization.
    $hard = new HardPoints($db, $root);
    $modern = $hard->createT0($account, true);
    $modernDb = new Database($root . '/modern-hardpoint/client.sqlite');
    $modernRestorer = new HardPoints($modernDb, $root);
    $modernRestorer->restore($account, $modern['id'], true);
    $row = $modernDb->pdo()->query('SELECT state,subscription_id,checks_per_candle FROM markets')->fetch();
    $assert($row === ['state' => 'STOPPED', 'subscription_id' => null, 'checks_per_candle' => null], 'Schema-2 hard point revived historical authorization.');
    unset($modernRestorer, $modernDb, $hard);
    $ledger = new Ledger($db);
    $ledger->recordOrder(['account_id' => $account, 'market_id' => $market, 'exchange_order_id' => 'open-1', 'side' => 'BUY', 'quantity' => '1', 'price' => '10']);
    $backup = new Backup($db, $root);
    $manual = $backup->create('manual');
    unset($backup, $ledger, $db);
    gc_collect_cycles();
    $result = Backup::restoreLatest($root, true);
    $assert($result['source'] === $manual['path'], 'Recovery selected the wrong snapshot.');
    $db = new Database($root . '/client.sqlite');
    $row = $db->pdo()->query('SELECT state,subscription_id,checks_per_candle FROM markets')->fetch();
    $assert($row === ['state' => 'STOPPING', 'subscription_id' => null, 'checks_per_candle' => null], 'Backup recovery failed to retain monitoring for an open order.');
    $assert($db->integrityCheck()['ok'], 'Recovered schema-2 snapshot failed integrity checks.');
    unset($db);
} finally {
    gc_collect_cycles();
    Backup::removeTree($root);
}
echo json_encode(['suite' => 'm2-storage', 'status' => 'passed', 'assertions' => $assertions], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
