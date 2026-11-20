<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M1\Config;
use Trademinator\Client\M2\Credentials;

final class Server
{
    private string $key;
    private readonly ?ServerRequestGate $gate;

    public function __construct(private readonly Config $config, private readonly ?\Closure $transport=null)
    {
        if($config->serverUrl==='') { throw new RuntimeException('Set server_url in the Client configuration.'); }
        $this->key=Credentials::read($config->serverApiKeyEnv,$config->serverApiKeyFile);
        \Trademinator\Client\M1\EventRecord::hideSecret($this->key);
        $this->gate=$transport===null?new ServerRequestGate($config->dataDir.'/runtime',hash('sha256',$config->serverUrl."\0".$this->key)):null;
    }

    public function request(string $subscription,string $resource,?array $body=null,array $query=[],bool $defer=false): array
    {
        if ($subscription === '' && in_array($resource, ['markets', 'risk-factor'], true) && $body === null) {
            $path = '/api/v1/client/'.$resource;
        } elseif (preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di',$subscription)
            && in_array($resource,['market','replay/history','replay/models','replay/infer'],true)) {
            $path = '/api/v1/client/markets/'.$subscription.($resource === 'market' ? '' : '/'.$resource);
        } else { throw new RuntimeException('Invalid paper Server endpoint.'); }
        if($this->transport!==null) { return ($this->transport)($subscription,$resource,$body,$query); }
        // Never hold the gate lock during network I/O or sleep. Live inference
        // defers immediately; discovery may wait only one short pacing interval.
        $deadline=(int)floor(microtime(true)*1000)+2100;
        while(($wait=$this->gate->reserve($resource==='replay/infer'))>0) {
            if($defer || (int)floor(microtime(true)*1000)+$wait>$deadline) { throw new RequestDeferred($wait); }
            usleep($wait*1000);
        }
        $url=$this->config->serverUrl.$path;
        if($query) { $url.='?'.http_build_query($query); }
        $h=curl_init($url);$buffer='';$retryAfter=null;
        curl_setopt_array($h,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->key,'Accept: application/json','Content-Type: application/json'],
            CURLOPT_TIMEOUT_MS=>$this->config->serverTimeoutMs,CURLOPT_CONNECTTIMEOUT_MS=>$this->config->serverTimeoutMs,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$retryAfter):int {
                if(strlen($line)<=8192 && strncasecmp($line,'Retry-After:',12)===0) { $retryAfter=trim(substr($line,12)); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function($handle,string $bytes) use(&$buffer): int { if(strlen($buffer)+strlen($bytes)>16777216) { return 0; }$buffer.=$bytes;return strlen($bytes); }]);
        if($body!==null) { curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR)); }
        $ok=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
        if($ok===false) { throw new ServerRequestFailed(0); }
        if($status!==200) {
            $now=(int)floor(microtime(true)*1000);
            $delay=$status===429 || $retryAfter!==null?ServerRequestFailed::retryDelayMs($retryAfter,$now):0;
            if($status===429) { $this->gate->cooldown($delay,$now); }
            throw new ServerRequestFailed($status,$delay);
        }
        return json_decode($buffer,true,512,JSON_THROW_ON_ERROR);
    }

    public function __debugInfo(): array { return ['server'=>$this->config->serverUrl]; }
}
