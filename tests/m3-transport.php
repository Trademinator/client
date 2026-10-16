<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';require_once dirname(__DIR__).'/m1/bootstrap.php';
use Trademinator\Client\M3\{CcxtAccount,Settings,Normalize};
use function React\Promise\resolve;
use function React\Async\await;
$checks=0;function check(bool $ok,string $text):void{global $checks;++$checks;if(!$ok){throw new RuntimeException($text);}echo "PASS $text\n";}
function refuses(callable $fn,string $text):void{try{$fn();}catch(Throwable){check(true,$text);return;}check(false,$text);}
class RestFixture
{
 public array $has=['fetchLedger'=>true,'fetchOrderTrades'=>true];public string $id='fixture';public array $calls=[];public bool $repeat=false;public bool $moving=false;private int $balanceCalls=0;
 public function load_markets():object{return resolve(['ATOM/USD'=>['symbol'=>'ATOM/USD','spot'=>true]]);}
 public function fetch_balance():object{$this->balanceCalls++;$balance=$this->moving&&$this->balanceCalls>1?'99':'100';return resolve(['total'=>['USD'=>$balance],'free'=>['USD'=>$balance],'used'=>['USD'=>'0']]);}
 public function fetch_open_orders($symbol,$since,$limit,$params):object{return resolve([]);}
 public function fetch_my_trades($symbol,$since,$limit,$params):object{
  $this->calls[]=[$symbol,$since,$limit,$params];$marker=$params['marker']??null;
  $rows=$marker===null||$this->repeat?[self::trade(3),self::trade(2)]:[self::trade(2),self::trade(1)];
  if($marker==='1'){$rows=[];}return resolve($rows);
 }
 public static function trade(int $i):array{return ['id'=>(string)$i,'order'=>'o'.$i,'symbol'=>'ATOM/USD','side'=>'buy','timestamp'=>$i*1000,'amount'=>'1','price'=>'10','cost'=>'10','fee'=>['currency'=>'USD','cost'=>'0.1']];}
 public function fetch_order($id,$symbol):object{$i=(int)substr($id,1);return resolve(['id'=>$id,'symbol'=>$symbol,'side'=>'buy','type'=>'limit','status'=>'closed','timestamp'=>$i*1000,'amount'=>'1','filled'=>'1','price'=>'10','cost'=>'10']);}
 public function fetch_order_trades($id,$symbol,$since,$limit,$params):object{return resolve([self::trade((int)substr($id,1))]);}
 public function fetch_ledger($code,$since,$limit,$params):object{return resolve([['id'=>'ledger1','referenceId'=>'w1','type'=>'transaction','direction'=>'out','timestamp'=>4000,'currency'=>'ATOM','amount'=>'-1','status'=>'ok','fee'=>null]]);}
 public function close():void{}
}
$a=['account_id'=>'fixture-account','api_key_env'=>'FIXTURE_KEY','secret_env'=>'FIXTURE_SECRET','history_since_ms'=>0,'history_params'=>[
 'fetch_my_trades'=>['mode'=>'backward_id','cursor_param'=>'marker','params'=>['sort'=>'desc']]
]];$s=Settings::validate(['accounts'=>[$a],'history_limit'=>2]);$a=$s['accounts'][0];$rest=new RestFixture();$client=new CcxtAccount('fixture',$s,$a,$rest);
$batch=await($client->snapshot(['symbols'=>['ATOM/USD'],'since_ms'=>0,'known_orders'=>[]]));
check(count($batch['trades'])===3,'overlapping ID pages deduplicate trade identities');
check(count($rest->calls)===3&&$rest->calls[1][1]===null&&$rest->calls[1][3]['marker']==='2','CCXT cursor pagination does not fabricate timestamp support');
check($batch['operations'][0]['kind']==='withdrawal'&&$batch['operations'][0]['id']==='withdrawal:w1'&&$batch['operations'][0]['amount']==='1','CCXT unified ledger provides withdrawal fallback');
check($batch['complete']&&count($batch['orders'])===3,'each fill has authoritative order state');
$bad=new RestFixture();$bad->repeat=true;$client2=new CcxtAccount('fixture',$s,$a,$bad);refuses(fn()=>await($client2->snapshot(['symbols'=>['ATOM/USD'],'since_ms'=>0,'known_orders'=>[]])),'non-progressing history page fails closed');
$moving=new RestFixture();$moving->moving=true;$client3=new CcxtAccount('fixture',$s,$a,$moving);refuses(fn()=>await($client3->snapshot(['symbols'=>['ATOM/USD'],'since_ms'=>0,'known_orders'=>[]])),'wallet mutation during capture requires another snapshot');
// Pinned CCXT parsing uses string precision, tested without a network connection.
$bitso=new ccxt\bitso(['number'=>'strval']);$raw=['book'=>'atom_usd','created_at'=>'2026-10-16T01:00:00+0000','oid'=>'actual-order','original_amount'=>'2.00000000','unfilled_amount'=>'0.00000000','original_value'=>'20.00000000','price'=>'10.00000000','side'=>'buy','status'=>'completed','type'=>'limit'];
$bitso->set_markets([['id'=>'atom_usd','symbol'=>'ATOM/USD','base'=>'ATOM','quote'=>'USD','baseId'=>'atom','quoteId'=>'usd','spot'=>true,'type'=>'spot','precision'=>['amount'=>0.00000001,'price'=>0.01],'limits'=>[]]]);
$normalized=Normalize::order($bitso->parse_order($raw));check($normalized['amount']==='2'&&$normalized['filled']==='2','pinned CCXT Bitso terminal order parses to exact decimals');
$deposit=$bitso->parse_transaction(['fid'=>'funding','status'=>'complete','created_at'=>'2026-10-16T01:00:00+0000','currency'=>'atom','amount'=>'0.12345678','method'=>'atom','network'=>'atom','details'=>['tx_hash'=>'hash','receiving_address'=>'address']]);
check(Normalize::operation($deposit,'deposit')['amount']==='0.12345678','pinned CCXT Bitso deposit retains decimal precision');
echo json_encode(['suite'=>'m3-transport','status'=>'passed','checks'=>$checks,'network_requests'=>0],JSON_PRETTY_PRINT)."\n";
