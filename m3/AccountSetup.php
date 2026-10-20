<?php
declare(strict_types=1);
namespace Trademinator\Client\M3;

use RuntimeException;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;
use Trademinator\Client\M1\Paths;

/** Local identities are generated once; credentials themselves are never persisted. */
final class AccountSetup
{
    public static function resolve(Database $db, array $settings, ?string $dataDir): array
    {
        if (array_filter($settings['accounts'], static fn(array $account): bool => !isset($account['account_id'])) === []) { return $settings; }
        $path = $dataDir === null ? null : $dataDir.'/exchange-account-bindings.json';
        $bindings = $path !== null && is_file($path) ? json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
        if (!is_array($bindings)) { throw new RuntimeException('Invalid automatic exchange account bindings.'); }
        $changed = false; $used = [];
        foreach ($settings['accounts'] as &$account) {
            if (isset($account['account_id'])) { $used[$account['account_id']] = true; continue; }
            $exchange = $account['exchange'];
            $refs = array_intersect_key($account, array_flip(['exchange','api_key_env','api_key_file','uid_env','uid_file']));
            ksort($refs); $binding = hash('sha256', json_encode($refs, JSON_THROW_ON_ERROR));
            $q = $db->pdo()->prepare('SELECT id,label FROM exchange_accounts WHERE exchange=? ORDER BY created_at,id');
            $q->execute([$exchange]); $rows = $q->fetchAll();
            $label = 'automatic:'.$exchange.':'.substr($binding, 0, 16);
            $matches = array_values(array_filter($rows, static fn(array $row): bool => $row['label'] === $label));
            $id = $bindings[$binding] ?? ($matches[0]['id'] ?? (count($rows) === 1 ? $rows[0]['id'] : null));
            if ($id !== null && !in_array($id, array_column($rows, 'id'), true)) { throw new RuntimeException('Automatic account binding no longer matches the local '.$exchange.' ledger.'); }
            if ($id === null && count($rows) > 1) { throw new RuntimeException('Several local '.$exchange.' accounts exist and credentials have no saved binding. Existing account_id configurations remain supported for this migration.'); }
            $id ??= (new Ledger($db))->createAccount($exchange, $label);
            if (isset($used[$id])) { throw new RuntimeException('Two credential entries resolve to the same local exchange account.'); }
            $used[$id] = true; $account['account_id'] = $id;
            if (($bindings[$binding] ?? null) !== $id) { $bindings[$binding] = $id; $changed = true; }
        } unset($account);
        if ($changed && $path !== null) { Paths::atomicJson($path, $bindings); }
        return $settings;
    }
}
