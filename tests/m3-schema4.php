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
function raw(string $path): PDO
{
    return new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

$root = sys_get_temp_dir().'/tm-schema4-'.bin2hex(random_bytes(6));
Backup::ensureDirectory($root);
try {
    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    $id = $ledger->createAccount('fixture', 'v3', null, ['USD' => '1000']);
    $market = $ledger->registerMarket($id, 'BTC/USD', 'BTC', 'USD');
    $buy = $ledger->recordOrder(['account_id' => $id, 'market_id' => $market, 'exchange_order_id' => 'buy', 'side' => 'BUY', 'quantity' => '2', 'filled_quantity' => '2', 'price' => '10', 'status' => 'filled']);
    $sell = $ledger->recordOrder(['account_id' => $id, 'market_id' => $market, 'exchange_order_id' => 'sell', 'side' => 'SELL', 'quantity' => '1', 'filled_quantity' => '1', 'price' => '20', 'status' => 'filled']);
    $ledger->allocate(['account_id' => $id, 'market_id' => $market, 'allocation_key' => 'allocation', 'buy_order_id' => $buy, 'sell_order_id' => $sell, 'quantity' => '1']);
    $ledger->reconcileAccount($id, $ledger->expectedBalances($id));
    $ledger->createCheckpoint($market);
    $before = $ledger->accountSnapshot($id);
    unset($ledger, $db);

    // Exact pre-patch orders definition from GitHub M3 (schema 3).
    $pdo = raw($root.'/client.sqlite');
    $pdo->exec(<<<'SQL'
PRAGMA foreign_keys=OFF;
BEGIN IMMEDIATE;
DROP TABLE movement_reviews;
DROP TABLE transfer_labels;
CREATE TABLE buys_vs_sells_v3 (
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
INSERT INTO buys_vs_sells_v3 SELECT id,account_id,market_id,allocation_key,buy_order_id,buy_operation_id,baseline_lot_id,sell_order_id,quantity,created_at,buy_lot_key FROM buys_vs_sells;
DROP TABLE buys_vs_sells;
ALTER TABLE buys_vs_sells_v3 RENAME TO buys_vs_sells;
CREATE INDEX allocations_buy_order ON buys_vs_sells(buy_order_id);
CREATE INDEX allocations_buy_operation ON buys_vs_sells(buy_operation_id);
CREATE INDEX allocations_baseline ON buys_vs_sells(baseline_lot_id);
CREATE INDEX allocations_sell ON buys_vs_sells(sell_order_id);
DROP INDEX operations_account_identity;
CREATE TABLE orders_v3 (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL, market_id TEXT NOT NULL,
 exchange_order_id TEXT NOT NULL, side TEXT NOT NULL CHECK(side IN ('BUY','SELL')),
 order_type TEXT NOT NULL DEFAULT 'LIMIT' CHECK(order_type IN ('LIMIT','MARKET')),
 status TEXT NOT NULL CHECK(status IN ('open','partially_filled','filled','cancelled','rejected','expired')),
 quantity TEXT NOT NULL, filled_quantity TEXT NOT NULL, remaining_quantity TEXT NOT NULL,
 price TEXT NOT NULL, average_price TEXT NOT NULL, cost_amount TEXT NOT NULL,
 fee_amount TEXT NOT NULL DEFAULT '0', fee_currency TEXT,
 occurred_at TEXT NOT NULL, updated_at TEXT NOT NULL, metadata_json TEXT NOT NULL DEFAULT '{}',
 observations_json TEXT NOT NULL DEFAULT '[]',
 UNIQUE(account_id,exchange_order_id), UNIQUE(id,account_id,market_id),
 FOREIGN KEY(market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE
);
INSERT INTO orders_v3 SELECT * FROM orders;
DROP TABLE orders;
ALTER TABLE orders_v3 RENAME TO orders;
CREATE INDEX orders_market_status ON orders(market_id,status);
DELETE FROM schema_migrations WHERE version>=4;
COMMIT;
PRAGMA foreign_keys=ON;
SQL);
    check(Database::schemaVersion($pdo) === 3, 'migration fixture uses the previous schema');
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo = null;
    Backup::ensureDirectory($root.'/fault');
    copy($root.'/client.sqlite', $root.'/fault/client.sqlite');
    $pdo = raw($root.'/fault/client.sqlite');
    $pdo->exec("CREATE TRIGGER reject_v4 BEFORE INSERT ON schema_migrations WHEN NEW.version=4 BEGIN SELECT RAISE(ABORT,'migration fault'); END");
    $pdo = null;

    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    check(Database::schemaVersion($db->pdo()) === Database::SCHEMA_VERSION, 'existing schema 3 upgrades through schema 4 to the current schema');
    check($ledger->accountSnapshot($id) === $before, 'migration preserves every financial row, ID and observation');
    check($db->integrityCheck()['ok'] && (int)$db->pdo()->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'migration validates and re-enables foreign keys');
    $backups = (new Backup($db, $root))->list('pre-migration');
    check(count($backups) === 1 && Backup::verifySnapshot($backups[0]['path'])['schema_version'] === 3, 'verified schema-3 backup exists before migration');
    $backupPdo = raw($backups[0]['path']);
    check($backupPdo->query('SELECT * FROM orders ORDER BY id')->fetchAll() === $db->pdo()->query('SELECT * FROM orders ORDER BY id')->fetchAll(), 'pre-migration backup retains exact order data');
    $backupPdo = null;
    unset($ledger, $db);
    $db = new Database($root.'/client.sqlite');
    check(count((new Backup($db, $root))->list('pre-migration')) === 1, 'reopening the current schema does not repeat the migration');

    $rejected = false;
    try { new Database($root.'/fault/client.sqlite'); } catch (Throwable) { $rejected = true; }
    check($rejected, 'injected failure stops the migration');
    $pdo = raw($root.'/fault/client.sqlite');
    check(Database::schemaVersion($pdo) === 3 && $pdo->query("SELECT name FROM sqlite_master WHERE name='orders_v4'")->fetchColumn() === false, 'failed rebuild rolls back the entire schema change');
    check($pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll() === $db->pdo()->query('SELECT * FROM orders ORDER BY id')->fetchAll(), 'failed migration preserves financial rows');
    check($pdo->query('PRAGMA foreign_key_check')->fetchAll() === [], 'failed migration preserves allocation references');
    check(count(glob($root.'/fault/backups/pre-migration/*/manifest.json') ?: []) === 1, 'failed migration retains its recovery backup');
} finally {
    unset($pdo, $db, $ledger);
    Backup::removeTree($root);
}
echo json_encode(['suite' => 'm3-schema4', 'status' => 'passed', 'checks' => $checks], JSON_PRETTY_PRINT).PHP_EOL;
