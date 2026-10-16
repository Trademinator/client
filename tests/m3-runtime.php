<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';require_once dirname(__DIR__).'/m1/bootstrap.php';
use Trademinator\Client\M1\{Database,Ledger};
use Trademinator\Client\M3\{Runtime,Settings,Normalize,CcxtAccount};
use React\Promise\Deferred;
use function React\Promise\resolve;
use function React\Promise\reject;
$checks=0;function check(bool $ok,string $text):void{global $checks;++$checks;if(!$ok){throw new RuntimeException($text);}echo "PASS $text\n";}
final class ExchangeFixture
{
 public array $batches=[];public array $watches=[];public int $calls=0;public bool $closed=false;
 public function wsCapabilities():array{return ['balance'];}
 public function watch(string $channel,?string $symbol):object{$d=new Deferred();$this->watches[]=$d;return $d->promise();}
 public function snapshot(array $context):object{$this->calls++;$b=array_shift($this->batches);return $b instanceof Throwable?reject($b):resolve($b);}
 public function resetRest():void{}
 public function resetWebSocket():void{}
 public function close():void{$this->closed=true;}
}
$db=new Database(':memory:');$ledger=new Ledger($db);$id=$ledger->createAccount('fixture','runtime',null,['USD'=>'100']);$m=$ledger->registerMarket($id,'ATOM/USD','ATOM','USD');$db->pdo()->exec("UPDATE markets SET state='ACTIVE'");
$a=['account_id'=>$id,'api_key_env'=>'M3_TEST_KEY','secret_env'=>'M3_TEST_SECRET','history_since_ms'=>0];$s=Settings::validate(['accounts'=>[$a]]);$f=new ExchangeFixture();
$now=(int)floor(microtime(true)*1000);$good=['at_ms'=>$now,'since_ms'=>0,'complete'=>true,'orders'=>[],'trades'=>[],'operations'=>[],'prices'=>[],'balances'=>['USD'=>['total'=>'100','free'=>'100','used'=>'0']]];
$f->batches=[$good,new RuntimeException('SECRET_NETWORK_FAILURE'),$good,new ccxt\AuthenticationError('SECRET_API_KEY')];$events=[];
$r=new Runtime($db,$s,fn($e,$settings,$a)=>$f,function($l,$e,$c,$m)use(&$events){$events[]=[$l,$e,$c,$m];});
check(!$r->ready($m)['ready'],'startup requires exchange reconciliation');$r->tick($now);check($r->ready($m)['ready'],'REST-only may be ready after complete reconciliation');
check($r->status()['accounts'][$id]['transport']==='rest','quiet or unavailable WebSocket does not masquerade as connected');
$f->watches[0]->resolve(['USD'=>['total'=>'100']]);check(!$r->ready($m)['ready'],'private WebSocket event invalidates prior REST authorization');
$r->tick($now+100000);check(!$r->ready($m)['ready']&&$r->status()['accounts'][$id]['state']==='RECOVERING','REST failure blocks new orders even with WebSocket traffic');
$r->tick($now+200000);check($r->ready($m)['ready'],'retry recovers using authoritative REST state');
$r->tick($now+300000);check($r->status()['accounts'][$id]['state']==='AUTH_BLOCKED','credential rejection enters AUTH_BLOCKED');
$calls=$f->calls;$r->tick($now+400000);check($f->calls===$calls,'AUTH_BLOCKED awaits credential reload instead of retry storm');
check(!str_contains(json_encode([$events,$r->status()]),'SECRET'),'transport error messages cannot expose credentials');
$r->close();check($f->closed,'shutdown closes exchange transport');
$missing=new Runtime($db,$s,fn($e,$settings,$a)=>throw new ccxt\AuthenticationError('missing-secret'));
check($missing->status()['accounts'][$id]['state']==='AUTH_BLOCKED','unavailable local credentials block the account without killing the daemon');
$missing->tick($now);check(!$missing->ready($m)['ready'],'missing credential account cannot become ready');$missing->close();
// No execution capabilities exist on the adapter offered to M3.
foreach(['create_order','cancel_order','withdraw','transfer'] as $method){check(!method_exists(CcxtAccount::class,$method),'M3 has no '.$method.' capability');}
echo json_encode(['suite'=>'m3-runtime','status'=>'passed','checks'=>$checks,'network_requests'=>0],JSON_PRETTY_PRINT)."\n";
