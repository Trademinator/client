<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M1\Config;
use Trademinator\Client\M2\Credentials;

final class Server
{
    private string $key;
    private float $lastRequest=0.0;
    public function __construct(private readonly Config $config, private readonly ?\Closure $transport=null)
    {
        if($config->serverUrl==='') { throw new RuntimeException('Set server_url in the Client configuration.'); }
        $this->key=Credentials::read($config->serverApiKeyEnv,$config->serverApiKeyFile);
    }
    public function request(string $subscription,string $resource,?array $body=null,array $query=[]): array
    {
        if(!preg_match('/^[a-f0-9-]{36}$/Di',$subscription) || !in_array($resource,['replay/history','replay/models','replay/infer'],true)) { throw new RuntimeException('Invalid replay endpoint.'); }
        if($this->transport!==null) { return ($this->transport)($subscription,$resource,$body,$query); }
        $interval=$resource==='replay/infer'?2.1:0.6;
        $wait=$interval-(microtime(true)-$this->lastRequest);if($wait>0) { usleep((int)ceil($wait*1000000)); }
        $this->lastRequest=microtime(true);
        $url=$this->config->serverUrl.'/api/v1/client/markets/'.$subscription.'/'.$resource;
        if($query) { $url.='?'.http_build_query($query); }
        $h=curl_init($url);$buffer='';
        curl_setopt_array($h,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->key,'Accept: application/json','Content-Type: application/json'],
            CURLOPT_TIMEOUT_MS=>$this->config->serverTimeoutMs,CURLOPT_CONNECTTIMEOUT_MS=>$this->config->serverTimeoutMs,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,string $bytes) use(&$buffer): int { if(strlen($buffer)+strlen($bytes)>16777216) { return 0; }$buffer.=$bytes;return strlen($bytes); }]);
        if($body!==null) { curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR)); }
        $ok=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
        if($ok===false || $status!==200) { throw new RuntimeException('Replay API failed (HTTP '.$status.'); no simulated decision was issued.'); }
        return json_decode($buffer,true,512,JSON_THROW_ON_ERROR);
    }
    public function __debugInfo(): array { return ['server'=>$this->config->serverUrl]; }
}
