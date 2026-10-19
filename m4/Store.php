<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use PDO;
use RuntimeException;
use Throwable;

/** An event and its resulting state commit together; real client.sqlite is never opened. */
final class Store
{
    private PDO $db;
    public function __construct(string $directory)
    {
        if(!is_dir($directory) && !mkdir($directory,0700,true)) { throw new RuntimeException('Cannot create paper directory.'); }
        $this->db=new PDO('sqlite:'.$directory.'/paper.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        chmod($directory.'/paper.sqlite',0600);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000;
            CREATE TABLE IF NOT EXISTS runs(id TEXT PRIMARY KEY, config TEXT NOT NULL, state TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS events(run_id TEXT NOT NULL, sequence INTEGER NOT NULL, id TEXT NOT NULL, digest TEXT NOT NULL, payload TEXT NOT NULL, PRIMARY KEY(run_id,id), UNIQUE(run_id,sequence));');
    }
    public static function id(string $id): string
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,96}$/D',$id)) { throw new RuntimeException('Invalid run ID.'); } return $id;
    }
    public function create(string $id, array $config): array
    {
        self::id($id); $engine=new Engine(Settings::validate($config));
        $q=$this->db->prepare('INSERT INTO runs VALUES(?,?,?)');
        $q->execute([$id,json_encode($engine->config,JSON_THROW_ON_ERROR),json_encode($engine->state,JSON_THROW_ON_ERROR)]);
        return $engine->state;
    }
    public function run(string $id): array
    {
        $q=$this->db->prepare('SELECT * FROM runs WHERE id=?');$q->execute([self::id($id)]);$r=$q->fetch();
        if(!$r) { throw new RuntimeException('Unknown paper run.'); }
        return ['config'=>json_decode($r['config'],true,512,JSON_THROW_ON_ERROR),'state'=>json_decode($r['state'],true,512,JSON_THROW_ON_ERROR)];
    }
    public function apply(string $id, array $event): array
    {
        self::id($id);$payload=json_encode($event,JSON_THROW_ON_ERROR);$hash=hash('sha256',$payload);
        if(!is_string($event['id']??null) || strlen($event['id'])>200 || $event['id']==='') { throw new RuntimeException('Event needs a stable ID.'); }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT digest FROM events WHERE run_id=? AND id=?');$q->execute([$id,$event['id']]);$old=$q->fetchColumn();
            if($old!==false) {
                if(!hash_equals($old,$hash)) { throw new RuntimeException('Event ID reused with different content.'); }
                $this->db->exec('COMMIT');return ['duplicate'=>true];
            }
            $run=$this->run($id);$engine=new Engine($run['config'],$run['state']);$engine->apply($event);
            $q=$this->db->prepare('INSERT INTO events VALUES(?,?,?,?,?)');$q->execute([$id,$engine->state['sequence'],$event['id'],$hash,$payload]);
            $q=$this->db->prepare('UPDATE runs SET state=? WHERE id=?');$q->execute([json_encode($engine->state,JSON_THROW_ON_ERROR),$id]);
            $this->db->exec('COMMIT');return ['sequence'=>$engine->state['sequence'],'orders'=>count($engine->state['orders']),'fills'=>count($engine->state['fills'])];
        } catch(Throwable $e) { $this->db->exec('ROLLBACK');throw $e; }
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
