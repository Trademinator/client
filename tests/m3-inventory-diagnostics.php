<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Database,Ledger};
use Trademinator\Client\M3\{Accounting,InventoryEvidenceError,Numbers,Reconciler,Settings,SyncDiagnostics};

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks; echo 'PASS '.$message.PHP_EOL;
}
function op(string $id, string $kind, string $currency, string $amount, int $at): array
{
    return ['id'=>$id,'kind'=>$kind,'currency'=>$currency,'amount'=>$amount,'fees'=>[],
        'timestamp'=>$at,'txid'=>'PRIVATE_HASH','address'=>'PRIVATE_ADDRESS','tag'=>'PRIVATE_MEMO','network'=>$currency];
}
function snapshot(array $operations, array $trades = []): array
{
    return ['complete'=>true,'at_ms'=>4000,'balances'=>[], 'operations'=>$operations,'trades'=>$trades,
        'orders'=>array_map(static fn ($t)=>['id'=>$t['order'],'symbol'=>$t['symbol'],'side'=>$t['side'],
            'type'=>'LIMIT','status'=>'filled','amount'=>$t['amount'],'filled'=>$t['amount'],'price'=>$t['price'],
            'cost'=>$t['cost'],'timestamp'=>$t['timestamp'],'client_order_id'=>null],$trades)];
}
function failure(callable $action): InventoryEvidenceError
{
    try { $action(); } catch (InventoryEvidenceError $error) { return $error; }
    throw new RuntimeException('Expected an inventory evidence failure.');
}
function fill(string $symbol, string $side, string $amount, string $price, array $fees = []): array
{
    return ['id'=>'PRIVATE_TRADE','order'=>'PRIVATE_ORDER','symbol'=>$symbol,'side'=>$side,'amount'=>$amount,
        'price'=>$price,'cost'=>Numbers::decimal(bcmul($amount,$price,24)),'fees'=>$fees,'timestamp'=>2000];
}
$db = new Database(':memory:'); $ledger = new Ledger($db); $accounting = new Accounting($db); $reconciler = new Reconciler($db);
$id = $ledger->createAccount('fixture','Inventory evidence');
$settings = Settings::validate(['accounts'=>[['account_id'=>$id,'history_since_ms'=>0,'api_key_env'=>'UNUSED','secret_env'=>'UNUSED']]])['accounts'][0];
$batch = snapshot([op('PRIVATE_DEPOSIT','deposit','BTC','1',1000),op('PRIVATE_WITHDRAWAL','withdrawal','BTC','1.25',2000),op('PRIVATE_LATER','deposit','BTC','0.25',3000)]);
$before = $ledger->accountSnapshot($id);
$error = failure(fn()=>$reconciler->validateInventory($id,$batch,$settings)); $d = SyncDiagnostics::describe($error,'fixture');
check($d['reason']==='inventory_shortfall' && $d['outflow_kind']==='withdrawal' && $d['outflow_currency']==='BTC', 'withdrawal shortfall identifies its kind and currency');
check($d['outflow_required']==='1.25' && $d['inventory_available']==='1' && $d['inventory_shortfall']==='0.25', 'diagnostic reports exact required available and missing amounts');
check($d['outflow_at']===Numbers::iso(2000) && $d['later_acquisition_quantity']==='0.25' && $d['next_acquisition_at']===Numbers::iso(3000), 'later deposits are explained without being backdated to fund a withdrawal');
check(str_contains($d['detail'],'1.25 BTC') && str_contains($d['detail'],'shortfall 0.25 BTC'), 'console description includes actionable amounts');
check(strlen($d['outflow_ref'])===16 && !str_contains(json_encode($d),'PRIVATE_') && !str_contains(json_encode($d),dirname(__DIR__)), 'diagnostics exclude raw identities addresses memos hashes and installation paths');
check($before===$ledger->accountSnapshot($id), 'failed preflight leaves every live account row unchanged');
$second = failure(fn()=>$reconciler->validateInventory($id,$batch,$settings));
check($second->diagnostic['outflow_ref']===$d['outflow_ref'], 'outflow reference is stable across discarded preview databases');
$live = failure(fn()=>$reconciler->apply($id,$batch,$settings));
check($live->diagnostic===$error->diagnostic && $accounting->rows('SELECT id FROM operations')===[] && $accounting->rows('SELECT id FROM markets')===[], 'live apply gives the same evidence and rolls back failed financial imports');

$accounting->market($id,'MXN/USD');
$quote = snapshot([op('PRIVATE_MXN','deposit','MXN','9',1000)], [fill('ATOM/MXN','BUY','1','10')]);
$d = failure(fn()=>$reconciler->validateInventory($id,$quote,$settings))->diagnostic;
check($d['outflow_kind']==='quote_spend' && $d['symbol']==='ATOM/MXN' && $d['inventory_shortfall']==='1', 'BUY quote debit identifies its actual pair and payment shortage');
$accounting->market($id,'ETH/USD');
$fee = snapshot([op('PRIVATE_ETH_LATER','deposit','ETH','0.1',3000)], [fill('BTC/USD','BUY','1','10',[['currency'=>'ETH','cost'=>'0.1']])]);
$d = failure(fn()=>$reconciler->validateInventory($id,$fee,$settings))->diagnostic;
check($d['outflow_kind']==='fee' && $d['outflow_currency']==='ETH' && $d['inventory_shortfall']==='0.1', 'third-currency fee failure identifies the fee rather than the traded asset');
$sell = snapshot([op('PRIVATE_BTC','deposit','BTC','1',1000)], [fill('BTC/USD','SELL','2','10')]);
$d = failure(fn()=>$reconciler->validateInventory($id,$sell,$settings))->diagnostic;
check($d['outflow_kind']==='sell' && $d['outflow_required']==='2' && $d['inventory_available']==='1', 'SELL failure exposes available acquisition quantity');
$precision = snapshot([op('PRIVATE_TINY','deposit','BTC','0.000000000000000000000001',1000),op('PRIVATE_TINY_OUT','withdrawal','BTC','0.000000000000000000000002',2000)]);
$d = failure(fn()=>$reconciler->validateInventory($id,$precision,$settings))->diagnostic;
check($d['inventory_shortfall']==='0.000000000000000000000001', 'shortfall diagnostics retain all 24 decimal places');
$good = snapshot([op('PRIVATE_GOOD','deposit','BTC','1',1000),op('PRIVATE_GOOD_OUT','withdrawal','BTC','0.25',2000)]);
$before = $ledger->accountSnapshot($id); $reconciler->validateInventory($id,$good,$settings);
check($before===$ledger->accountSnapshot($id), 'successful preflight also leaves the live ledger untouched');
$good['balances']=['BTC'=>['total'=>'0.75','free'=>'0.75','used'=>'0']];
check($reconciler->apply($id,$good,$settings)['reconciled'], 'successful preview does not replace actual wallet reconciliation');
check(!$ledger->marketState($accounting->currencyMarket($id,'BTC',$settings)['id'])['acquisition_cost_known'], 'quantity validation never invents a missing cost basis');
$unknown = SyncDiagnostics::describe(new RuntimeException('PRIVATE_ERROR'),'fixture');
check($unknown['reason']==='synchronization_error' && !str_contains(json_encode($unknown),'PRIVATE_'), 'unrecognized errors still withhold raw text');
echo json_encode(['suite'=>'m3-inventory-diagnostics','status'=>'passed','checks'=>$checks,'network_requests'=>0],JSON_PRETTY_PRINT).PHP_EOL;
