<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/m1/bootstrap.php';

use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;

function runM1LedgerTests(): array
{
    $count = 0;
    $assert = static function (bool $condition, string $message) use (&$count): void { ++$count; if (!$condition) { throw new RuntimeException($message); } };
    $reject = static function (callable $fn, string $message) use ($assert): void { try { $fn(); } catch (Throwable) { $assert(true, $message); return; } $assert(false, $message); };
    $db = new Database(':memory:');
    $ledger = new Ledger($db);
    $assert(count($db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll()) === 8, 'Exactly eight domain tables.');
    $account = $ledger->createAccount('fixture', 'main', null, ['USD'=>'1000','ATOM'=>'0']);
    $market = $ledger->registerMarket($account, 'ATOM/USD', 'ATOM', 'USD', null, ['min_quantity'=>'0.01','min_notional'=>'1','reference_price'=>'10']);
    $ledger->reconcileAccount($account, ['USD'=>'1000']);
    $first = $ledger->createCheckpoint($market);
    $assert(is_string($first), 'Initial market checkpoint captured.');
    $assert($ledger->createCheckpoint($market) === null, 'Idle checkpoints are not duplicated.');
    $at = '2026-10-15T10:00:00Z';
    $buyData = ['account_id'=>$account,'market_id'=>$market,'exchange_order_id'=>'buy1','side'=>'BUY','quantity'=>'10','filled_quantity'=>'4','remaining_quantity'=>'6','price'=>'10','status'=>'partially_filled','occurred_at'=>$at];
    $buy = $ledger->recordOrder($buyData);
    $revision = $db->revision($account);
    $assert($ledger->recordOrder($buyData) === $buy && $db->revision($account) === $revision, 'Order replay idempotent.');
    $history = json_decode($db->pdo()->query("SELECT observations_json FROM orders WHERE exchange_order_id='buy1'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $assert(count($history)===1 && $history[0]['filled_quantity']==='4','Duplicate replay does not append an observation.');
    $reject(fn () => $ledger->createCheckpoint($market), 'Open order blocks checkpoint.');
    $buyData['filled_quantity']='10'; $buyData['remaining_quantity']='0'; $buyData['status']='filled';
    $ledger->recordOrder($buyData);
    $history = json_decode($db->pdo()->query("SELECT observations_json FROM orders WHERE exchange_order_id='buy1'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $assert(count($history)===2 && $history[0]['filled_quantity']==='4' && $history[1]['filled_quantity']==='10','Partial and final cumulative observations both persist.');
    $ledger->reconcileAccount($account,['USD'=>'900','ATOM'=>'10']);
    $ledger->createCheckpoint($market);
    $sellData=['account_id'=>$account,'market_id'=>$market,'exchange_order_id'=>'sell1','side'=>'SELL','quantity'=>'10','filled_quantity'=>'4','remaining_quantity'=>'6','price'=>'11','status'=>'partially_filled','occurred_at'=>'2026-10-15T11:00:00Z'];
    $sell=$ledger->recordOrder($sellData);
    $allocation=['account_id'=>$account,'market_id'=>$market,'allocation_key'=>'sell1-a','buy_order_id'=>$buy,'sell_order_id'=>$sell,'quantity'=>'4'];
    $a=$ledger->allocate($allocation); $revision=$db->revision($account);
    $assert($ledger->allocate($allocation)===$a && $db->revision($account)===$revision,'Allocation replay idempotent.');
    $reject(fn()=> $ledger->allocate(array_replace($allocation,['allocation_key'=>'excess','quantity'=>'1'])),'Cannot allocate more than current SELL fill.');
    $sellData['filled_quantity']='10';$sellData['remaining_quantity']='0';$sellData['status']='filled';
    $ledger->recordOrder($sellData);
    $ledger->allocate(array_replace($allocation,['allocation_key'=>'sell1-b','quantity'=>'6']));
    $ledger->reconcileAccount($account,['USD'=>'1010']);
    $ledger->createCheckpoint($market);
    $assert($ledger->marketState($market)['holdings']==='0','Partial allocations consume exactly ten units.');
    $assert((int)$db->pdo()->query('SELECT COUNT(*) FROM checkpoints')->fetchColumn()===3,'Every activity checkpoint retained.');
    $reject(fn()=> $ledger->reconcileAccount($account,['USD'=>'1011']),'Arbitrary balances cannot bless reconciliation.');
    $reject(fn()=> $ledger->recordOrder(array_replace($buyData,['quantity'=>'11'])),'Order identity immutable.');
    $reject(fn()=> Ledger::decimal(0.1),'Float financial amounts forbidden.');
    $assert(Ledger::decimal('0.000000000000000000000001')==='0.000000000000000000000001','24-place dust survives exactly.');
    $deposit=$ledger->recordOperation(['account_id'=>$account,'market_id'=>$market,'exchange_operation_id'=>'dep1','kind'=>'deposit','currency'=>'ATOM','amount'=>'2','price_basis'=>'9','occurred_at'=>'2026-10-15T12:00:00Z']);
    $ledger->reconcileAccount($account,['USD'=>'1010','ATOM'=>'2']);
    $ledger->createCheckpoint($market);
    $before=$ledger->accountSnapshot($account);
    $ledger->installHardT0($account,['hardpoint_id'=>'test-hard-t1','kind'=>'HardT1'],true);
    $assert($ledger->expectedBalances($account)===['ATOM'=>'2','USD'=>'1010'],'T1 preserves wallet.');
    $assert($ledger->completedTrades($account)===2,'T1 preserves lifetime trade counter.');
    $assert((int)$db->pdo()->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,'T1 prunes archived orders.');
    $lots=$ledger->marketLots($market);
    $assert(count($lots)===1 && $lots[0]['unit_cost']==='9' && $lots[0]['quantity']==='2','T1 preserves deposit acquisition basis.');
    $sell2=$ledger->recordOrder(['account_id'=>$account,'market_id'=>$market,'exchange_order_id'=>'sell2','side'=>'SELL','quantity'=>'2','filled_quantity'=>'2','price'=>'12','status'=>'filled','occurred_at'=>'2026-10-15T13:00:00Z']);
    $ledger->allocate(['account_id'=>$account,'market_id'=>$market,'allocation_key'=>'baseline-sale','baseline_lot_id'=>$lots[0]['id'],'sell_order_id'=>$sell2,'quantity'=>'2']);
    $ledger->reconcileAccount($account,['USD'=>'1034']);
    $ledger->createCheckpoint($market);
    $ledger->restoreAccountSnapshot($before);
    $assert($ledger->expectedBalances($account)===['ATOM'=>'2','USD'=>'1010'],'Restore reconstructs prior wallet.');
    $assert($db->integrityCheck()['ok'],'Restored foreign keys and SQLite integrity.');
    $restoredHistory=json_decode($db->pdo()->query("SELECT observations_json FROM orders WHERE exchange_order_id='buy1'")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $assert($restoredHistory===$history,'Archive-source snapshot restore preserves cumulative order observation history.');
    $other=$ledger->createAccount('fixture','other',null,['USD'=>'100']);
    $otherMarket=$ledger->registerMarket($other,'ATOM/USD','ATOM','USD');
    $reject(fn()=> $ledger->recordOrder(array_replace($buyData,['account_id'=>$other,'market_id'=>$market,'exchange_order_id'=>'cross'])),'Cross-account order rejected.');
    $reject(fn()=> $ledger->registerMarket($account,'USD/BTC','USD','BTC'),'Cross-market quote spending is fail-closed in M1.');
    $reject(fn()=> $ledger->recordOrder(array_replace($buyData,['exchange_order_id'=>'third-fee','fee_amount'=>'0.1','fee_currency'=>'BNB'])),'Unsupported third-currency inventory fee rejected.');
    $reject(fn()=> $ledger->recordOperation(['account_id'=>$account,'market_id'=>$market,'exchange_operation_id'=>'early-withdraw','kind'=>'withdrawal','currency'=>'ATOM','amount'=>'1','occurred_at'=>'2026-10-14T10:00:00Z']),'Withdrawal cannot consume future acquisitions.');
    $ledger->recordOperation(['account_id'=>$account,'market_id'=>$market,'exchange_operation_id'=>'withdraw','kind'=>'withdrawal','currency'=>'ATOM','amount'=>'1.999','occurred_at'=>'2026-10-15T14:00:00Z']);
    $ledger->reconcileAccount($account,['USD'=>'1010','ATOM'=>'0.001']);
    $assert($ledger->marketState($market)['dust'],'Tiny remnant marked dust.');
    $reject(fn()=> $ledger->createCheckpoint($market),'Dust blocks checkpoint.');
    $ledger->installHardT0($account,['hardpoint_id'=>'dust-is-allowed-at-initial-t0']);
    $assert(true,'Initial Hard T0 only requires no open order.');
    // Failures in a nested savepoint must not leak a partial financial mutation.
    $revision=$db->revision($account);
    $db->transaction(function() use ($db,$ledger,$account,$market,$reject): void {
        $reject(fn()=> $db->transaction(function() use($ledger,$account,$market):void {
            $ledger->recordOperation(['account_id'=>$account,'exchange_operation_id'=>'rolled-back','kind'=>'deposit','currency'=>'USD','amount'=>'1']);
            throw new RuntimeException('synthetic rollback');
        }),'Nested transaction rollback');
    });
    $assert($db->revision($account)===$revision,'Nested failure rolled back revision and operation.');
    return ['status'=>'passed','assertions'=>$count];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode(runM1LedgerTests(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
}
