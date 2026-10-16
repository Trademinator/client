<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Backup, Database, Ledger};

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $fn, string $message): void
{
    $failed = false;
    try { $fn(); } catch (Throwable) { $failed = true; }
    check($failed, $message);
}
function raw(string $path): PDO
{
    return new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function insertAllocation(PDO $pdo, array $row): void
{
    $pdo->prepare('INSERT INTO buys_vs_sells('.implode(',', array_keys($row)).') VALUES('.implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
}

$root = sys_get_temp_dir().'/tm-schema5-'.bin2hex(random_bytes(6));
Backup::ensureDirectory($root);
try {
    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    $account = $ledger->createAccount('fixture', 'schema-four', null, ['USD' => '1000','EUR' => '1000']);
    $market = $ledger->registerMarket($account, 'BTC/USD', 'BTC', 'USD');
    $buy = $ledger->recordOrder(['account_id' => $account,'market_id' => $market,'exchange_order_id' => 'buy','side' => 'BUY','quantity' => '2','filled_quantity' => '2','price' => '10','status' => 'filled','occurred_at' => '2024-01-01T00:00:00Z']);
    $sell = $ledger->recordOrder(['account_id' => $account,'market_id' => $market,'exchange_order_id' => 'sell','side' => 'SELL','quantity' => '1','filled_quantity' => '1','price' => '20','status' => 'filled','occurred_at' => '2024-01-02T00:00:00Z']);
    $ledger->allocate(['account_id' => $account,'market_id' => $market,'allocation_key' => 'legacy','buy_order_id' => $buy,'sell_order_id' => $sell,'quantity' => '1']);
    $ledger->reconcileAccount($account, $ledger->expectedBalances($account));
    $ledger->createCheckpoint($market);
    $before = $ledger->accountSnapshot($account);
    unset($ledger, $db);

    // Schema 4's original composite source foreign keys require the same market.
    $pdo = raw($root.'/client.sqlite');
    $pdo->exec(<<<'SQL'
PRAGMA foreign_keys=OFF;
BEGIN IMMEDIATE;
CREATE TABLE buys_vs_sells_v4 (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL, market_id TEXT NOT NULL,
 allocation_key TEXT NOT NULL, buy_order_id TEXT, buy_operation_id TEXT, baseline_lot_id TEXT,
 sell_order_id TEXT NOT NULL, quantity TEXT NOT NULL, created_at TEXT NOT NULL, buy_lot_key TEXT,
 CHECK((buy_order_id IS NOT NULL)+(buy_operation_id IS NOT NULL)+(baseline_lot_id IS NOT NULL)=1),
 UNIQUE(account_id,allocation_key),
 FOREIGN KEY(market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE,
 FOREIGN KEY(buy_order_id,account_id,market_id) REFERENCES orders(id,account_id,market_id),
 FOREIGN KEY(buy_operation_id,account_id,market_id) REFERENCES operations(id,account_id,market_id),
 FOREIGN KEY(sell_order_id,account_id,market_id) REFERENCES orders(id,account_id,market_id)
);
INSERT INTO buys_vs_sells_v4 SELECT id,account_id,market_id,allocation_key,buy_order_id,buy_operation_id,baseline_lot_id,sell_order_id,quantity,created_at,buy_lot_key FROM buys_vs_sells;
DROP TABLE buys_vs_sells;
ALTER TABLE buys_vs_sells_v4 RENAME TO buys_vs_sells;
CREATE INDEX allocations_buy_order ON buys_vs_sells(buy_order_id);
CREATE INDEX allocations_buy_operation ON buys_vs_sells(buy_operation_id);
CREATE INDEX allocations_baseline ON buys_vs_sells(baseline_lot_id);
CREATE INDEX allocations_sell ON buys_vs_sells(sell_order_id);
DROP INDEX orders_account_identity;
DROP INDEX operations_account_identity;
DELETE FROM schema_migrations WHERE version=5;
COMMIT;
PRAGMA foreign_keys=ON;
SQL);
    check(Database::schemaVersion($pdo) === 4, 'migration fixture has the original schema-4 allocation structure');
    $previousAllocations = $pdo->query('SELECT * FROM buys_vs_sells ORDER BY id')->fetchAll();
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo = null;
    Backup::ensureDirectory($root.'/fault');
    copy($root.'/client.sqlite', $root.'/fault/client.sqlite');
    $pdo = raw($root.'/fault/client.sqlite');
    $pdo->exec("CREATE TRIGGER reject_v5 BEFORE INSERT ON schema_migrations WHEN NEW.version=5 BEGIN SELECT RAISE(ABORT,'migration fault'); END");
    $pdo = null;

    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    check(Database::schemaVersion($db->pdo()) === 5, 'schema 4 upgrades to schema 5');
    check($ledger->accountSnapshot($account) === $before, 'migration preserves financial rows and assigns their original inventory owner');
    check($db->integrityCheck()['ok'] && (int)$db->pdo()->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'schema-5 migration verifies and re-enables foreign keys');
    $backups = (new Backup($db, $root))->list('pre-migration');
    check(count($backups) === 1 && Backup::verifySnapshot($backups[0]['path'])['schema_version'] === 4, 'schema-4 recovery backup is published before migration');
    $pdo = raw($backups[0]['path']);
    check($pdo->query('SELECT * FROM buys_vs_sells ORDER BY id')->fetchAll() === $previousAllocations, 'recovery backup retains exact original allocation rows');
    $pdo = null;

    $legacySnapshot = $before;
    foreach ($legacySnapshot['buys_vs_sells'] as &$allocation) { unset($allocation['inventory_market_id']); }
    unset($allocation);
    $restored = new Database(':memory:');
    $restoredLedger = new Ledger($restored);
    $restoredLedger->restoreAccountSnapshot($legacySnapshot);
    check($restoredLedger->accountSnapshot($account) === $before && $restored->integrityCheck()['ok'], 'legacy hard-point snapshots restore with original inventory ownership');

    $otherMarket = $ledger->registerMarket($account, 'BTC/EUR', 'BTC', 'EUR');
    $otherSell = $ledger->recordOrder(['account_id' => $account,'market_id' => $otherMarket,'exchange_order_id' => 'sell-eur','side' => 'SELL','quantity' => '1','filled_quantity' => '1','price' => '25','status' => 'filled','occurred_at' => '2024-01-03T00:00:00Z']);
    $input = ['account_id' => $account,'market_id' => $otherMarket,'inventory_market_id' => $market,'allocation_key' => 'cross-market','buy_order_id' => $buy,'sell_order_id' => $otherSell,'quantity' => '1'];
    $allocationId = $ledger->allocate($input);
    check($db->integrityCheck()['ok'], 'same-account acquisitions may fulfill a SELL in another quote market');
    check($ledger->marketLots($market) === [] && $ledger->marketState($otherMarket)['unmatched_sell_quantity'] === '0', 'cross-market allocation debits the owning inventory and fulfills the actual SELL');
    check($ledger->marketLots($market, false)[0]['quantity'] === '2', 'raw acquisition view excludes prior SELL consumption');
    $revision = $db->revision($account);
    check($ledger->allocate($input) === $allocationId && $db->revision($account) === $revision, 'cross-market allocation replay preserves revisions and identity');
    $ledger->reconcileAccount($account, $ledger->expectedBalances($account));
    $checkpoint = $ledger->createCheckpoint($market);
    $q = $db->pdo()->prepare('SELECT reconstruction_refs_json FROM checkpoints WHERE id=?');
    $q->execute([$checkpoint]);
    $refs = json_decode($q->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    check(in_array($otherSell, $refs['order_ids'], true) && in_array($allocationId, $refs['allocation_ids'], true), 'inventory checkpoint includes the foreign-market SELL and allocation');
    $checkpoint = $ledger->createCheckpoint($otherMarket);
    $q->execute([$checkpoint]);
    $refs = json_decode($q->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    check(in_array($buy, $refs['order_ids'], true), 'SELL checkpoint includes its foreign-market acquisition source');

    $thirdMarket = $ledger->registerMarket($account, 'ETH/USD', 'ETH', 'USD');
    rejects(fn () => $ledger->allocate(array_replace($input, ['allocation_key' => 'wrong-currency','inventory_market_id' => $thirdMarket])), 'allocation rejects a different inventory currency');
    $lateBuy = $ledger->recordOrder(['account_id' => $account,'market_id' => $market,'exchange_order_id' => 'late-buy','side' => 'BUY','quantity' => '1','filled_quantity' => '1','price' => '10','status' => 'filled','occurred_at' => '2024-01-04T00:00:00Z']);
    rejects(fn () => $ledger->allocate(array_replace($input, ['allocation_key' => 'future-source','buy_order_id' => $lateBuy])), 'cross-market SELL cannot consume a later acquisition');
    rejects(fn () => $ledger->registerMarket($account, 'USD/EUR', 'USD', 'EUR'), 'legacy market registration retains its currency-role guard');
    check($ledger->registerMarket($account, 'USD/EUR', 'USD', 'EUR', null, ['allow_currency_roles' => true]) !== '', 'M3 may explicitly register a currency with both market roles');

    $foreignAccount = $ledger->createAccount('fixture', 'foreign', null, ['USD' => '100']);
    $foreignMarket = $ledger->registerMarket($foreignAccount, 'BTC/USD', 'BTC', 'USD');
    $foreignBuy = $ledger->recordOrder(['account_id' => $foreignAccount,'market_id' => $foreignMarket,'exchange_order_id' => 'foreign-buy','side' => 'BUY','quantity' => '1','filled_quantity' => '1','price' => '10','status' => 'filled']);
    $foreignDeposit = $ledger->recordOperation(['account_id' => $foreignAccount,'market_id' => $foreignMarket,'exchange_operation_id' => 'foreign-deposit','kind' => 'deposit','currency' => 'BTC','amount' => '1']);
    $allocation = $db->pdo()->query("SELECT * FROM buys_vs_sells WHERE allocation_key='cross-market'")->fetch();
    $invalid = array_replace($allocation, ['id' => 'invalid-order','allocation_key' => 'invalid-order','buy_order_id' => $foreignBuy]);
    rejects(fn () => insertAllocation($db->pdo(), $invalid), 'source-order foreign key prevents borrowing another account inventory');
    $invalid = array_replace($allocation, ['id' => 'invalid-operation','allocation_key' => 'invalid-operation','buy_order_id' => null,'buy_operation_id' => $foreignDeposit]);
    rejects(fn () => insertAllocation($db->pdo(), $invalid), 'source-operation foreign key prevents borrowing another account inventory');
    $invalid = array_replace($allocation, ['id' => 'invalid-owner','allocation_key' => 'invalid-owner','inventory_market_id' => $foreignMarket]);
    rejects(fn () => insertAllocation($db->pdo(), $invalid), 'inventory owner foreign key enforces the same account');
    $invalid = array_replace($allocation, ['id' => 'invalid-sell','allocation_key' => 'invalid-sell','sell_order_id' => $sell]);
    rejects(fn () => insertAllocation($db->pdo(), $invalid), 'SELL foreign key still enforces the actual execution market');

    rejects(fn () => new Database($root.'/fault/client.sqlite'), 'injected schema-5 migration failure stops the upgrade');
    $pdo = raw($root.'/fault/client.sqlite');
    check(Database::schemaVersion($pdo) === 4 && $pdo->query("SELECT name FROM sqlite_master WHERE name='buys_vs_sells_v5'")->fetchColumn() === false, 'failed schema-5 rebuild rolls back all schema changes');
    check($pdo->query('SELECT * FROM buys_vs_sells ORDER BY id')->fetchAll() === $previousAllocations, 'failed schema-5 migration preserves exact allocation data');
    check($pdo->query('PRAGMA foreign_key_check')->fetchAll() === [], 'failed schema-5 migration preserves original foreign keys');
    check(count(glob($root.'/fault/backups/pre-migration/*/manifest.json') ?: []) === 1, 'failed schema-5 migration retains its recovery backup');
    unset($ledger, $db);
    $db = new Database($root.'/client.sqlite');
    check(count((new Backup($db, $root))->list('pre-migration')) === 1, 'reopening schema 5 does not repeat migration or create another backup');
} finally {
    unset($pdo, $db, $ledger, $restored, $restoredLedger);
    Backup::removeTree($root);
}
echo json_encode(['suite' => 'm3-schema5','status' => 'passed','checks' => $checks], JSON_PRETTY_PRINT).PHP_EOL;
