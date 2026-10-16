<?php
declare(strict_types=1);
namespace Trademinator\Client\M2;

use RuntimeException;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;

/** Durable operator choices only. Catalogs, periods, warnings and timers stay in RAM. */
final class Markets
{
    public function __construct(private readonly Database $database) {}

    public function all(): array
    {
        return $this->database->pdo()->query('SELECT m.id,m.account_id,a.exchange,m.symbol,m.state,m.subscription_id,m.checks_per_candle FROM markets m JOIN exchange_accounts a ON a.id=m.account_id ORDER BY a.exchange,m.account_id,m.symbol')->fetchAll();
    }

    public function get(string $id): array
    {
        $statement = $this->database->pdo()->prepare('SELECT m.id,m.account_id,a.exchange,m.symbol,m.state,m.subscription_id,m.checks_per_candle FROM markets m JOIN exchange_accounts a ON a.id=m.account_id WHERE m.id=?');
        $statement->execute([$id]);
        return $statement->fetch() ?: throw new RuntimeException('Unknown local market.');
    }

    public function activate(string $accountId, array $subscription, ?int $checks = null): array
    {
        if (!($subscription['is_active'] ?? false)) { throw new RuntimeException('This subscription is inactive.'); }
        if ($checks !== null) { Scheduler::checks($checks); }
        return $this->database->transaction(function () use ($accountId, $subscription, $checks): array {
            $statement = $this->database->pdo()->prepare('SELECT exchange FROM exchange_accounts WHERE id=?');
            $statement->execute([$accountId]);
            $exchange = $statement->fetchColumn();
            if ($exchange === false) { throw new RuntimeException('Unknown local exchange account.'); }
            if ($exchange !== $subscription['exchange']) { throw new RuntimeException('Subscription exchange does not match the local account.'); }
            $parts = explode('/', $subscription['symbol']);
            if (count($parts) !== 2) { throw new RuntimeException('Unsupported subscription symbol.'); }
            $id = (new Ledger($this->database))->registerMarket($accountId, $subscription['symbol'], $parts[0], $parts[1]);
            $statement = $this->database->pdo()->prepare("UPDATE markets SET state='ACTIVE',subscription_id=?,checks_per_candle=COALESCE(?,checks_per_candle) WHERE id=?");
            $statement->execute([$subscription['subscription_id'], $checks, $id]);
            return $this->get($id);
        });
    }

    public function stop(string $id): array
    {
        return $this->database->transaction(function () use ($id): array {
            $market = $this->get($id);
            $state = (new Ledger($this->database))->openOrdersCount($market['account_id'], $id) > 0 ? 'STOPPING' : 'STOPPED';
            $this->database->pdo()->prepare('UPDATE markets SET state=? WHERE id=?')->execute([$state, $id]);
            return $this->get($id);
        });
    }

    public function setChecks(string $id, ?int $checks): array
    {
        $this->get($id);
        if ($checks !== null) { Scheduler::checks($checks); }
        $this->database->pdo()->prepare('UPDATE markets SET checks_per_candle=? WHERE id=?')->execute([$checks, $id]);
        return $this->get($id);
    }

    public function settleStopping(): void
    {
        // Only authoritative ledger status releases a market. Elapsed time never cancels orders.
        $this->database->transaction(function (): void {
            $this->database->pdo()->exec("UPDATE markets SET state='STOPPING' WHERE state='STOPPED' AND EXISTS (SELECT 1 FROM orders WHERE orders.market_id=markets.id AND status IN ('open','partially_filled'))");
            $this->database->pdo()->exec("UPDATE markets SET state='STOPPED' WHERE state='STOPPING' AND NOT EXISTS (SELECT 1 FROM orders WHERE orders.market_id=markets.id AND status IN ('open','partially_filled'))");
        });
    }
}
