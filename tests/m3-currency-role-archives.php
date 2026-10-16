<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Backup,Database,Ledger};
use Trademinator\Client\M3\{Accounting,HardPoints,History,Numbers,Reconciler,Settings,Transfers};

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
    echo "PASS $message\n";
}
function settings(string $account, string $quote = 'USD'): array
{
    return ['account_id'=>$account,'valuation_quote'=>$quote,'history_since_ms'=>0,
        'currency_markets'=>[],'public_price_exchanges'=>[],'coingecko_ids'=>[],
        'history_params'=>[],'api_key_env'=>'M3_ARCHIVE_KEY','secret_env'=>'M3_ARCHIVE_SECRET'];
}
function trade(string $id, string $symbol, string $side, string $amount, string $price, int $time): array
{
    return ['id'=>$id,'order'=>'order-'.$id,'symbol'=>$symbol,'side'=>$side,'timestamp'=>$time,
        'amount'=>$amount,'price'=>$price,'cost'=>Numbers::decimal(bcmul($amount,$price,24)),'fees'=>[]];
}
function operation(string $id, string $kind, string $amount, int $time, ?string $hash = null): array
{
    return ['id'=>$kind.':'.$id,'kind'=>$kind,'currency'=>'USD','amount'=>$amount,'fees'=>[],
        'timestamp'=>$time,'txid'=>$hash,'address'=>null,'tag'=>null,'network'=>'USD'];
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

$root = sys_get_temp_dir().'/trademinator-currency-archives-'.bin2hex(random_bytes(6));
Backup::ensureDirectory($root);
try {
    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db); $math = new Accounting($db); $reconciler = new Reconciler($db);
    $account = $ledger->createAccount('fixture','Currency introduced after H0',null,['USD'=>'100']);
    $math->execute('UPDATE exchange_accounts SET created_at=? WHERE id=?',['1970-01-01T00:00:00.000000Z',$account]);
    $buy = trade('old-buy','BTC/USD','BUY','5','10',1000);
    $sell = trade('old-sell','BTC/USD','SELL','5','12',2000);
    $oldDeposit = operation('old-cash','deposit','5',3000);
    $oldWithdrawal = operation('old-cash','withdrawal','3',4000);
    $original = batch([$buy,$sell],[$oldDeposit,$oldWithdrawal],['USD'=>'112'],5000);
    check($reconciler->apply($account,$original,settings($account))['reconciled'], 'untracked cash history reconciles before its first inventory market');
    check(array_column($math->rows('SELECT market_id FROM operations WHERE account_id=?',[$account]),'market_id') === [null,null],
        'original cash deposits and withdrawals remain account-level evidence');
    $points = new HardPoints($db,$root); $first = $points->capture($account);
    check($first['eligible'], 'cash-only balance permits a frozen H candidate');
    $original['at_ms'] = $first['cutoff_ms']+1;
    check($points->verifyPending($account,$original)['verification'] === 'verified', 'fresh H1 verifies the original cash-only history');
    $points->promote($account,$first['token']);
    $firstArchive = $root.'/hard-points/'.$account.'/'.$first['id'].'/hard.json';
    $firstHash = hash_file('sha256',$firstArchive);

    // USD was a quote only until this new market. Its H0 cash must be seeded
    // once in live history, and not counted again in expanded original history.
    $conversion = trade('new-market','USD/MXN','SELL','12','20',$first['cutoff_ms']+2);
    $current = batch([$conversion],[],['USD'=>'100','MXN'=>'240'],$first['cutoff_ms']+3);
    check($reconciler->apply($account,$current,settings($account))['reconciled'], 'new inventory market consumes carried H0 cash');
    $usd = $math->rows('SELECT * FROM markets WHERE account_id=? AND symbol=?',[$account,'USD/MXN'])[0];
    $seed = Numbers::decode($usd['baseline_lots_json'])[0];
    check($seed['quantity'] === '112' && $seed['origin']['kind'] === 'hard_point_cash'
        && $seed['origin']['hardpoint_id'] === $first['id']
        && $seed['acquired_at'] === Numbers::iso($first['cutoff_ms'])
        && $seed['available_at'] === $seed['acquired_at'], 'new cash seed records its exact hard-point origin and availability');
    $history = new History($db,$root); $expanded = $history->expand(); $fullLedger = new Ledger($expanded);
    check($fullLedger->expectedBalances($account) === $ledger->expectedBalances($account)
        && $fullLedger->accountState($account)['inventory_balanced'], 'expanded archive reconstructs the same wallet without counting H0 cash twice');
    check($fullLedger->marketState($usd['id'])['holdings'] === $ledger->marketState($usd['id'])['holdings'],
        'expanded and live newly tracked currency holdings agree');
    $fullMath = new Accounting($expanded);
    $fullSeed = Numbers::decode($fullMath->rows('SELECT baseline_lots_json FROM markets WHERE id=?',[$usd['id']])[0]['baseline_lots_json'])[0];
    check($fullSeed['id'] === $seed['id'] && $fullSeed['quantity'] === '100' && $fullSeed['unit_cost'] === null
        && $fullSeed['origin']['reconstructed_from'] === $seed['origin'], 'archive expansion restores the original opening amount with stable identity and audit');
    check(array_unique(array_column($fullMath->rows('SELECT market_id FROM operations WHERE account_id=?',[$account]),'market_id')) === [$usd['id']],
        'archived account-level cash operations acquire the new inventory owner during replay');
    check(hash_file('sha256',$firstArchive) === $firstHash, 'cash reconstruction leaves the original archive immutable');
    $history->apply($expanded,'cash_inventory_reconstruction_test');
    $afterCorrection = $history->expand(); $correctedLedger = new Ledger($afterCorrection);
    check($correctedLedger->expectedBalances($account) === $ledger->expectedBalances($account)
        && $correctedLedger->accountState($account)['inventory_balanced']
        && $correctedLedger->marketState($usd['id'])['holdings'] === '100',
        'repeated archive expansion retains cash-seed evidence after a cost correction');

    // Promote again after the newly tracked currency is fully withdrawn. The
    // earliest appearance of its seed must survive a multi-H0 expansion.
    $withdrawal = operation('new-cash','withdrawal','100',$first['cutoff_ms']+4,'synthetic-new-cash-transfer');
    $current = batch([$conversion],[$withdrawal],['MXN'=>'240'],$first['cutoff_ms']+5);
    check($reconciler->apply($account,$current,settings($account))['reconciled'], 'newly tracked cash can be withdrawn after its first H0');
    $second = $points->capture($account);
    check($second['eligible'], 'fully consumed cash seed permits another H candidate');
    $current['at_ms'] = $second['cutoff_ms']+1;
    $current['since_ms'] = $first['cutoff_ms']+1;
    check($points->verifyPending($account,$current)['verification'] === 'verified', 'H1 verifies history after the first inventory-market introduction');
    $points->promote($account,$second['token']);
    $secondArchive = $root.'/hard-points/'.$account.'/'.$second['id'].'/hard.json';
    $secondHash = hash_file('sha256',$secondArchive);
    $expandedAgain = $history->expand(); $againLedger = new Ledger($expandedAgain);
    check($againLedger->expectedBalances($account) === $ledger->expectedBalances($account)
        && $againLedger->accountState($account)['inventory_balanced'], 'multiple H0 snapshots retain the earliest cash-seed reconstruction');

    $destination = $ledger->createAccount('fixture','Late cash destination');
    $target = $ledger->registerMarket($destination,'USD/EUR','USD','EUR');
    $deposit = operation('new-cash','deposit','100',$first['cutoff_ms']+5,'synthetic-new-cash-transfer');
    $received = batch([],[$deposit],['USD'=>'100'],$first['cutoff_ms']+6);
    $received['prices'][$deposit['id']] = ['price'=>'99','source'=>'synthetic-estimate'];
    check($reconciler->apply($destination,$received,settings($destination,'EUR'))['reconciled'], 'late cash destination records an explicitly estimated basis');
    $transferSettings = Settings::validate(['accounts'=>[settings($account),settings($destination,'EUR')]]);
    $links = (new Transfers($db,$transferSettings,$root))->autoLink();
    check(count(array_filter($links,static fn ($link) => $link['linked'] ?? false)) === 1, 'late transfer links reconstructed cash acquisitions across H0 boundaries');
    $destinationLots = $ledger->marketLots($target);
    check(array_sum(array_map(static fn ($lot) => (int)$lot['quantity'],$destinationLots)) === 100
        && array_unique(array_column($destinationLots,'unit_cost')) === [null], 'late transfer preserves the unknown original cash basis without fabricated FX');
    check(hash_file('sha256',$firstArchive) === $firstHash && hash_file('sha256',$secondArchive) === $secondHash
        && $db->integrityCheck()['ok'], 'late correction preserves both immutable archives and database integrity');

    $zero = $ledger->createAccount('fixture','No original cash');
    $ledger->registerMarket($zero,'BTC/USD','BTC','USD');
    $zeroDeposit = operation('zero-opening','deposit','20',1000);
    $zeroOriginal = batch([],[$zeroDeposit],['USD'=>'20'],2000);
    $reconciler->apply($zero,$zeroOriginal,settings($zero));
    $zeroPoints = new HardPoints($db,$root); $zeroCandidate = $zeroPoints->capture($zero);
    $zeroOriginal['at_ms'] = $zeroCandidate['cutoff_ms']+1;
    check($zeroPoints->verifyPending($zero,$zeroOriginal)['verification'] === 'verified', 'cash acquired entirely after opening supports H1 verification');
    $zeroPoints->promote($zero,$zeroCandidate['token']);
    $zeroConversion = trade('zero-conversion','USD/MXN','SELL','5','20',$zeroCandidate['cutoff_ms']+2);
    check($reconciler->apply($zero,batch([$zeroConversion],[],['USD'=>'15','MXN'=>'100'],$zeroCandidate['cutoff_ms']+3),settings($zero))['reconciled'],
        'new base currency uses carried cash even when its original opening balance was zero');
    $zeroMarket = $math->rows('SELECT * FROM markets WHERE account_id=? AND symbol=?',[$zero,'USD/MXN'])[0];
    $zeroExpanded = $history->expand(); $zeroLedger = new Ledger($zeroExpanded); $zeroMath = new Accounting($zeroExpanded);
    check(Numbers::decode($zeroMath->rows('SELECT baseline_lots_json FROM markets WHERE id=?',[$zeroMarket['id']])[0]['baseline_lots_json']) === []
        && $zeroLedger->accountState($zero)['inventory_balanced'] && $zeroLedger->marketState($zeroMarket['id'])['holdings'] === '15',
        'zero original balance omits the synthetic cash seed and replays only the real deposit');
    $history->apply($zeroExpanded,'zero_opening_cash_reconstruction_test');
    $zeroAgain = $history->expand(); $zeroAgainLedger = new Ledger($zeroAgain);
    check($zeroAgainLedger->expectedBalances($zero) === $ledger->expectedBalances($zero)
        && $zeroAgainLedger->accountState($zero)['inventory_balanced'], 'zero-opening cash reconstruction remains stable after cost correction');

    $empty = $ledger->createAccount('fixture','Original cash fully spent before H0',null,['USD'=>'100']);
    $math->execute('UPDATE exchange_accounts SET created_at=? WHERE id=?',['1970-01-01T00:00:00.000000Z',$empty]);
    $emptyBuy = trade('spent-opening','BTC/USD','BUY','10','10',1000);
    $emptyOriginal = batch([$emptyBuy],[],['BTC'=>'10'],2000);
    $reconciler->apply($empty,$emptyOriginal,settings($empty));
    $emptyPoints = new HardPoints($db,$root); $emptyCandidate = $emptyPoints->capture($empty);
    $emptyOriginal['at_ms'] = $emptyCandidate['cutoff_ms']+1;
    check($emptyPoints->verifyPending($empty,$emptyOriginal)['verification'] === 'verified', 'H1 permits original cash fully converted into known inventory');
    $emptyPoints->promote($empty,$emptyCandidate['token']);
    $emptyMarket = $ledger->registerMarket($empty,'USD/MXN','USD','MXN',null,['allow_currency_roles'=>true]);
    $emptySnapshot = $ledger->accountSnapshot($empty);
    $emptyBaseline = Numbers::decode($emptySnapshot['account']['hard_t0_json']);
    check($ledger->marketLots($emptyMarket) === [] && $emptyBaseline['cash_inventory_seeds'][$emptyMarket]['quantity'] === '0',
        'zero H0 cash retains a replay descriptor without inventing a financial lot');
    $emptySell = trade('restore-cash','BTC/USD','SELL','1','12',$emptyCandidate['cutoff_ms']+2);
    $emptyConvert = trade('spend-restored','USD/MXN','SELL','2','20',$emptyCandidate['cutoff_ms']+3);
    check($reconciler->apply($empty,batch([$emptySell,$emptyConvert],[],['BTC'=>'9','USD'=>'10','MXN'=>'40'],$emptyCandidate['cutoff_ms']+4),settings($empty))['reconciled'],
        'later quote proceeds fund a market introduced with zero H0 cash');
    $emptyExpanded = $history->expand(); $emptyLedger = new Ledger($emptyExpanded);
    check($emptyLedger->expectedBalances($empty) === $ledger->expectedBalances($empty)
        && $emptyLedger->accountState($empty)['inventory_balanced'] && $emptyLedger->marketState($emptyMarket)['holdings'] === '10',
        'full replay restores originally spent cash even when H0 held none');
    echo json_encode(['suite'=>'m3-currency-role-archives','status'=>'passed','checks'=>$checks,
        'network_requests'=>0,'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT)."\n";
} finally {
    unset($emptyExpanded,$emptyLedger,$emptyPoints,$zeroAgain,$zeroAgainLedger,$zeroExpanded,$zeroLedger,$zeroMath,$zeroPoints,$expandedAgain,$againLedger,$afterCorrection,$correctedLedger,$expanded,$fullLedger,$fullMath,$history,$points,$reconciler,$math,$ledger,$db);
    gc_collect_cycles();
    Backup::removeTree($root);
}
