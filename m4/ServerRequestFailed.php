<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;

final class ServerRequestFailed extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly int $retryAfterMs=0)
    {
        parent::__construct('Replay API failed (HTTP '.$status.'); no simulated decision was issued.');
    }

    public function isTransient(): bool
    {
        return $this->status===0 || $this->status===429 || ($this->status>=500 && $this->status<=599);
    }

    /** Retry-After is a delay in seconds or an HTTP date; never expose response bodies. */
    public static function retryDelayMs(?string $header,int $nowMs,int $fallbackMs=60000): int
    {
        $header=trim($header??'');
        if(preg_match('/^\d{1,9}$/D',$header)) { return max(1000,(int)$header*1000); }
        if(preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/D',$header)) {
            $at=strtotime($header);
            if($at!==false) { return max(1000,$at*1000-$nowMs); }
        }
        return max(1000,$fallbackMs);
    }
}
