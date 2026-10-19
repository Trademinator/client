<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Backup, Database, Ledger};
use Trademinator\Client\M3\{Normalize, TransferLabels, TransferReviews};

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $callback, string $message): void
{
    $failed = false;
    try { $callback(); } catch (Throwable) { $failed = true; }
    check($failed, $message);
}
function raw(string $path): PDO
{
    return new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

$root = sys_get_temp_dir().'/tm-schema7-'.bin2hex(random_bytes(6));
Backup::ensureDirectory($root);
try {
    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    $account = $ledger->createAccount('bitso', 'Existing schema-six account', null, ['USD' => '1000']);
    $market = $ledger->registerMarket($account, 'BTC/USD', 'BTC', 'USD');
    $ledger->recordOrder(['account_id' => $account, 'market_id' => $market,
        'exchange_order_id' => 'original-buy', 'side' => 'BUY', 'quantity' => '2',
        'filled_quantity' => '2', 'price' => '10.123456789012345678901234',
        'status' => 'filled', 'occurred_at' => '2024-01-01T00:00:00Z']);
    $ledger->recordOperation(['account_id' => $account, 'exchange_operation_id' => 'original-deposit',
        'kind' => 'deposit', 'currency' => 'USD', 'amount' => '0.000000000000000000000001',
        'occurred_at' => '2024-01-02T00:00:00Z']);
    $ledger->reconcileAccount($account, $ledger->expectedBalances($account));
    $ledger->createCheckpoint($market);

    // Existing user-transfer proposals are distinct from the new unresolved-movement reviews.
    $operations = []; $labels = [];
    for ($index = 0; $index < 32; ++$index) {
        $kind = $index % 2 === 0 ? 'deposit' : 'withdrawal';
        $row = ['id' => 'existing-transfer-'.$index, 'status' => 'ok', 'currency' => 'USD',
            'amount' => (string)($index + 1), 'timestamp' => 1704240000000 + $index,
            'fee' => ['currency' => 'USD', 'cost' => '0'],
            'info' => ['method' => 'bt', 'network' => 'bt', 'protocol' => 'bt', 'integration' => 'bt']];
        $operation = Normalize::operation($row, $kind);
        $operations[] = $operation;
        $labels[$operation['id']] = TransferLabels::suggest('bitso', $row, $kind);
    }
    (new TransferReviews($db))->observe($account, ['complete' => true,
        'operations' => $operations, 'transfer_labels' => $labels]);
    $before = $ledger->accountSnapshot($account);
    $previousLabels = $db->pdo()->query('SELECT * FROM transfer_labels ORDER BY id')->fetchAll();
    unset($ledger, $db);

    $pdo = raw($root.'/client.sqlite');
    $pdo->exec('DROP TABLE movement_reviews');
    $pdo->exec('DELETE FROM schema_migrations WHERE version>=7');
    $previousMigrations = $pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll();
    check(Database::schemaVersion($pdo) === 6 && count($previousLabels) === 32
        && $pdo->query("SELECT name FROM sqlite_master WHERE name='movement_reviews'")->fetchColumn() === false,
        'migration starts from schema 6 with 32 existing transfer reviews');
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $pdo = null;
    Backup::ensureDirectory($root.'/fault');
    copy($root.'/client.sqlite', $root.'/fault/client.sqlite');
    $pdo = raw($root.'/fault/client.sqlite');
    $pdo->exec("CREATE TRIGGER reject_v7 BEFORE INSERT ON schema_migrations WHEN NEW.version=7 BEGIN SELECT RAISE(ABORT,'migration fault'); END");
    $pdo = null;

    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    check(Database::schemaVersion($db->pdo()) === Database::SCHEMA_VERSION,
        'existing schema 6 upgrades to the current schema');
    check($ledger->accountSnapshot($account) === $before,
        'schema-7 migration preserves exact financial amounts, records, checkpoints and revisions');
    check($db->pdo()->query('SELECT * FROM transfer_labels ORDER BY id')->fetchAll() === $previousLabels,
        'schema-7 migration preserves all 32 pending transfer labels and their audit fields');
    check($db->integrityCheck()['ok'] && (int)$db->pdo()->query('PRAGMA foreign_keys')->fetchColumn() === 1
        && (int)$db->pdo()->query('SELECT COUNT(*) FROM movement_reviews')->fetchColumn() === 0,
        'the new review table starts empty and existing foreign keys remain valid');
    check($db->pdo()->query('SELECT * FROM schema_migrations WHERE version<7 ORDER BY version')->fetchAll() === $previousMigrations,
        'schema-7 migration retains the original migration timestamps');
    $backups = (new Backup($db, $root))->list('pre-migration');
    check(count($backups) === 1 && Backup::verifySnapshot($backups[0]['path'])['schema_version'] === 6,
        'a verified schema-6 recovery backup precedes the schema-7 migration');
    $pdo = raw($backups[0]['path']);
    check($pdo->query('SELECT * FROM transfer_labels ORDER BY id')->fetchAll() === $previousLabels
        && $pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll() === $before['orders']
        && $pdo->query('SELECT * FROM operations ORDER BY id')->fetchAll() === $before['operations'],
        'the recovery backup retains exact transfer-review and financial rows');
    $pdo = null;
    unset($ledger, $db);
    $db = new Database($root.'/client.sqlite');
    check(count((new Backup($db, $root))->list('pre-migration')) === 1
        && $db->pdo()->query('SELECT * FROM transfer_labels ORDER BY id')->fetchAll() === $previousLabels,
        'reopening does not repeat the migration or rewrite existing reviews');

    rejects(fn () => new Database($root.'/fault/client.sqlite'),
        'an injected schema-7 migration failure stops the upgrade');
    $pdo = raw($root.'/fault/client.sqlite');
    check(Database::schemaVersion($pdo) === 6
        && $pdo->query("SELECT name FROM sqlite_master WHERE name='movement_reviews'")->fetchColumn() === false,
        'failed schema-7 migration rolls back the new table and migration version');
    check($pdo->query('SELECT * FROM transfer_labels ORDER BY id')->fetchAll() === $previousLabels
        && $pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll() === $before['orders']
        && $pdo->query('SELECT * FROM operations ORDER BY id')->fetchAll() === $before['operations']
        && $pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll() === $previousMigrations,
        'failed migration preserves existing financial evidence, review labels and migration history');
    check($pdo->query('PRAGMA foreign_key_check')->fetchAll() === []
        && count(glob($root.'/fault/backups/pre-migration/*/manifest.json') ?: []) === 1,
        'failed migration retains valid foreign keys and its recovery backup');
} finally {
    unset($pdo, $db, $ledger);
    gc_collect_cycles(); Backup::removeTree($root);
}
echo json_encode(['suite' => 'm3-schema7', 'status' => 'passed', 'checks' => $checks,
    'network_requests' => 0], JSON_PRETTY_PRINT).PHP_EOL;
