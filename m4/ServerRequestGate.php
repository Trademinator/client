<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;

/** Coordinates M4 workers sharing a data directory, Server origin and credential. */
final class ServerRequestGate
{
    private readonly string $path;

    public function __construct(private readonly string $directory,string $identity)
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$identity)) { throw new RuntimeException('Invalid replay pacing identity.'); }
        $this->path=rtrim($directory,'/\\').'/paper-server-'.$identity.'.json';
    }

    /** A zero result reserves a request slot. Otherwise return the remaining delay without reserving. */
    public function reserve(bool $inference,?int $nowMs=null): int
    {
        $nowMs??=(int)floor(microtime(true)*1000);
        return $this->update(static function(array &$state)use($inference,$nowMs):int {
            $until=max($state['next_any_ms'],$state['cooldown_until_ms'],$inference?$state['next_infer_ms']:0);
            if($until>$nowMs) { return $until-$nowMs; }
            $state['next_any_ms']=$nowMs+600;
            if($inference) { $state['next_infer_ms']=$nowMs+2100; }
            return 0;
        });
    }

    /** Extend an actual Server cooldown. Reads and deferred attempts never move this deadline. */
    public function cooldown(int $retryAfterMs,?int $nowMs=null): void
    {
        $nowMs??=(int)floor(microtime(true)*1000);
        $this->update(static function(array &$state)use($retryAfterMs,$nowMs):int {
            $state['cooldown_until_ms']=max($state['cooldown_until_ms'],$nowMs+max(0,$retryAfterMs));
            return 0;
        });
    }

    private function update(callable $change): int
    {
        if(!is_dir($this->directory) && !@mkdir($this->directory,0700,true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create shared replay pacing directory.');
        }
        $handle=@fopen($this->path,'c+b');
        if($handle===false) { throw new RuntimeException('Cannot open shared replay pacing state.'); }
        try {
            if(PHP_OS_FAMILY!=='Windows' && !@chmod($this->path,0600)) { throw new RuntimeException('Cannot protect shared replay pacing state.'); }
            if(!flock($handle,LOCK_EX)) { throw new RuntimeException('Cannot lock shared replay pacing state.'); }
            $bytes=stream_get_contents($handle,4097);
            if($bytes===false || strlen($bytes)>4096) { throw new RuntimeException('Invalid shared replay pacing state.'); }
            $saved=$bytes===''?[]:json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($saved)) { throw new RuntimeException('Invalid shared replay pacing state.'); }
            $state=[];
            foreach(['next_any_ms','next_infer_ms','cooldown_until_ms'] as $name) {
                $value=$saved[$name]??0;
                if(!is_int($value) || $value<0) { throw new RuntimeException('Invalid shared replay pacing state.'); }
                $state[$name]=$value;
            }
            $result=$change($state);
            $updated=json_encode($state,JSON_THROW_ON_ERROR);
            if($updated!==$bytes) {
                rewind($handle);
                if(!ftruncate($handle,0) || fwrite($handle,$updated)!==strlen($updated) || !fflush($handle)) {
                    throw new RuntimeException('Cannot save shared replay pacing state.');
                }
            }
            return $result;
        } finally {
            flock($handle,LOCK_UN);
            fclose($handle);
        }
    }
}
