<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Backup,Database,Ledger};
use Trademinator\Client\M3\{Accounting,HardPoints,Numbers,Reconciler,Settings,Transfers};

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
    echo "PASS $message\n";
}
function refuses(callable $action, string $message): void
{
    try { $action(); } catch (Throwable) { check(true, $message); return; }
    check(false, $message);
}
function settings(string $account): array
{
    return ['account_id'=>$account,'valuation_quote'=>'USD','history_since_ms'=>0,
        'currency_markets'=>[],'public_price_exchanges'=>[],'coingecko_ids'=>[],
        'history_params'=>[],'api_key_env'=>'M3_ROLES_KEY','secret_env'=>'M3_ROLES_SECRET'];
}
function trade(string $id, string $symbol, string $side, string $amount, string $price, int $time, array $fees = []): array
{
    return ['id'=>$id,'order'=>'order-'.$id,'symbol'=>$symbol,'side'=>$side,'timestamp'=>$time,
        'amount'=>$amount,'price'=>$price,'cost'=>Numbers::decimal(bcmul($amount,$price,24)),'fees'=>$fees];
}
function batch(array $trades, array $operations, array $totals, int $time): array
{
    $balances = [];
    foreach ($totals as $currency=>$quantity) { $balances[$currency] = ['total'=>$quantity,'free'=>$quantity,'used'=>'0']; }
    $orders = array_map(static fn ($t) => ['id'=>$t['order'],'symbol'=>$t['symbol'],'side'=>$t['side'],
        'type'=>'LIMIT','status'=>'filled','amount'=>$t['amount'],'filled'=>$t['amount'],
        'price'=>$t['price'],'cost'=>$t['cost'],'timestamp'=>$t['timestamp'],'client_order_id'=>null], $trades);
    return ['at_ms'=>$time,'since_ms'=>0,'complete'=>true,'orders'=>$orders,'trades'=>$trades,
        'operations'=>$operations,'balances'=>$balances,'prices'=>[]];
}
function operation(string $id, string $kind, string $currency, string $amount, int $time, ?string $hash = null): array
{
    return ['id'=>$kind.':'.$id,'kind'=>$kind,'currency'=>$currency,'amount'=>$amount,'fees'=>[],
        'timestamp'=>$time,'txid'=>$hash,'address'=>null,'tag'=>null,'network'=>$currency];
}
function market(Accounting $accounting, string $account, string $symbol): array
{
    return $accounting->rows('SELECT * FROM markets WHERE account_id=? AND symbol=?', [$account,$symbol])[0];
}
function orderRow(Accounting $accounting, string $account, string $id): array
{
    return $accounting->rows('SELECT * FROM orders WHERE account_id=? AND exchange_order_id=?', [$account,'order-'.$id])[0];
}

$root = sys_get_temp_dir().'/trademinator-currency-roles-'.bin2hex(random_bytes(6));
Backup::ensureDirectory($root);
try {
    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    $accounting = new Accounting($db);
    $reconciler = new Reconciler($db);
    check(Database::SCHEMA_VERSION === 5 && $db->integrityCheck()['ok'], 'schema 5 supports inventory source ownership');

    // One currency can be bought in several quotes and later spent as quote.
    // These are actual exchange fills; no synthetic SELL is inserted for a BUY payment.
    $account = $ledger->createAccount('fixture','Currency roles',null,['USD'=>'1000']);
    $trades = [
        trade('doge','DOGE/USD','BUY','5','1',1000),
        trade('mxn','MXN/USD','BUY','100','0.05',2000),
        trade('btc-mxn','BTC/MXN','BUY','2','20',3000,[
            ['currency'=>'BTC','cost'=>'0.1'],['currency'=>'MXN','cost'=>'1'],['currency'=>'DOGE','cost'=>'0.5']]),
        trade('btc-usd','BTC/USD','BUY','1','3',4000),
        trade('sell','BTC/USD','SELL','1.2','4',5000,[
            ['currency'=>'BTC','cost'=>'0.1'],['currency'=>'USD','cost'=>'0.2'],['currency'=>'DOGE','cost'=>'0.25']]),
        trade('atom','ATOM/BTC','BUY','2','0.5',6000,[['currency'=>'BTC','cost'=>'0.05']]),
    ];
    $history = batch($trades,[],['USD'=>'991.6','MXN'=>'59','DOGE'=>'4.25','BTC'=>'0.55','ATOM'=>'2'],7000);
    check($reconciler->apply($account,$history,settings($account))['reconciled'], 'base and quote currency roles reconcile all wallet legs');
    $btcMxn = market($accounting,$account,'BTC/MXN');
    $btcUsd = market($accounting,$account,'BTC/USD');
    $mxn = market($accounting,$account,'MXN/USD');
    $doge = market($accounting,$account,'DOGE/USD');
    check($ledger->marketState($btcMxn['id'])['holdings'] === '0.55'
        && $ledger->marketState($btcUsd['id'])['holdings'] === '0', 'cross-market SELL and quote spending use global currency LIFO');
    check($ledger->marketState($mxn['id'])['holdings'] === '59'
        && $ledger->marketState($doge['id'])['holdings'] === '4.25', 'quote fees and third-currency fees consume the correct inventory');
    $btcLot = $ledger->marketLots($btcMxn['id'])[0];
    check($btcLot['origin']['quote_currency'] === 'MXN' && $btcLot['acquisition_cost'] === '41'
        && $btcLot['acquisition_quantity'] === '1.9', 'surviving BUY lot retains its original quote cost and net acquisition');
    $sell = orderRow($accounting,$account,'sell');
    $allocations = $accounting->rows('SELECT * FROM buys_vs_sells WHERE sell_order_id=? ORDER BY quantity',[$sell['id']]);
    $allocated = [];
    foreach ($allocations as $allocation) { $allocated[$allocation['inventory_market_id']] = $allocation['quantity']; }
    check(count($allocations) === 2 && ($allocated[$btcUsd['id']] ?? null) === '1'
        && ($allocated[$btcMxn['id']] ?? null) === '0.3'
        && array_unique(array_column($allocations,'market_id')) === [$btcUsd['id']], 'SELL allocations preserve both selling market and source inventory market');
    check($db->integrityCheck()['ok'], 'cross-market SELL allocation foreign keys remain valid');
    $atom = orderRow($accounting,$account,'atom');
    $atomDebits = Numbers::decode($atom['metadata_json'])['m3']['inventory_debits'][$btcMxn['id']] ?? [];
    check(count($atomDebits) === 1 && array_values($atomDebits) === ['1.05'], 'BUY quote principal and quote fee are attributed without a fictitious SELL');
    check(count($accounting->rows('SELECT * FROM orders WHERE account_id=? AND side=\'SELL\'',[$account])) === 1
        && count($accounting->rows('SELECT * FROM orders WHERE account_id=?',[$account])) === 6, 'history preserves exactly the six exchange orders');
    $revision = $db->revision($account);
    $financialRows = $accounting->rows('SELECT * FROM orders WHERE account_id=? ORDER BY id',[$account]);
    $reconciler->apply($account,$history,settings($account));
    check($db->revision($account) === $revision
        && $accounting->rows('SELECT * FROM orders WHERE account_id=? ORDER BY id',[$account]) === $financialRows,
        'repeated complete history leaves financial records unchanged');
    unset($reconciler,$accounting,$ledger,$db);
    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db); $accounting = new Accounting($db); $reconciler = new Reconciler($db);
    check($reconciler->apply($account,$history,settings($account))['reconciled']
        && $db->revision($account) === $revision && $ledger->marketState($btcMxn['id'])['holdings'] === '0.55', 'restart and replay preserve currency inventory and financial revision');

    // A later acquisition cannot finance an earlier spend, even if totals would balance.
    $early = $ledger->createAccount('fixture','Chronology',null,['USD'=>'100','BTC'=>'1']);
    $ledger->registerMarket($early,'BTC/USD','BTC','USD',null,['opening_lots'=>[
        ['id'=>'known-btc','quantity'=>'1','unit_cost'=>'10','acquired_at'=>'1970-01-01T00:00:00Z']]]);
    $ledger->registerMarket($early,'MXN/USD','MXN','USD');
    $earlyHistory = batch([
        trade('spend-before-credit','BTC/MXN','BUY','1','10',1000),
        trade('late-credit','BTC/MXN','SELL','1','20',2000),
    ],[],['USD'=>'100','BTC'=>'1','MXN'=>'10'],3000);
    refuses(fn () => $reconciler->apply($early,$earlyHistory,settings($early)), 'future SELL quote receipts cannot cover an earlier BUY payment');
    check($accounting->rows('SELECT id FROM orders WHERE account_id=?',[$early]) === [], 'chronology failure rolls back imported financial records');

    // Exchange IDs have no chronological meaning when timestamp precision ties.
    $sameTime = $ledger->createAccount('fixture','Same-time quote credit',null,['BTC'=>'1']);
    $ledger->registerMarket($sameTime,'BTC/USD','BTC','USD',null,['opening_lots'=>[
        ['id'=>'same-time-btc','quantity'=>'1','unit_cost'=>'10','acquired_at'=>'1970-01-01T00:00:00Z']]]);
    $sameTimeMxn = $ledger->registerMarket($sameTime,'MXN/USD','MXN','USD');
    $sameTimeHistory = batch([
        trade('a-quote-spend','ATOM/MXN','BUY','2','5',1000),
        trade('z-quote-credit','BTC/MXN','SELL','1','20',1000),
    ],[],['MXN'=>'10','ATOM'=>'2'],2000);
    check($reconciler->apply($sameTime,$sameTimeHistory,settings($sameTime))['reconciled'],
        'same-time funded SELL quote credit can finance a BUY with an earlier opaque ID');
    $sameTimeBuyer = orderRow($accounting,$sameTime,'a-quote-spend');
    $sameTimeSeller = orderRow($accounting,$sameTime,'z-quote-credit');
    $sameTimeDebits = Numbers::decode($sameTimeBuyer['metadata_json'])['m3']['inventory_debits'][$sameTimeMxn] ?? [];
    check($sameTimeDebits === ['order:'.$sameTimeSeller['id'].':quote:z-quote-credit'=>'10'],
        'same-time payment records the actual funding SELL quote lot');
    $sameTimeRevision = $db->revision($sameTime);
    check($reconciler->apply($sameTime,$sameTimeHistory,settings($sameTime))['reconciled']
        && $db->revision($sameTime) === $sameTimeRevision,
        'same-time dependency resolution is financially idempotent');

    $sameTimeFees = $ledger->createAccount('fixture','Same-time third fee',null,['USD'=>'1','DOGE'=>'1']);
    $ledger->registerMarket($sameTimeFees,'DOGE/USD','DOGE','USD',null,['opening_lots'=>[
        ['id'=>'same-time-fee-doge','quantity'=>'1','unit_cost'=>'1','acquired_at'=>'1970-01-01T00:00:00Z']]]);
    $sameTimeFeeMarket = $ledger->registerMarket($sameTimeFees,'CCC/USD','CCC','USD');
    $sameTimeFeeHistory = batch([
        trade('a-main-buy','BBB/USD','BUY','1','1',1000,[['currency'=>'CCC','cost'=>'1']]),
        trade('b-following-buy','ATOM/BBB','BUY','1','1',1000),
        trade('z-fee-credit','DOGE/CCC','SELL','1','1',1000),
    ],[],['ATOM'=>'1'],2000);
    check($reconciler->apply($sameTimeFees,$sameTimeFeeHistory,settings($sameTimeFees))['reconciled'],
        'same-time execution waits for its third-currency fee before releasing its acquisition');
    $sameTimeMain = orderRow($accounting,$sameTimeFees,'a-main-buy');
    $sameTimeFeeSource = orderRow($accounting,$sameTimeFees,'z-fee-credit');
    $feePayment = Numbers::decode($sameTimeMain['metadata_json'])['m3']['inventory_debits'][$sameTimeFeeMarket] ?? [];
    check($feePayment === ['order:'.$sameTimeFeeSource['id'].':quote:z-fee-credit'=>'1']
        && $ledger->marketState(market($accounting,$sameTimeFees,'BBB/USD')['id'])['holdings'] === '0',
        'same-time fee chain preserves actual fee source and spends the funded BUY credit once');
    $sameTimeFeeRevision = $db->revision($sameTimeFees);
    check($reconciler->apply($sameTimeFees,$sameTimeFeeHistory,settings($sameTimeFees))['reconciled']
        && $db->revision($sameTimeFees) === $sameTimeFeeRevision,
        'same-time third-fee dependency chain remains stable on replay');

    $cycle = $ledger->createAccount('fixture','Unfunded same-time cycle');
    $cycleHistory = batch([
        trade('a-cycle','BBB/CCC','BUY','1','1',1000),
        trade('z-cycle','CCC/BBB','BUY','1','1',1000),
    ],[],[],2000);
    refuses(fn () => $reconciler->apply($cycle,$cycleHistory,settings($cycle)),
        'same-time reciprocal BUY cycle cannot fund itself without starting inventory');
    check($accounting->rows('SELECT id FROM orders WHERE account_id=?',[$cycle]) === []
        && $ledger->expectedBalances($cycle) === [], 'unfunded same-time cycle rolls back all imported trades');

    $feeCycle = $ledger->createAccount('fixture','Unfunded same-time fee cycle',null,['USD'=>'1']);
    $ledger->registerMarket($feeCycle,'CCC/USD','CCC','USD');
    $feeCycleHistory = batch([
        trade('a-fee-cycle','BBB/USD','BUY','1','1',1000,[['currency'=>'CCC','cost'=>'1']]),
        trade('z-fee-cycle','CCC/BBB','BUY','1','1',1000),
    ],[],[],2000);
    refuses(fn () => $reconciler->apply($feeCycle,$feeCycleHistory,settings($feeCycle)),
        'a BUY credit cannot fund another trade that circularly supplies its own third-currency fee');
    check($accounting->rows('SELECT id FROM orders WHERE account_id=?',[$feeCycle]) === []
        && $ledger->expectedBalances($feeCycle) === ['USD'=>'1'], 'unfunded fee cycle preserves the original wallet and rejects both trades');

    $feeAccount = $ledger->createAccount('fixture','Quote receipt fees',null,['USD'=>'100']);
    $ledger->registerMarket($feeAccount,'USD/MXN','USD','MXN',null,['opening_lots'=>[
        ['id'=>'fee-usd','quantity'=>'100','unit_cost'=>'20','acquired_at'=>'1970-01-01T00:00:00Z']]]);
    $feeMxn = $ledger->registerMarket($feeAccount,'MXN/USD','MXN','USD',null,['allow_currency_roles'=>true]);
    $feeTrades = [
        trade('quote-net','USD/MXN','SELL','10','20',1000,[['currency'=>'USD','cost'=>'1'],['currency'=>'MXN','cost'=>'2']]),
        trade('quote-spend','USD/MXN','BUY','1','20',2000,[['currency'=>'USD','cost'=>'0.1'],['currency'=>'MXN','cost'=>'1']]),
    ];
    check($reconciler->apply($feeAccount,batch($feeTrades,[],['USD'=>'89.9','MXN'=>'177'],3000),settings($feeAccount))['reconciled'],
        'net SELL quote receipt can fund a later BUY including both quote fees');
    $feeQuote = $ledger->marketLots($feeMxn)[0];
    check($feeQuote['quantity'] === '177' && $feeQuote['acquisition_quantity'] === '198'
        && $feeQuote['acquisition_cost'] === '11', 'quote acquisition excludes quote fee and includes actual base debit in reciprocal cost');

    // An unvalued new receipt must invalidate a formerly current checkpoint.
    $checkpointAccount = $ledger->createAccount('fixture','Quote checkpoint',null,['BTC'=>'2','MXN'=>'20']);
    $ledger->registerMarket($checkpointAccount,'BTC/USD','BTC','USD',null,['opening_lots'=>[
        ['id'=>'checkpoint-btc','quantity'=>'2','unit_cost'=>'10','acquired_at'=>'1970-01-01T00:00:00Z']]]);
    $checkpointMxn = $ledger->registerMarket($checkpointAccount,'MXN/USD','MXN','USD',null,['opening_lots'=>[
        ['id'=>'checkpoint-mxn','quantity'=>'20','unit_cost'=>'0.05','acquired_at'=>'1970-01-01T00:00:00Z']]]);
    $reconciler->apply($checkpointAccount,batch([],[],['BTC'=>'2','MXN'=>'20'],1000),settings($checkpointAccount));
    $beforeReceipt = $ledger->marketState($checkpointMxn);
    check($beforeReceipt['checkpoint_current'], 'known inventory starts with a current checkpoint');
    $checkpointHistory = batch([trade('checkpoint-receipt','BTC/MXN','SELL','1','20',2000)],[],['BTC'=>'1','MXN'=>'40'],3000);
    check($reconciler->apply($checkpointAccount,$checkpointHistory,settings($checkpointAccount))['reconciled'],
        'unknown quote valuation does not fabricate a wallet discrepancy');
    $afterReceipt = $ledger->marketState($checkpointMxn);
    check(!$afterReceipt['acquisition_cost_known'] && !$afterReceipt['checkpoint_current']
        && $afterReceipt['activity_revision'] > $beforeReceipt['activity_revision'], 'new quote inventory invalidates its owner checkpoint until cost is known');
    check(!(new HardPoints($db,$root))->capture($checkpointAccount)['eligible'], 'unknown quote cost prevents Hard H promotion eligibility');
    $receiptRevision = $db->revision($checkpointAccount);
    $reconciler->apply($checkpointAccount,$checkpointHistory,settings($checkpointAccount));
    check($db->revision($checkpointAccount) === $receiptRevision, 'duplicate quote receipt does not repeatedly invalidate financial revisions');

    $valuedCash = $ledger->createAccount('fixture','Valuation quote inventory');
    $valuedMarket = $ledger->registerMarket($valuedCash,'USD/MXN','USD','MXN');
    $valuedDeposit = operation('valuation-deposit','deposit','USD','10',1000);
    $valuedBatch = batch([],[$valuedDeposit],['USD'=>'10'],2000);
    $valuedBatch['prices'][$valuedDeposit['id']] = ['price'=>'20','source'=>'synthetic-MXN-basis'];
    check($reconciler->apply($valuedCash,$valuedBatch,settings($valuedCash))['reconciled']
        && $ledger->marketLots($valuedMarket)[0]['unit_cost'] === '20', 'valuation quote deposits use registered inventory and its actual quote basis');

    // Cash operations may predate the first market that makes their currency inventory.
    // The later incremental response deliberately does not repeat those operations.
    $newRole = $ledger->createAccount('fixture','New currency role');
    $cashDeposit = operation('former-cash-in','deposit','USD','50',1000);
    $cashWithdrawal = operation('former-cash-out','withdrawal','USD','5',2000);
    check($reconciler->apply($newRole,batch([],[$cashDeposit,$cashWithdrawal],['USD'=>'45'],2500),settings($newRole))['reconciled'],
        'cash deposits and withdrawals reconcile before their currency has an inventory market');
    $cashRows = $accounting->rows('SELECT * FROM operations WHERE account_id=? ORDER BY occurred_at',[$newRole]);
    check(array_column($cashRows,'market_id') === [null,null], 'earlier valuation-currency cash operations initially have no inventory owner');
    $ledger->registerMarket($newRole,'MXN/USD','MXN','USD');
    $incremental = batch([trade('new-currency-role','USD/MXN','SELL','10','20',3000)],[],['USD'=>'35','MXN'=>'200'],4000);
    check($reconciler->apply($newRole,$incremental,settings($newRole))['reconciled'],
        'new base role reconstructs earlier cash operations absent from the incremental response');
    $reattributed = $accounting->rows('SELECT * FROM operations WHERE account_id=? ORDER BY occurred_at',[$newRole]);
    $newOwner = market($accounting,$newRole,'USD/MXN');
    $sameEvidence = true;
    foreach ($cashRows as $index=>$cashRow) {
        foreach (['id','exchange_operation_id','kind','currency','amount','occurred_at'] as $field) {
            $sameEvidence = $sameEvidence && $cashRow[$field] === $reattributed[$index][$field];
        }
    }
    check($sameEvidence && array_column($reattributed,'market_id') === [$newOwner['id'],$newOwner['id']],
        'cash reattribution preserves operation identities, amounts, and original timestamps');
    $newRoleLot = $ledger->marketLots($newOwner['id'])[0];
    check($newRoleLot['quantity'] === '35' && $newRoleLot['unit_cost'] === null,
        'reattributed cash deposit keeps unknown acquisition cost after withdrawal and SELL');
    $newRoleRevision = $db->revision($newRole);
    check($reconciler->apply($newRole,$incremental,settings($newRole))['reconciled']
        && $db->revision($newRole) === $newRoleRevision
        && count($accounting->rows('SELECT id FROM operations WHERE account_id=?',[$newRole])) === 2,
        'new currency role replay does not duplicate cash history or revise it again');

    // Withdrawals consume all markets holding the currency; quote conversions are per lot.
    $source = $ledger->createAccount('fixture','Mixed source',null,['USD'=>'100']);
    $mixedTrades = [trade('mixed-mxn','MXN/USD','BUY','100','0.05',1000),
        trade('mixed-btc-usd','BTC/USD','BUY','1','10',2000),
        trade('mixed-btc-mxn','BTC/MXN','BUY','1','20',3000)];
    $withdrawal = operation('mixed','withdrawal','BTC','1.5',4000,'synthetic-mixed-transfer');
    check($reconciler->apply($source,batch($mixedTrades,[$withdrawal],['USD'=>'85','MXN'=>'80','BTC'=>'0.5'],5000),settings($source))['reconciled'], 'withdrawal reconciles currency held across multiple markets');
    $withdrawalRow = $accounting->rows('SELECT * FROM operations WHERE account_id=?',[$source])[0];
    $consumptions = array_values(Numbers::decode($withdrawalRow['lot_consumptions_json']));
    check($consumptions === ['1','0.5'], 'withdrawal consumes newest acquisition before the older market lot');
    $destination = $ledger->createAccount('fixture','Mixed destination');
    $destinationMarket = $ledger->registerMarket($destination,'BTC/USD','BTC','USD');
    $deposit = operation('mixed','deposit','BTC','1.5',4500,'synthetic-mixed-transfer');
    $received = batch([],[$deposit],['BTC'=>'1.5'],5500);
    $received['prices'][$deposit['id']] = ['price'=>'99','source'=>'synthetic'];
    $reconciler->apply($destination,$received,settings($destination));
    $depositRow = $accounting->rows('SELECT * FROM operations WHERE account_id=?',[$destination])[0];
    $transferSettings = Settings::validate(['accounts'=>[settings($source),settings($destination)]]);
    $transfers = new Transfers($db,$transferSettings);
    refuses(fn () => $transfers->link($withdrawalRow['id'],$depositRow['id'],true), 'known MXN cost cannot silently be relabelled as USD on transfer');
    $depositMeta = Numbers::decode($depositRow['metadata_json']);
    $depositMeta['m3']['fx_rates']['MXN'] = ['quote'=>'USD','price'=>'0.05','source'=>'synthetic-audited-rate'];
    $accounting->execute('UPDATE operations SET metadata_json=? WHERE id=?',[Numbers::json($depositMeta),$depositRow['id']]);
    check($transfers->link($withdrawalRow['id'],$depositRow['id'],true)['linked'], 'mixed-market transfer accepts an audited conversion for only the foreign-quote lot');
    $receivedLots = $ledger->marketLots($destinationMarket);
    $costs = array_column($receivedLots,'unit_cost'); sort($costs);
    check(count($receivedLots) === 2 && $costs === ['1','10'], 'transfer retains each originating BUY basis using its own quote denomination');
    check(count(array_unique(array_column(array_column($receivedLots,'origin'),'inventory_market_id'))) === 2, 'transfer audit retains the two distinct source inventory owners');

    // The reciprocal market provides an exact cost for a SELL quote acquisition.
    $hardAccount = $ledger->createAccount('fixture','Reciprocal hard point',null,['USD'=>'100']);
    $usd = $ledger->registerMarket($hardAccount,'USD/MXN','USD','MXN',null,['opening_lots'=>[
        ['id'=>'known-usd','quantity'=>'100','unit_cost'=>'20','acquired_at'=>'1970-01-01T00:00:00Z']]]);
    $hardMxn = $ledger->registerMarket($hardAccount,'MXN/USD','MXN','USD',null,['allow_currency_roles'=>true]);
    $conversion = trade('reciprocal','USD/MXN','SELL','10','20',1000);
    $hardHistory = batch([$conversion],[],['USD'=>'90','MXN'=>'200'],2000);
    check($reconciler->apply($hardAccount,$hardHistory,settings($hardAccount))['reconciled'], 'SELL credits tracked quote inventory in a reciprocal market');
    $quoteLot = $ledger->marketLots($hardMxn)[0];
    check($quoteLot['quantity'] === '200' && $quoteLot['unit_cost'] === '0.05'
        && str_contains($quoteLot['source_key'],':quote:reciprocal'), 'reciprocal quote lot records exact acquisition basis and deterministic source identity');
    $hardPoints = new HardPoints($db,$root);
    $candidate = $hardPoints->capture($hardAccount);
    check($candidate['eligible'] && $candidate['verification'] === 'pending', 'currency-role inventory can produce an eligible frozen H candidate');
    $hardHistory['at_ms'] = $candidate['cutoff_ms'] + 1;
    check($hardPoints->verifyPending($hardAccount,$hardHistory)['verification'] === 'verified', 'H1 reconstructs reciprocal quote acquisitions');
    $post = trade('post-snapshot','USD/MXN','BUY','2','20',$candidate['cutoff_ms']+2);
    $postHistory = batch([$conversion,$post],[],['USD'=>'92','MXN'=>'160'],$candidate['cutoff_ms']+3);
    check($reconciler->apply($hardAccount,$postHistory,settings($hardAccount))['reconciled'], 'post-snapshot BUY consumes the frozen quote acquisition');
    $beforePromotion = $ledger->expectedBalances($hardAccount);
    check($hardPoints->promote($hardAccount,$candidate['token'])['active']
        && $ledger->expectedBalances($hardAccount) === $beforePromotion, 'promotion preserves wallet activity after the quote lot snapshot');
    $carried = $ledger->marketLots($hardMxn)[0];
    check($carried['quantity'] === '160' && $carried['unit_cost'] === '0.05'
        && $carried['source_type'] === 'baseline', 'post-snapshot quote consumption remaps to the carried acquisition lot');
    check(count($accounting->rows('SELECT * FROM orders WHERE account_id=?',[$hardAccount])) === 1
        && $db->integrityCheck()['ok'], 'promotion archives only the prefix and preserves allocation integrity');

    // Unknown quote cost remains unknown after archival and late transfer matching.
    $late = $ledger->createAccount('fixture','Late quote source',null,['USD'=>'100']);
    $lateMxn = $ledger->registerMarket($late,'MXN/USD','MXN','USD');
    $lateTrades = [trade('late-btc','BTC/USD','BUY','1','10',1000),
        trade('late-sale','BTC/MXN','SELL','1','20',2000)];
    $lateWithdrawal = operation('late-quote','withdrawal','MXN','20',3000,'synthetic-quote-transfer');
    $lateHistory = batch($lateTrades,[$lateWithdrawal],['USD'=>'90'],4000);
    check($reconciler->apply($late,$lateHistory,settings($late))['reconciled'], 'SELL quote proceeds can be withdrawn completely without inventing their valuation cost');
    $rawQuote = $ledger->marketLots($lateMxn,false)[0];
    check($rawQuote['unit_cost'] === null && $rawQuote['cost_amount'] === null
        && $ledger->marketLots($lateMxn) === [], 'raw acquisitions retain depleted quote provenance and unknown cost');
    $latePoints = new HardPoints($db,$root); $lateCandidate = $latePoints->capture($late);
    check($lateCandidate['eligible'], 'fully consumed unknown-cost receipts do not block an otherwise reconciled snapshot');
    $lateHistory['at_ms'] = $lateCandidate['cutoff_ms']+1;
    check($latePoints->verifyPending($late,$lateHistory)['verification'] === 'verified', 'H1 verifies fully consumed quote inventory');
    $latePoints->promote($late,$lateCandidate['token']);
    $archive = $root.'/hard-points/'.$late.'/'.$lateCandidate['id'].'/hard.json';
    $archiveHash = hash_file('sha256',$archive);
    $lateDestination = $ledger->createAccount('fixture','Late quote destination');
    $lateTarget = $ledger->registerMarket($lateDestination,'MXN/EUR','MXN','EUR');
    $lateDeposit = operation('late-quote','deposit','MXN','20',3500,'synthetic-quote-transfer');
    $lateReceived = batch([],[$lateDeposit],['MXN'=>'20'],4500);
    $lateReceived['prices'][$lateDeposit['id']] = ['price'=>'99','source'=>'synthetic-estimate'];
    $reconciler->apply($lateDestination,$lateReceived,settings($lateDestination));
    $lateTransferSettings = Settings::validate(['accounts'=>[settings($late),settings($lateDestination)]]);
    $links = (new Transfers($db,$lateTransferSettings,$root))->autoLink();
    check(count(array_filter($links,static fn ($link) => $link['linked'] ?? false)) === 1, 'late transfer finds a fully consumed SELL quote lot in the archive');
    $lateLot = $ledger->marketLots($lateTarget)[0];
    check($lateLot['quantity'] === '20' && $lateLot['unit_cost'] === null
        && $lateLot['origin']['original_unit_cost'] === null && $lateLot['origin']['conversion'] === null,
        'late transfer keeps unknown source basis across quote currencies without inventing FX');
    check(hash_file('sha256',$archive) === $archiveHash && $db->integrityCheck()['ok'], 'late quote transfer preserves the immutable archive and live foreign keys');
    echo json_encode(['suite'=>'m3-currency-roles','status'=>'passed','checks'=>$checks,
        'network_requests'=>0,'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT)."\n";
} finally {
    unset($hardPoints,$latePoints,$transfers,$reconciler,$accounting,$ledger,$db);
    gc_collect_cycles();
    Backup::removeTree($root);
}
