<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

use PDO;

final class StateStore
{
    private PDO $db;

    public function __construct(string $path, private int $retention)
    {
        $this->db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=3000');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS probe_state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS probe_quotes (id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id TEXT NOT NULL, received_at_ms INTEGER NOT NULL, transport TEXT NOT NULL, payload TEXT NOT NULL)');
    }

    public function set(string $name, array $value): void
    {
        $statement = $this->db->prepare('INSERT INTO probe_state(name, value) VALUES (?, ?)
            ON CONFLICT(name) DO UPDATE SET value = excluded.value');
        $statement->execute([$name, json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    public function get(string $name): ?array
    {
        $statement = $this->db->prepare('SELECT value FROM probe_state WHERE name = ?');
        $statement->execute([$name]);
        $value = $statement->fetchColumn();
        return $value === false ? null : json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }

    public function append(string $runId, string $transport, array $quote): void
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare('INSERT INTO probe_quotes(run_id, received_at_ms, transport, payload) VALUES (?, ?, ?, ?)');
            $statement->execute([$runId, (int) floor(microtime(true) * 1000), $transport, json_encode($quote, JSON_THROW_ON_ERROR)]);
            $prune = $this->db->prepare('DELETE FROM probe_quotes WHERE id <= (SELECT id FROM probe_quotes ORDER BY id DESC LIMIT 1 OFFSET ?)');
            $prune->bindValue(1, $this->retention, PDO::PARAM_INT);
            $prune->execute();
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function count(): int { return (int) $this->db->query('SELECT COUNT(*) FROM probe_quotes')->fetchColumn(); }

    public function latest(): ?array
    {
        $value = $this->db->query('SELECT payload FROM probe_quotes ORDER BY id DESC LIMIT 1')->fetchColumn();
        return $value === false ? null : json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }

    public function checkpoint(): void { $this->db->exec('PRAGMA wal_checkpoint(TRUNCATE)'); }
}
