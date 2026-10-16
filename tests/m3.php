<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';
use Trademinator\Client\M1\{Database,Ledger,Backup};
use Trademinator\Client\M3\{Accounting,Reconciler,Normalize,Numbers,Settings,Transfers,HardPoints,Runtime};
use function React\Promise\resolve;
use function React\Promise\reject;

$checks=0;
function check(bool $test,string $message): void {global $checks;$checks++;if(!$test){throw new RuntimeException($message);}echo "PASS $message\n";}
function refuses(callable $fn,string $message): void {try{$fn();}catch(Throwable){check(true,$message);return;}check(false,$message);}
function config(string $id): array {return ['account_id'=>$id,'valuation_quote'=>'USD','history_since_ms'=>0,'currency_markets'=>[],'public_price_exchanges'=>[],'coingecko_ids'=>[],'history_params'=>[],'api_key_env'=>'M3_TEST_KEY','secret_env'=>'M3_TEST_SECRET'];}
function fill(string $id,string $order,string $side,string $amount,string $price,int $at,array $fees=[]): array {return ['id'=>$id,'order'=>$order,'symbol'=>'ATOM/USD','side'=>$side,'timestamp'=>$at,'amount'=>$amount,'price'=>$price,'cost'=>Numbers::decimal(bcmul($amount,$price,24)),'fees'=>$fees];}
function order(array $fill,string $status='filled',?string $amount=null): array {return ['id'=>$fill['order'],'symbol'=>$fill['symbol'],'side'=>$fill['side'],'type'=>'LIMIT','status'=>$status,'amount'=>$amount??$fill['amount'],'filled'=>$fill['amount'],'price'=>$fill['price'],'cost'=>$fill['cost'],'timestamp'=>$fill['timestamp'],'client_order_id'=>null];}
function operation(string $id,string $kind,string $amount,int $at,array $fees=[],?string $hash=null): array {return ['id'=>$kind.':'.$id,'kind'=>$kind,'currency'=>'ATOM','amount'=>$amount,'fees'=>$fees,'timestamp'=>$at,'txid'=>$hash,'address'=>null,'tag'=>null,'network'=>'ATOM'];}
function batch(array $trades,array $operations,array $totals,int $at,?array $orders=null): array {
 $balances=[];foreach($totals as $c=>$q){$balances[$c]=['total'=>$q,'free'=>$q,'used'=>'0'];}ksort($balances);
 return ['at_ms'=>$at,'since_ms'=>0,'complete'=>true,'orders'=>$orders??array_map(fn($t)=>order($t),$trades),'trades'=>$trades,'operations'=>$operations,'balances'=>$balances,'prices'=>[]];
}
$root=sys_get_temp_dir().'/trademinator-m3-'.bin2hex(random_bytes(6));Backup::ensureDirectory($root);
try {
 $db=new Database($root.'/client.sqlite');$ledger=new Ledger($db);$account=$ledger->createAccount('fixture','M3',null,['USD'=>'1000']);$market=$ledger->registerMarket($account,'ATOM/USD','ATOM','USD');
 $db->pdo()->prepare("UPDATE markets SET state='ACTIVE' WHERE id=?")->execute([$market]);
 $s=Settings::validate(['accounts'=>[config($account)]]);$a=$s['accounts'][0];$r=new Reconciler($db);$math=new Accounting($db);
 check(Database::SCHEMA_VERSION===5&&$db->integrityCheck()['ok'],'schema 5 migration is valid');
 check(Numbers::decimal(1e-8)==='0.00000001','CCXT scientific notation converts once to decimal');
 refuses(fn()=>Normalize::balances(['total'=>['USD'=>'2'],'free'=>['USD'=>'1'],'used'=>['USD'=>'0']]),'inconsistent locked/free balances rejected');
 $t=1000000;$b1=fill('t1','b1','BUY','10','10',$t);$b2=fill('t2','b2','BUY','5','12',$t+1000);
 $one=batch([$b1,$b2],[],['USD'=>'840','ATOM'=>'15'],$t+2000);
 check($r->apply($account,$one,$a)['reconciled'],'manual trades enter unified ledger');
 $revision=$db->revision($account);$r->apply($account,$one,$a);check($db->revision($account)===$revision,'duplicate REST history is financially idempotent');
 check(count($ledger->marketLots($market))===2,'individual fill acquisition lots retained');
 $w=operation('w1','withdrawal','4',$t+3000,[['currency'=>'ATOM','cost'=>'1']],'hash-1');
 $two=batch([$b1,$b2],[$w],['USD'=>'840','ATOM'=>'10'],$t+4000);
 check($r->apply($account,$two,$a)['reconciled'],'withdrawal deducts principal and confirmed network expense');
 $lots=$ledger->marketLots($market);check(count($lots)===1&&$lots[0]['unit_cost']==='10'&&$lots[0]['quantity']==='10','withdrawal uses newest BUY first (LIFO)');
 $destination=$ledger->createAccount('fixture','Destination');$target=$ledger->registerMarket($destination,'ATOM/USD','ATOM','USD');$da=config($destination);
 $d=operation('d1','deposit','4',$t+3500,[],'hash-1');$received=batch([],[$d],['ATOM'=>'4'],$t+4500);$received['prices'][$d['id']]=['price'=>'99','source'=>'fixture'];
 check($r->apply($destination,$received,$da)['reconciled'],'unmatched deposit stores estimated cost');
 $transfer=new Transfers($db,$s);check($transfer->candidates()[0]['confidence']==='high','unique hash match is high confidence');
 $linked=$transfer->autoLink();check($linked[0]['linked'],'high-confidence managed-wallet transfer auto-links');
 $lots=$ledger->marketLots($target);check($lots[0]['unit_cost']==='12'&&$lots[0]['quantity']==='4','transfer preserves original BUY cost without capitalizing fee');
 check(count($math->rows('SELECT * FROM orders WHERE account_id=?',[$account]))===2,'network fee is not a SELL');
 check($transfer->candidates()===[],'linked transfer is not offered again');
 $r->apply($account,$two,$a);$r->apply($destination,$received,$da);
 $bad=$two;$bad['balances']['USD']['total']='841';$bad['balances']['USD']['free']='841';
 check(!$r->apply($account,$bad,$a)['reconciled'],'unexplained wallet discrepancy blocks reconciliation');
 check($db->pdo()->query("SELECT state FROM markets WHERE id=".$db->pdo()->quote($market))->fetchColumn()==='ACTIVE','discrepancy leaves subscription market ACTIVE');
 check($ledger->expectedBalances($account)['USD']==='840','discrepancy never fabricates balancing operations');
 $r->apply($account,$two,$a);
 $uncertain=$r->uncertain(['account_id'=>$account,'market_id'=>$market,'client_order_id'=>'client-1','side'=>'BUY','quantity'=>'1','price'=>'10']);
 check($ledger->openOrdersCount($account,$market)===1,'uncertain submission blocks affected market');
 unset($r,$ledger,$math,$db);$db=new Database($root.'/client.sqlite');$ledger=new Ledger($db);$r=new Reconciler($db);$math=new Accounting($db);
 check($ledger->openOrdersCount($account,$market)===1,'uncertain submission survives restart');
 $r->apply($account,$two,$a);check($ledger->openOrdersCount($account,$market)===1,'absence from exchange history cannot resolve uncertainty');
 $b3=fill('t3','b3','BUY','1','10',$t+5000);$o3=order($b3);$o3['client_order_id']='client-1';$three=batch([$b1,$b2,$b3],[$w],['USD'=>'830','ATOM'=>'11'],$t+6000,[order($b1),order($b2),$o3]);
 $r->apply($account,$three,$a);check($ledger->openOrdersCount($account,$market)===0,'explicit unique clientOrderId resolves uncertain outcome');
 $h=new HardPoints($db,$root);$candidate=$h->capture($account);check($candidate['eligible']&&$candidate['verification']==='pending','candidate H freezes a fully reconciled checkpoint');
 $three['at_ms']=$candidate['cutoff_ms']+1;$v=$h->verifyPending($account,$three);check($v['verification']==='verified','H1 verifies fresh complete history and frozen lot reconstruction');
 refuses(fn()=>$h->promote($account,'wrong-token'),'snapshot promotion requires exact approval token');
 $sell=fill('t4','s1','SELL','1','20',$candidate['cutoff_ms']+2);
 $four=batch([$b1,$b2,$b3,$sell],[$w],['USD'=>'850','ATOM'=>'10'],$candidate['cutoff_ms']+3,[order($b1),order($b2),$o3,order($sell)]);
 $r->apply($account,$four,$a);$wallet=$ledger->expectedBalances($account);$promoted=$h->promote($account,$candidate['token']);
 check($promoted['active']&&$ledger->expectedBalances($account)===$wallet,'promotion preserves post-snapshot wallet activity');
 check(count($math->rows('SELECT * FROM orders WHERE account_id=?',[$account]))===1,'only the captured prefix is archived; later SELL remains live');
 check($ledger->marketState($market)['holdings']==='10','post-snapshot SELL remaps to carried acquisition lots');
 check(is_file($root.'/hard-points/'.$account.'/'.$candidate['id'].'/archive.sqlite'),'permanent immutable financial archive exists');
 check($db->integrityCheck()['ok'],'post-promotion foreign keys remain valid');
 // Receiving first and ambiguity are independent of arrival order.
 $other=$ledger->createAccount('fixture','Ambiguous',null,['USD'=>'100']);$om=$ledger->registerMarket($other,'ATOM/USD','ATOM','USD');$oa=config($other);
 $buy=fill('amb-buy','amb-order','BUY','2','10',$t);$out=operation('amb-w','withdrawal','1',$t+1000,[],'same');$r->apply($other,batch([$buy],[$out],['USD'=>'80','ATOM'=>'1'],$t+2000),$oa);
 foreach(['x','y'] as $label){$ac=$ledger->createAccount('fixture',$label);$mk=$ledger->registerMarket($ac,'ATOM/USD','ATOM','USD');$dep=operation($label,'deposit','1',$t+1500,[],'same');$r->apply($ac,batch([],[$dep],['ATOM'=>'1'],$t+2500),config($ac));}
 $candidates=(new Transfers($db,$s))->candidates();check(count($candidates)===2&&array_unique(array_column($candidates,'confidence'))===['medium'],'ambiguous hash matches require confirmation');
 // Cost fallback can be completed by an auditable operator correction.
 $deposit=$math->rows("SELECT * FROM operations WHERE exchange_operation_id='deposit:x'")[0];$r->basis($deposit['id'],'14','Original acquisition statement');
 check($ledger->marketLots($deposit['market_id'])[0]['unit_cost']==='14','operator deposit basis updates inventory with audit');
 // Partial fill amounts, third-currency fees, and mixed-lot transfers.
 $pa=$ledger->createAccount('fixture','Partial',null,['USD'=>'100','BNB'=>'1']);$pm=$ledger->registerMarket($pa,'ATOM/USD','ATOM','USD');$feeMarket=$ledger->registerMarket($pa,'BNB/USD','BNB','USD',null,['opening_lots'=>[['id'=>'fee-lot','quantity'=>'1','unit_cost'=>'1','acquired_at'=>'1970-01-01T00:00:00Z']]]);
 $part=fill('partial-1','partial-order','BUY','2','10',$t,[['currency'=>'BNB','cost'=>'0.1']]);$pb=batch([$part],[],['USD'=>'80','ATOM'=>'2','BNB'=>'0.9'],$t+1000,[order($part,'partially_filled','4')]);
 $r->apply($pa,$pb,config($pa));check($ledger->openOrdersCount($pa)===1&&$ledger->marketState($feeMarket)['holdings']==='0.9','partial fill consumes confirmed third-currency fee from inventory');
 $part2=fill('partial-2','partial-order','BUY','2','11',$t+2000,[['currency'=>'USD','cost'=>'0.2']]);$finalOrder=order($part);$finalOrder['amount']='4';$finalOrder['filled']='4';$finalOrder['cost']='42';$pf=batch([$part,$part2],[],['USD'=>'57.8','ATOM'=>'4','BNB'=>'0.9'],$t+3000,[$finalOrder]);
 $r->apply($pa,$pf,config($pa));$pl=$ledger->marketLots($pm);check(count($pl)===2&&$pl[0]['unit_cost']==='10'&&$pl[1]['unit_cost']==='11.1','multiple fills preserve individual price and quote fee basis');
 $pw=operation('partial-withdrawal','withdrawal','3',$t+4000);$pf['operations']=[$pw];$pf['balances']['ATOM']=['total'=>'1','free'=>'1','used'=>'0'];$pf['at_ms']=$t+5000;$r->apply($pa,$pf,config($pa));
 $pd=$ledger->createAccount('fixture','Mixed transfer');$pdm=$ledger->registerMarket($pd,'ATOM/USD','ATOM','USD');$pdep=operation('mixed','deposit','3',$t+4500);$r->apply($pd,batch([],[$pdep],['ATOM'=>'3'],$t+6000),config($pd));
 $wr=$math->rows('SELECT id FROM operations WHERE account_id=?',[$pa])[0]['id'];$dr=$math->rows('SELECT id FROM operations WHERE account_id=?',[$pd])[0]['id'];(new Transfers($db,$s))->link($wr,$dr,true);
 $mixed=$ledger->marketLots($pdm);check(count($mixed)===2&&array_sum(array_map(fn($lot)=>(int)$lot['quantity'],$mixed))===3,'linked transfer retains multiple originating BUY lots');
 // A transfer first discovered after promotion must correct costs without touching the archive.
 $lateSource=$ledger->createAccount('fixture','Late source',null,['USD'=>'100']);$lsm=$ledger->registerMarket($lateSource,'ATOM/USD','ATOM','USD');$lsa=config($lateSource);
 $lb=fill('late-buy','late-order','BUY','5','7',$t);$lw=operation('late-w','withdrawal','2',$t+1000,[],'late-hash');$lbch=batch([$lb],[$lw],['USD'=>'65','ATOM'=>'3'],$t+2000);
 $r->apply($lateSource,$lbch,$lsa);$lh=new HardPoints($db,$root);$lc=$lh->capture($lateSource);$lbch['at_ms']=$lc['cutoff_ms']+1;$lh->verifyPending($lateSource,$lbch);$lh->promote($lateSource,$lc['token']);
 $archivePath=$root.'/hard-points/'.$lateSource.'/'.$lc['id'].'/hard.json';$archiveHash=hash_file('sha256',$archivePath);
 $lateDest=$ledger->createAccount('fixture','Late destination');$ldm=$ledger->registerMarket($lateDest,'ATOM/USD','ATOM','USD');$ld=operation('late-d','deposit','2',$t+1500,[],'late-hash');$ldb=batch([],[$ld],['ATOM'=>'2'],$t+2500);$ldb['prices'][$ld['id']]=['price'=>'99','source'=>'fixture'];$r->apply($lateDest,$ldb,config($lateDest));
 $matches=(new Transfers($db,$s,$root))->autoLink();
 check(count(array_filter($matches,fn($x)=>($x['linked']??false)))===1,'late transfer finds withdrawal in immutable archive');
 check($ledger->marketLots($ldm)[0]['unit_cost']==='7','late transfer replaces estimated basis with archived original BUY cost');
 check(hash_file('sha256',$archivePath)===$archiveHash,'late cost correction preserves Hard H0 archive hash');
 // 10,000 normalized events in one account; replay must remain idempotent.
 $big=$ledger->createAccount('fixture','10000',null,['USD'=>'100000']);$bm=$ledger->registerMarket($big,'ATOM/USD','ATOM','USD');$events=[];
 for($i=0;$i<10000;$i++){$side=$i%2===0?'BUY':'SELL';$events[]=fill('event-'.$i,'order-'.$i,$side,'1',$side==='BUY'?'1':'2',$t+$i);}
 $large=batch($events,[],['USD'=>'105000'],$t+20000);$start=microtime(true);$r->apply($big,$large,config($big));$rev=$db->revision($big);$r->apply($big,$large,config($big));
 check($db->revision($big)===$rev&&$ledger->marketState($bm)['holdings']==='0','10,000 financial events reconcile and replay without duplicates');
 echo json_encode(['milestone'=>'M3','status'=>'passed','checks'=>$checks,'events'=>10000,'event_seconds'=>round(microtime(true)-$start,3),'peak_memory_bytes'=>memory_get_peak_usage(true),'network_requests'=>0,'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT)."\n";
}finally{unset($h,$r,$ledger,$math,$db);gc_collect_cycles();Backup::removeTree($root);}
