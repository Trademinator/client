<?php

declare(strict_types=1);

namespace Trademinator\Client\M1;

use PDO;
use RuntimeException;
use Throwable;

/** SQLite is the durable authority; all financial writers serialize with BEGIN IMMEDIATE. */
final class Database
{
    public const SCHEMA_VERSION = 7;

    private PDO $connection;
    private int $depth = 0;

    public function __construct(private readonly string $filename)
    {
        if (!extension_loaded('pdo_sqlite') || !extension_loaded('bcmath')) {
            throw new RuntimeException('M1 requires PDO SQLite and BCMath.');
        }
        $directory = dirname($filename);
        if ($filename !== ':memory:' && is_file($directory . '/backups/restore-pending.json')) {
            throw new RuntimeException('Backup restore interrupted; run recover-backup --approve=yes.');
        }
        if ($filename !== ':memory:' && !is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create database directory.');
        }
        $this->connection = new PDO('sqlite:' . $filename, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->assertSupportedSchema();
        $this->connection->exec('PRAGMA foreign_keys = ON');
        $this->connection->exec('PRAGMA busy_timeout = 5000');
        $this->connection->exec('PRAGMA journal_mode = WAL');
        $this->connection->exec('PRAGMA synchronous = FULL');
        $this->migrate();
        if ($filename !== ':memory:') {
            @chmod($filename, 0600);
        }
    }

    public function pdo(): PDO { return $this->connection; }
    public function path(): string { return $this->filename; }
    public function inTransaction(): bool { return $this->depth > 0; }

    private function assertSupportedSchema(): void
    {
        self::schemaVersion($this->connection);
    }

    /** Validate snapshots without opening a Database and accidentally migrating them. */
    public static function schemaVersion(PDO $connection): int
    {
        $tables = $connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        if ($tables === []) { return 0; }
        if (!in_array('schema_migrations', $tables, true)) { throw new RuntimeException('This is not an M1 database. Use a new M1 data directory; M0 data is not migrated implicitly.'); }
        $version = (int) $connection->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn();
        if ($version > self::SCHEMA_VERSION) { throw new RuntimeException('Database schema is newer than this M3 release.'); }
        $expected = ['buys_vs_sells','checkpoints','exchange_accounts','markets','operations','orders','schema_migrations','signal_decisions'];
        if ($version >= 6) { $expected[] = 'transfer_labels'; }
        if ($version >= 7) { $expected[] = 'movement_reviews'; sort($expected); }
        if (($version >= 1 && $tables !== $expected) || ($version === 0 && $tables !== ['schema_migrations'])) { throw new RuntimeException('Unrecognized or incomplete client database schema.'); }
        if ($version >= 2) {
            $columns = array_column($connection->query('PRAGMA table_info(markets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (array_diff(['state', 'subscription_id', 'checks_per_candle'], $columns) !== []) {
                throw new RuntimeException('Incomplete M2 market schema.');
            }
        }
        if ($version >= 3) {
            $columns = array_column($connection->query('PRAGMA table_info(buys_vs_sells)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('buy_lot_key', $columns, true)) { throw new RuntimeException('Incomplete M3 lot schema.'); }
        }
        if ($version >= 4) {
            $columns = array_column($connection->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), null, 'name');
            if (($columns['quantity']['notnull'] ?? 1) !== 0 || ($columns['remaining_quantity']['notnull'] ?? 1) !== 0) {
                throw new RuntimeException('Incomplete M3 historical-order schema.');
            }
        }
        if ($version >= 5) {
            $columns = array_column($connection->query('PRAGMA table_info(buys_vs_sells)')->fetchAll(PDO::FETCH_ASSOC), null, 'name');
            if (($columns['inventory_market_id']['notnull'] ?? 0) !== 1) {
                throw new RuntimeException('Incomplete M3 currency-inventory schema.');
            }
        }
        if ($version >= 6) {
            $columns = array_column($connection->query('PRAGMA table_info(transfer_labels)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (array_diff(['account_id','exchange_operation_id','financial_fingerprint','label','status','evidence_json'], $columns) !== []) {
                throw new RuntimeException('Incomplete M3 transfer-review schema.');
            }
        }
        if ($version >= 7) {
            $columns = array_column($connection->query('PRAGMA table_info(movement_reviews)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (array_diff(['account_id','source_kind','source_ref','currency','amount','at','movement_kind','direction',
                'decision','label','status','reason','evidence_json'], $columns) !== []) {
                throw new RuntimeException('Incomplete M3 unresolved-movement review schema.');
            }
        }
        return $version;
    }

    public function transaction(callable $callback): mixed
    {
        $level = $this->depth++;
        try {
            $this->connection->exec($level === 0 ? 'BEGIN IMMEDIATE' : 'SAVEPOINT m1_' . $level);
            try {
                $result = $callback($this);
                $this->connection->exec($level === 0 ? 'COMMIT' : 'RELEASE SAVEPOINT m1_' . $level);
                return $result;
            } catch (Throwable $error) {
                $this->connection->exec($level === 0 ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT m1_' . $level);
                if ($level !== 0) {
                    $this->connection->exec('RELEASE SAVEPOINT m1_' . $level);
                }
                throw $error;
            }
        } finally {
            --$this->depth;
        }
    }

    public function migrate(): void
    {
        $this->assertSupportedSchema();
        $existing = $this->connection->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn();
        $version = $existing ? (int) $this->connection->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn() : 0;
        if ($version === self::SCHEMA_VERSION) { return; }
        if ($version > 0 && $this->filename !== ':memory:') {
            // The regular manifest makes this verified M1 snapshot discoverable by recover-backup.
            // Publication must succeed before any schema or financial record is changed.
            (new Backup($this, dirname($this->filename)))->preMigration();
        }
        // SQLite's documented table-rebuild procedure: disable FK enforcement BEFORE
        // BEGIN, retain every row/ID, validate all foreign keys before committing.
        $this->connection->exec('PRAGMA foreign_keys = OFF');
        try {
        $this->transaction(function (): void {
            $this->connection->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL
);
SQL);
            $version = (int) $this->connection->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn();
            if ($version > self::SCHEMA_VERSION) { throw new RuntimeException('Database schema is newer than this M3 release.'); }
            if ($version === self::SCHEMA_VERSION) { return; }
            if ($version === 0) {
            $this->connection->exec(<<<'SQL'
CREATE TABLE exchange_accounts (
 id TEXT PRIMARY KEY, exchange TEXT NOT NULL, label TEXT NOT NULL,
 revision INTEGER NOT NULL DEFAULT 0 CHECK(revision >= 0),
 opening_balances_json TEXT NOT NULL DEFAULT '{}', wallet_balances_json TEXT NOT NULL DEFAULT '{}',
 reconciled_revision INTEGER, reconciled_at TEXT, hard_t0_json TEXT,
 baseline_completed_trades INTEGER NOT NULL DEFAULT 0 CHECK(baseline_completed_trades >= 0),
 created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE markets (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL REFERENCES exchange_accounts(id) ON DELETE CASCADE,
 symbol TEXT NOT NULL, base_currency TEXT NOT NULL, quote_currency TEXT NOT NULL,
 min_quantity TEXT NOT NULL DEFAULT '0', min_notional TEXT NOT NULL DEFAULT '0', reference_price TEXT NOT NULL DEFAULT '0',
 baseline_lots_json TEXT NOT NULL DEFAULT '[]', activity_revision INTEGER NOT NULL DEFAULT 0,
 created_at TEXT NOT NULL, UNIQUE(account_id,symbol), UNIQUE(id,account_id)
);
CREATE TABLE orders (
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
CREATE TABLE operations (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL REFERENCES exchange_accounts(id) ON DELETE CASCADE,
 market_id TEXT, exchange_operation_id TEXT NOT NULL,
 kind TEXT NOT NULL CHECK(kind IN ('deposit','withdrawal')), currency TEXT NOT NULL,
 amount TEXT NOT NULL, price_basis TEXT, fee_amount TEXT NOT NULL DEFAULT '0', fee_currency TEXT,
 lot_consumptions_json TEXT NOT NULL DEFAULT '{}', occurred_at TEXT NOT NULL, metadata_json TEXT NOT NULL DEFAULT '{}',
 UNIQUE(account_id,exchange_operation_id), UNIQUE(id,account_id,market_id),
 FOREIGN KEY(market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE
);
CREATE TABLE buys_vs_sells (
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
CREATE TABLE checkpoints (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL, market_id TEXT NOT NULL,
 activity_revision INTEGER NOT NULL, account_revision INTEGER NOT NULL,
 state_json TEXT NOT NULL, wallet_balances_json TEXT NOT NULL, reconstruction_refs_json TEXT NOT NULL,
 created_at TEXT NOT NULL, UNIQUE(market_id,activity_revision),
 FOREIGN KEY(market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE
);
CREATE TABLE signal_decisions (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL, market_id TEXT NOT NULL,
 decision_key TEXT NOT NULL, signal TEXT NOT NULL CHECK(signal IN ('BUY','HOLD','SELL')),
 reason TEXT NOT NULL, payload_json TEXT NOT NULL DEFAULT '{}', occurred_at TEXT NOT NULL,
 UNIQUE(account_id,decision_key),
 FOREIGN KEY(market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE
);
CREATE INDEX orders_market_status ON orders(market_id,status);
CREATE INDEX operations_market ON operations(market_id);
CREATE INDEX allocations_buy_order ON buys_vs_sells(buy_order_id);
CREATE INDEX allocations_buy_operation ON buys_vs_sells(buy_operation_id);
CREATE INDEX allocations_baseline ON buys_vs_sells(baseline_lot_id);
CREATE INDEX allocations_sell ON buys_vs_sells(sell_order_id);
CREATE INDEX checkpoints_account ON checkpoints(account_id);
SQL);
            $statement = $this->connection->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(1,?)');
            $statement->execute([gmdate('c')]);
            }
            if ($version < 2) {
            // Additive migration only: no financial table is rebuilt and no M1 value is rewritten.
            $this->connection->exec(<<<'SQL'
ALTER TABLE markets ADD COLUMN state TEXT NOT NULL DEFAULT 'STOPPED' CHECK(state IN ('ACTIVE','STOPPING','STOPPED'));
ALTER TABLE markets ADD COLUMN subscription_id TEXT;
ALTER TABLE markets ADD COLUMN checks_per_candle INTEGER CHECK(checks_per_candle IS NULL OR (typeof(checks_per_candle)='integer' AND checks_per_candle BETWEEN 1 AND 59));
SQL);
            $statement = $this->connection->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(2,?)');
            $statement->execute([gmdate('c')]);
            }
            if ($version < 3) {
                $this->connection->exec('ALTER TABLE buys_vs_sells ADD COLUMN buy_lot_key TEXT');
                $this->connection->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(3,?)')->execute([gmdate('c')]);
            }
            if ($version < 4) {
                $this->connection->exec(<<<'SQL'
CREATE TABLE orders_v4 (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL, market_id TEXT NOT NULL,
 exchange_order_id TEXT NOT NULL, side TEXT NOT NULL CHECK(side IN ('BUY','SELL')),
 order_type TEXT NOT NULL DEFAULT 'LIMIT' CHECK(order_type IN ('LIMIT','MARKET','UNKNOWN')),
 status TEXT NOT NULL CHECK(status IN ('open','partially_filled','filled','cancelled','rejected','expired','history_only')),
 quantity TEXT, filled_quantity TEXT NOT NULL, remaining_quantity TEXT,
 price TEXT NOT NULL, average_price TEXT NOT NULL, cost_amount TEXT NOT NULL,
 fee_amount TEXT NOT NULL DEFAULT '0', fee_currency TEXT,
 occurred_at TEXT NOT NULL, updated_at TEXT NOT NULL, metadata_json TEXT NOT NULL DEFAULT '{}',
 observations_json TEXT NOT NULL DEFAULT '[]',
 CHECK((status='history_only' AND quantity IS NULL AND remaining_quantity IS NULL AND order_type='UNKNOWN')
    OR (status<>'history_only' AND quantity IS NOT NULL AND remaining_quantity IS NOT NULL AND order_type<>'UNKNOWN')),
 UNIQUE(account_id,exchange_order_id), UNIQUE(id,account_id,market_id),
 FOREIGN KEY(market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE
);
INSERT INTO orders_v4 SELECT * FROM orders;
DROP TABLE orders;
ALTER TABLE orders_v4 RENAME TO orders;
CREATE INDEX orders_market_status ON orders(market_id,status);
SQL);
                $this->connection->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(4,?)')->execute([gmdate('c')]);
            }
            if ($version < 5) {
                $this->connection->exec(<<<'SQL'
CREATE UNIQUE INDEX orders_account_identity ON orders(id,account_id);
CREATE UNIQUE INDEX operations_account_identity ON operations(id,account_id);
CREATE TABLE buys_vs_sells_v5 (
 id TEXT PRIMARY KEY, account_id TEXT NOT NULL, market_id TEXT NOT NULL,
 allocation_key TEXT NOT NULL, buy_order_id TEXT, buy_operation_id TEXT, baseline_lot_id TEXT,
 sell_order_id TEXT NOT NULL, quantity TEXT NOT NULL, created_at TEXT NOT NULL,
 buy_lot_key TEXT, inventory_market_id TEXT NOT NULL,
 CHECK((buy_order_id IS NOT NULL)+(buy_operation_id IS NOT NULL)+(baseline_lot_id IS NOT NULL)=1),
 UNIQUE(account_id,allocation_key),
 FOREIGN KEY(market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE,
 FOREIGN KEY(inventory_market_id,account_id) REFERENCES markets(id,account_id) ON DELETE CASCADE,
 FOREIGN KEY(buy_order_id,account_id) REFERENCES orders(id,account_id),
 FOREIGN KEY(buy_operation_id,account_id) REFERENCES operations(id,account_id),
 FOREIGN KEY(sell_order_id,account_id,market_id) REFERENCES orders(id,account_id,market_id)
);
INSERT INTO buys_vs_sells_v5 SELECT buys_vs_sells.*,market_id FROM buys_vs_sells;
DROP TABLE buys_vs_sells;
ALTER TABLE buys_vs_sells_v5 RENAME TO buys_vs_sells;
CREATE INDEX allocations_buy_order ON buys_vs_sells(buy_order_id);
CREATE INDEX allocations_buy_operation ON buys_vs_sells(buy_operation_id);
CREATE INDEX allocations_baseline ON buys_vs_sells(baseline_lot_id);
CREATE INDEX allocations_sell ON buys_vs_sells(sell_order_id);
CREATE INDEX allocations_inventory_market ON buys_vs_sells(inventory_market_id);
SQL);
                $this->connection->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(5,?)')->execute([gmdate('c')]);
            }
            if ($version < 6) {
                // Review annotations survive rolled-back ingestion and financial archival.
                // They never participate in wallet arithmetic or acquisition lot evidence.
                $this->connection->exec(<<<'SQL'
CREATE TABLE transfer_labels (
 id TEXT PRIMARY KEY,
 account_id TEXT NOT NULL REFERENCES exchange_accounts(id) ON DELETE CASCADE,
 exchange_operation_id TEXT NOT NULL,
 kind TEXT NOT NULL CHECK(kind IN ('deposit','withdrawal')),
 currency TEXT NOT NULL, amount TEXT NOT NULL, occurred_at TEXT NOT NULL,
 financial_fingerprint TEXT NOT NULL,
 label TEXT NOT NULL CHECK(label IN ('user_deposit','user_withdrawal')),
 status TEXT NOT NULL CHECK(status IN ('pending_confirmation','confirmed','explained','superseded')),
 reason TEXT NOT NULL, evidence_json TEXT NOT NULL,
 first_observed_at TEXT NOT NULL, updated_at TEXT NOT NULL,
 UNIQUE(account_id,exchange_operation_id)
);
CREATE INDEX transfer_labels_pending ON transfer_labels(account_id,status,occurred_at,id);
SQL);
                $this->connection->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(6,?)')->execute([gmdate('c')]);
            }
            if ($version < 7) {
                // Unexplained funding is review evidence, never an invented deposit.
                $this->connection->exec(<<<'SQL'
CREATE TABLE movement_reviews (
 id TEXT PRIMARY KEY,
 account_id TEXT NOT NULL REFERENCES exchange_accounts(id) ON DELETE CASCADE,
 source_kind TEXT NOT NULL CHECK(source_kind IN ('inventory_shortfall','ledger_movement')), source_ref TEXT NOT NULL,
 currency TEXT NOT NULL, amount TEXT NOT NULL, at TEXT NOT NULL,
 movement_kind TEXT, direction TEXT CHECK(direction IN ('in','out')),
 decision TEXT NOT NULL CHECK(decision IN ('soft_label','reject')),
 label TEXT CHECK(label='user_deposit'),
 status TEXT NOT NULL CHECK(status IN ('pending_confirmation','rejected','confirmed','explained','superseded')),
 reason TEXT NOT NULL, evidence_json TEXT NOT NULL,
 first_observed_at TEXT NOT NULL, updated_at TEXT NOT NULL,
 CHECK((decision='soft_label' AND label IS NOT NULL AND label='user_deposit') OR (decision='reject' AND label IS NULL)),
 UNIQUE(account_id,source_kind,source_ref,currency)
);
CREATE INDEX movement_reviews_pending ON movement_reviews(account_id,status,at,id);
SQL);
                $this->connection->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(7,?)')->execute([gmdate('c')]);
            }
            if (!$this->integrityCheck()['ok']) { throw new RuntimeException('M3 migration failed database integrity checks.'); }
        });
        } finally {
            $this->connection->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function revision(string $accountId): int
    {
        $query = $this->connection->prepare('SELECT revision FROM exchange_accounts WHERE id=?');
        $query->execute([$accountId]);
        $revision = $query->fetchColumn();
        if ($revision === false) { throw new RuntimeException('Unknown exchange account.'); }
        return (int) $revision;
    }

    public function integrityCheck(): array
    {
        $integrity = $this->connection->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        $foreignKeys = $this->connection->query('PRAGMA foreign_key_check')->fetchAll();
        return ['ok' => $integrity === ['ok'] && $foreignKeys === [], 'integrity' => $integrity, 'foreign_keys' => $foreignKeys];
    }
}
