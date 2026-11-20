<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use PDO;
use RuntimeException;
use Throwable;

/** An event and its resulting state commit together; real client.sqlite is never opened. */
final class Store
{
    public const MAX_BATCH_EVENTS = 50;
    private PDO $db;
    private mixed $writerLock;
    private bool $writing = false;
    public function __construct(private readonly string $directory)
    {
        if(!is_dir($directory) && !mkdir($directory,0700,true)) { throw new RuntimeException('Cannot create paper directory.'); }
        $path = $directory.'/writer.lock';
        if (is_link($path)) { throw new RuntimeException('Unsafe paper writer lock path.'); }
        $this->writerLock = fopen($path, 'c+b');
        if (!$this->writerLock) { throw new RuntimeException('Cannot open paper writer lock.'); }
        @chmod($path, 0600);
        $this->write(function () use ($directory): void {
            $this->db=new PDO('sqlite:'.$directory.'/paper.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            chmod($directory.'/paper.sqlite',0600);
            $this->db->exec('PRAGMA busy_timeout=5000');
            $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL;
                CREATE TABLE IF NOT EXISTS runs(id TEXT PRIMARY KEY, config TEXT NOT NULL, state TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS events(run_id TEXT NOT NULL, sequence INTEGER NOT NULL, id TEXT NOT NULL, digest TEXT NOT NULL, payload TEXT NOT NULL, PRIMARY KEY(run_id,id), UNIQUE(run_id,sequence));');
            if (!in_array('resolved_config', array_column($this->db->query('PRAGMA table_info(runs)')->fetchAll(), 'name'), true)) {
                $this->db->exec('ALTER TABLE runs ADD COLUMN resolved_config TEXT');
            }
        });
    }
    public function __destruct()
    {
        if (is_resource($this->writerLock ?? null)) { fclose($this->writerLock); }
    }
    /** Queue cooperating writers before SQLite instead of competing through its busy polling. */
    private function write(callable $operation): mixed
    {
        if ($this->writing) { return $operation(); }
        if (!flock($this->writerLock, LOCK_EX)) { throw new RuntimeException('Cannot acquire paper writer lock.'); }
        $this->writing = true;
        try { return $operation(); }
        finally { $this->writing = false; flock($this->writerLock, LOCK_UN); }
    }
    private function transaction(callable $operation): mixed
    {
        return $this->write(function () use ($operation): mixed {
            $began = false;
            try {
                $this->db->exec('BEGIN IMMEDIATE'); $began = true;
                $result = $operation();
                $this->db->exec('COMMIT'); $began = false;
                return $result;
            } catch (Throwable $error) {
                if ($began) { $this->db->exec('ROLLBACK'); }
                throw $error;
            }
        });
    }
    public static function id(string $id): string
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,96}$/D',$id)) { throw new RuntimeException('Invalid run ID.'); } return $id;
    }
    public function create(string $id, array $config): array
    {
        self::id($id); $engine=new Engine(Settings::validate($config));
        return $this->write(function () use ($id, $engine): array {
            $q=$this->db->prepare('INSERT INTO runs(id,config,state) VALUES(?,?,?)');
            $q->execute([$id,json_encode($engine->config,JSON_THROW_ON_ERROR),json_encode($engine->state,JSON_THROW_ON_ERROR)]);
            return $engine->state;
        });
    }
    public function ensure(string $id, array $config): string
    {
        self::id($id); $config = Settings::validate($config);
        return $this->transaction(function () use ($id, $config): string {
            $q = $this->db->prepare('SELECT config FROM runs WHERE id=?'); $q->execute([$id]); $saved = $q->fetchColumn();
            if ($saved === false) { $this->create($id, $config); $result = 'created'; }
            else {
                if (PaperConfig::fingerprint(Settings::validate(json_decode($saved, true, 512, JSON_THROW_ON_ERROR))) !== PaperConfig::fingerprint($config)) {
                    throw new RuntimeException('Paper run '.$id.' already has different settings. Restore its settings or choose a new paper_trading.run_id; existing balances are never reset.');
                }
                $result = 'resumed';
            }
            return $result;
        });
    }
    /** @return resource Held for the complete live session, including public feed startup. */
    public function acquireLiveLock(string $id)
    {
        $path = $this->directory.'/live-'.self::id($id).'.lock';
        if (is_link($path)) { throw new RuntimeException('Unsafe paper lock path.'); }
        $lock = fopen($path, 'c+b');
        if (!$lock) { throw new RuntimeException('Cannot open paper run lock.'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException('Paper run '.$id.' is already running.'); }
        @chmod($path, 0600); return $lock;
    }
    public function run(string $id): array
    {
        $q=$this->db->prepare('SELECT * FROM runs WHERE id=?');$q->execute([self::id($id)]);$r=$q->fetch();
        if(!$r) { throw new RuntimeException('Unknown paper run.'); }
        $result = ['config'=>json_decode($r['config'],true,512,JSON_THROW_ON_ERROR),'state'=>json_decode($r['state'],true,512,JSON_THROW_ON_ERROR)];
        if ($r['resolved_config'] !== null) { $result['resolved_config'] = json_decode($r['resolved_config'], true, 512, JSON_THROW_ON_ERROR); }
        return $result;
    }
    public function resolve(string $id, callable $resolve): array
    {
        $run = $this->run($id);
        if (isset($run['resolved_config'])) { return $run['resolved_config']; }
        $config = Settings::validate($resolve($run['config']));
        // Resolve may perform network I/O; only its final local update takes the writer gate.
        $this->write(function () use ($id, $config): void {
            $q = $this->db->prepare('UPDATE runs SET resolved_config=? WHERE id=? AND resolved_config IS NULL');
            $q->execute([json_encode($config, JSON_THROW_ON_ERROR), $id]);
        });
        return $this->run($id)['resolved_config'];
    }
    public function exists(string $id): bool
    {
        $q = $this->db->prepare('SELECT 1 FROM runs WHERE id=?'); $q->execute([self::id($id)]); return $q->fetchColumn() !== false;
    }
    public function automaticIds(): array
    {
        $path = $this->directory.'/automatic-runs.json';
        if (is_file($path)) {
            $ids = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR)['runs'] ?? [];
            if (!is_array($ids)) { throw new RuntimeException('Invalid automatic paper run list.'); }
            foreach ($ids as $id) { self::id($id); }
            return $ids;
        }
        return $this->db->query("SELECT id FROM runs WHERE id LIKE 'auto-%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    }
    public function apply(string $id, array $event): array
    {
        return $this->applyBatch($id, [$event]);
    }
    /** Commit a bounded group of captured events and its final state atomically. */
    public function applyBatch(string $id, array $events): array
    {
        self::id($id);
        if (!array_is_list($events) || !$events || count($events) > self::MAX_BATCH_EVENTS) {
            throw new RuntimeException('Supply 1–'.self::MAX_BATCH_EVENTS.' events per paper batch.');
        }
        $captured = [];
        foreach ($events as $event) {
            if (!is_array($event) || !is_string($event['id'] ?? null) || $event['id'] === '' || strlen($event['id']) > 200) {
                throw new RuntimeException('Event needs a stable ID.');
            }
            $payload = json_encode($event, JSON_THROW_ON_ERROR);
            $captured[] = [$event, $payload, hash('sha256', $payload)];
        }
        return $this->transaction(function () use ($id, $captured): array {
            $lookup = $this->db->prepare('SELECT digest FROM events WHERE run_id=? AND id=?');
            $insert = $this->db->prepare('INSERT INTO events VALUES(?,?,?,?,?)');
            $engine = null;
            foreach ($captured as [$event, $payload, $hash]) {
                $lookup->execute([$id, $event['id']]); $old = $lookup->fetchColumn(); $lookup->closeCursor();
                if ($old !== false) {
                    if (!hash_equals($old, $hash)) { throw new RuntimeException('Event ID reused with different content.'); }
                    continue;
                }
                if ($engine === null) {
                    $run = $this->run($id); $engine = new Engine($run['config'], $run['state']);
                }
                $engine->apply($event);
                $insert->execute([$id, $engine->state['sequence'], $event['id'], $hash, $payload]);
            }
            if ($engine === null) { return ['duplicate' => true]; }
            $q = $this->db->prepare('UPDATE runs SET state=? WHERE id=?');
            $q->execute([json_encode($engine->state, JSON_THROW_ON_ERROR), $id]);
            return ['sequence' => $engine->state['sequence'], 'orders' => count($engine->state['orders']), 'fills' => count($engine->state['fills'])];
        });
    }
    public function events(string $id): iterable
    {
        $q=$this->db->prepare('SELECT payload FROM events WHERE run_id=? ORDER BY sequence');$q->execute([self::id($id)]);
        while($row=$q->fetch()) { yield json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR); }
    }
    public function replay(string $source, string $target): array
    {
        $run=$this->run($source);$this->create($target,$run['config']);
        foreach($this->events($source) as $event) { $this->apply($target,$event); }
        $actual=$this->run($target)['state'];
        return ['run'=>$target,'matched'=>$actual===$run['state'],'state_sha256'=>hash('sha256',json_encode($actual,JSON_THROW_ON_ERROR))];
    }
}
