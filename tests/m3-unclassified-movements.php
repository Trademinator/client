<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Backup,Database,Ledger};
use Trademinator\Client\M3\{CcxtAccount,HardPoints,InventoryEvidenceError,MovementClassifier,MovementReviews,Numbers,Reconciler,Runtime,Settings};
use function React\Async\await;
use function React\Promise\resolve;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
    echo 'PASS '.$message.PHP_EOL;
}
function inventoryFailure(callable $action): InventoryEvidenceError
{
    try { $action(); } catch (InventoryEvidenceError $error) { return $error; }
    throw new RuntimeException('Expected inventory evidence failure.');
}
function refuses(callable $action, string $message): void
{
    try { $action(); } catch (Throwable) { check(true,$message); return; }
    check(false,$message);
}
function settings(string $account): array
{
    return Settings::validate(['accounts'=>[['account_id'=>$account,'history_since_ms'=>0,
        'api_key_env'=>'UNCLASSIFIED_TEST_KEY','secret_env'=>'UNCLASSIFIED_TEST_SECRET']]]);
}
function operation(string $id, string $kind, string $amount, int $at): array
{
    return ['id'=>$kind.':'.$id,'kind'=>$kind,'currency'=>'USD','amount'=>$amount,'fees'=>[],
        'timestamp'=>$at,'txid'=>null,'address'=>null,'tag'=>null,'network'=>null];
}
function batch(array $operations, string $balance = '0'): array
{
    return ['at_ms'=>(int)floor(microtime(true)*1000),'since_ms'=>0,'complete'=>true,
        'orders'=>[],'trades'=>[],'operations'=>$operations,'prices'=>[],
        'balances'=>['USD'=>['total'=>$balance,'free'=>$balance,'used'=>'0']]];
}
function gap(string $account, string $shortfall = '185', string $id = 'PRIVATE_OUTFLOW', bool $ambiguous = false, array $pool = []): InventoryEvidenceError
{
    return new InventoryEvidenceError(['currency'=>'USD','at'=>Numbers::iso(2000),'kind'=>'withdrawal','sequence'=>0,
        'row'=>['account_id'=>$account,'exchange_operation_id'=>$id]],'185',$shortfall,$pool,[],$ambiguous);
}
final class MovementExchange
{
    public array $batches = [];
    public int $calls = 0;
    public function wsCapabilities(): array { return []; }
    public function snapshot(array $context): object
    {
        ++$this->calls;
        return resolve(array_shift($this->batches));
    }
    public function resetRest(): void {}
    public function resetWebSocket(): void {}
    public function close(): void {}
}

final class MovementLedgerRest
{
    public string $id = 'ndax';
    public array $has = ['fetchLedger'=>true];
    public mixed $marketsLoading = null;
    public bool $reloadingMarkets = false;
    public array $ledger = [];
    public string $balance = '0';
    public function load_markets(): object { return resolve(['USD/EUR'=>['symbol'=>'USD/EUR','spot'=>true]]); }
    public function fetch_balance(): object { return resolve(['total'=>['USD'=>$this->balance],
        'free'=>['USD'=>$this->balance],'used'=>['USD'=>'0']]); }
    public function fetch_my_trades(...$args): object { return resolve([]); }
    public function fetch_open_orders(...$args): object { return resolve([]); }
    public function fetch_ledger(...$args): object { return resolve($this->ledger); }
    public function close(): void {}
}
function ledgerRow(string $id, string $type, array $extra = []): array
{
    return array_replace(['id'=>$id,'type'=>$type,'status'=>'ok','currency'=>'USD','amount'=>'5',
        'timestamp'=>3000,'direction'=>'in','info'=>['counterparty'=>'PRIVATE_COUNTERPARTY',
        'email'=>'PRIVATE_EMAIL','description'=>'PRIVATE_DESCRIPTION']],$extra);
}

foreach (['bitso','ndax','coinbase'] as $exchange) {
    check(MovementClassifier::supportsUserTransfers($exchange),$exchange.' advertises known inter-user transfer support');
    $decision = MovementClassifier::classify($exchange,'unknown');
    check($decision['decision'] === 'soft_label' && $decision['label'] === 'user_deposit'
        && $decision['status'] === 'pending_confirmation',
        $exchange.' unclassified movement becomes a user-deposit proposal');
}
foreach (['deposit','withdrawal','trade','buy','sell','conversion','swap','BUY','SELL'] as $kind) {
    foreach (['bitso','fixture'] as $exchange) {
        $decision = MovementClassifier::classify($exchange,$kind);
        check($decision['decision'] === 'recognized' && $decision['label'] === null && $decision['status'] === 'known',
            $exchange.' known '.$kind.' movement cannot enter the unknown-source fallback');
    }
}
$rejected = MovementClassifier::classify('fixture','unknown');
check(!MovementClassifier::supportsUserTransfers('fixture') && $rejected['decision'] === 'reject'
    && $rejected['label'] === null && $rejected['status'] === 'rejected',
    'unknown movement on an unsupported exchange is rejected instead of inferred as a user transfer');
check(!MovementClassifier::supportsUserTransfers('coinbaseexchange'),
    'retail Coinbase capability is not inherited by a different exchange adapter');

$root = sys_get_temp_dir().'/trademinator-unclassified-'.bin2hex(random_bytes(6));
Backup::ensureDirectory($root);
try {
    $path = $root.'/client.sqlite';
    $db = new Database($path); $ledger = new Ledger($db); $reviews = new MovementReviews($db);
    $account = $ledger->createAccount('bitso','Unclassified source');
    $ledger->registerMarket($account,'USD/EUR','USD','EUR');
    $before = $ledger->accountSnapshot($account); $revision = $db->revision($account);
    $error = gap($account); $proposal = $reviews->observeInventoryGap($account,$error);
    check($proposal !== null && $proposal['changed'] && $proposal['label'] === 'user_deposit'
        && $proposal['status'] === 'pending_confirmation' && $proposal['decision'] === 'soft_label',
        'an unexplained source receives a durable pending user-deposit proposal');
    check($proposal['currency'] === 'USD' && $proposal['minimum_unexplained_amount'] === '185'
        && $proposal['required_by'] === Numbers::iso(2000)
        && $proposal['related_outflow_ref'] === $error->diagnostic['outflow_ref'],
        'proposal retains exact missing quantity and the latest time funds must have been available');
    check($error->diagnostic['outflow_kind'] === 'withdrawal'
        && MovementClassifier::classify('bitso',$error->diagnostic['outflow_kind'])['decision'] === 'recognized',
        'the actual known withdrawal remains a withdrawal while its unexplained source is reviewed');
    check($proposal['evidence'] === ['movement_not_deposit_or_withdrawal'=>true,'movement_not_trade'=>true,
        'exchange_supports_user_transfers'=>true],
        'proposal records both absent classifications and exchange capability');
    check($ledger->accountSnapshot($account) === $before && $db->revision($account) === $revision,
        'soft labeling adds no financial deposit, inventory, acquisition cost or financial revision');
    $pending = $reviews->pending($account);
    check($pending['count'] === 1 && count($pending['items']) === 1 && !$pending['truncated']
        && !isset($pending['items'][0]['changed']),
        'status exposes one pending descriptor without transient write state');
    check(!str_contains(Numbers::json($pending),'PRIVATE_OUTFLOW'),
        'pending descriptor exposes only a safe reference to the original exchange outflow');
    $unchanged = $reviews->observeInventoryGap($account,$error);
    check(!$unchanged['changed'] && $reviews->pending($account) === $pending,
        'repeated identical inventory evidence is idempotent');
    $reduced = $reviews->observeInventoryGap($account,gap($account,'100'));
    check($reduced['changed'] && $reduced['ref'] === $proposal['ref']
        && $reduced['minimum_unexplained_amount'] === '100' && $reviews->pending($account)['count'] === 1,
        'new partial acquisition evidence updates the same review instead of inventing another deposit');
    check($reviews->observeInventoryGap($account,gap($account,ambiguous:true)) === null,
        'ambiguous same-timestamp execution ordering is not relabeled as an unknown user deposit');
    $execution = [['currency'=>'USD','quantity'=>'185','available_at'=>Numbers::iso(1000),
        'acquired_at'=>Numbers::iso(1000),'execution'=>'PRIVATE_PENDING_EXECUTION']];
    check($reviews->observeInventoryGap($account,gap($account,pool:$execution)) === null,
        'a known pending execution acquisition cannot be relabeled as an unknown user deposit');
    $reviews->observeInventoryGap($account,gap($account,id:'PRIVATE_SECOND_OUTFLOW'));
    $limited = $reviews->pending($account,1);
    check($limited['count'] === 2 && count($limited['items']) === 1 && $limited['truncated'],
        'review pagination retains the complete count and signals truncation');

    $unsupported = $ledger->createAccount('fixture','Unsupported user transfers');
    $redFlag = $reviews->observeInventoryGap($unsupported,gap($unsupported));
    check($redFlag['decision'] === 'reject' && $redFlag['label'] === null && $redFlag['status'] === 'rejected'
        && !$redFlag['evidence']['exchange_supports_user_transfers'],
        'unsupported exchange stores an explicit rejected movement for review');
    check($reviews->pending($unsupported)['count'] === 1,
        'rejected movement remains visible instead of disappearing from the review queue');

    $restartExpected = $reviews->pending($account);
    unset($reviews,$ledger,$db);
    $db = new Database($path); $ledger = new Ledger($db); $reviews = new MovementReviews($db);
    check($reviews->pending($account) === $restartExpected && $reviews->pending($unsupported)['count'] === 1,
        'pending and rejected reviews survive a database restart');

    // The financial transaction rolls back while the review remains available to the future UI.
    $live = $ledger->createAccount('bitso','Live reconciliation');
    $ledger->registerMarket($live,'USD/EUR','USD','EUR');
    $s = settings($live); $config = $s['accounts'][0]; $reconciler = new Reconciler($db);
    $withdrawal = operation('PRIVATE_LIVE_OUTFLOW','withdrawal','185',2000);
    $missing = batch([$withdrawal]);
    $financialBefore = $ledger->accountSnapshot($live); $revision = $db->revision($live);
    $preflight = inventoryFailure(fn () => $reconciler->validateInventory($live,$missing,$config));
    check($reviews->pending($live)['count'] === 1 && $ledger->accountSnapshot($live) === $financialBefore
        && $db->revision($live) === $revision,
        'discarded inventory preflight persists its review without changing the live ledger');
    $preflightReview = $reviews->pending($live);
    inventoryFailure(fn () => $reconciler->apply($live,$missing,$config));
    check($reviews->pending($live) === $preflightReview && $ledger->accountSnapshot($live) === $financialBefore
        && $db->revision($live) === $revision,
        'failed apply rolls back financial imports but preserves the same review');
    $partial = $missing; $partial['complete'] = false;
    refuses(fn () => $reconciler->apply($live,$partial,$config),'incomplete exchange history remains rejected');
    check($reviews->pending($live) === $preflightReview,'incomplete exchange history cannot clear an existing review');

    // Runtime distinguishes pending explanation from an ordinary synchronization fault.
    $runtimeAccount = $ledger->createAccount('bitso','Runtime review');
    $market = $ledger->registerMarket($runtimeAccount,'USD/EUR','USD','EUR');
    $db->pdo()->prepare("UPDATE markets SET state='ACTIVE' WHERE id=?")->execute([$market]);
    $runtimeSettings = settings($runtimeAccount); $exchange = new MovementExchange();
    $corrected = batch([operation('PRIVATE_EARLIER_SOURCE','deposit','185',1000),$withdrawal]);
    $corrected['prices']['deposit:PRIVATE_EARLIER_SOURCE'] = ['price'=>'1','source'=>'fixture','estimated'=>true];
    $exchange->batches = [$missing,$missing,$corrected]; $events = [];
    $runtime = new Runtime($db,$runtimeSettings,fn () => $exchange,
        function ($level,$event,$context,$message) use (&$events): void { $events[] = compact('level','event','context','message'); });
    $now = (int)floor(microtime(true)*1000);
    try {
        $runtime->tick($now);
        $state = $runtime->status()['accounts'][$runtimeAccount];
        check($state['state'] === 'REVIEW_REQUIRED' && !$state['rest_ok']
            && $state['warning'] === 'user_deposit_confirmation_required',
            'supported unknown source enters REVIEW_REQUIRED with an explicit confirmation warning');
        check(!isset($state['last_error']) && $state['last_review']['label'] === 'user_deposit'
            && $state['unclassified_review']['count'] === 1,
            'runtime exposes the pending user-deposit descriptor instead of repeating an inventory error');
        check(in_array('exchange_user_deposit_review_pending',array_column($events,'event'),true)
            && !in_array('exchange_sync_failed',array_column($events,'event'),true),
            'supported unknown source emits a review event without the generic sync-failed event');
        check(!$runtime->ready($market)['ready'] && $ledger->accountSnapshot($runtimeAccount)['operations'] === [],
            'pending explanation never authorizes trading or fabricates financial history');
        $runtime->tick($now+120000);
        check($runtime->status()['accounts'][$runtimeAccount]['unclassified_review']['count'] === 1,
            'repeated runtime observations retain one review identity');
        $runtime->tick($now+240000);
        $recovered = $runtime->status()['accounts'][$runtimeAccount];
        check($recovered['state'] === 'READY' && $recovered['rest_ok']
            && $recovered['unclassified_review']['count'] === 0 && !isset($recovered['last_review']),
            'complete corrected acquisition history supersedes the proposal and clears runtime review state');
        check(count($ledger->accountSnapshot($runtimeAccount)['operations']) === 2
            && $ledger->expectedBalances($runtimeAccount) === [],
            'recovery records only the two actual financial movements without a duplicate soft-labeled deposit');
    } finally { $runtime->close(); }

    $unsupportedRuntime = $ledger->createAccount('fixture','Runtime rejection');
    $ledger->registerMarket($unsupportedRuntime,'USD/EUR','USD','EUR');
    $unsupportedExchange = new MovementExchange(); $unsupportedExchange->batches = [$missing]; $rejectionEvents = [];
    $runtime = new Runtime($db,settings($unsupportedRuntime),fn () => $unsupportedExchange,
        function ($level,$event,$context,$message) use (&$rejectionEvents): void { $rejectionEvents[] = compact('level','event','context','message'); });
    try {
        $runtime->tick($now);
        $state = $runtime->status()['accounts'][$unsupportedRuntime];
        $redEvents = array_values(array_filter($rejectionEvents,static fn ($event) => $event['event'] === 'exchange_movement_rejected'));
        check($state['state'] === 'RECOVERING' && !$state['rest_ok'] && $state['unclassified_review']['count'] === 1
            && $state['unclassified_review']['items'][0]['status'] === 'rejected',
            'unsupported unknown source remains rejected and blocks reconciliation');
        check(count($redEvents) === 1 && ($redEvents[0]['context']['red_flag'] ?? false) === true
            && in_array('exchange_sync_failed',array_column($rejectionEvents,'event'),true),
            'unsupported unknown source emits an explicit red flag alongside synchronization failure');
    } finally { $runtime->close(); }

    // Native ledger movements must reach the same decision path even without an inventory shortfall.
    $ledgerAccount = $ledger->createAccount('ndax','Native ledger classification');
    $ledgerSettings = settings($ledgerAccount); $ledgerRest = new MovementLedgerRest();
    $ledgerRest->ledger = [ledgerRow('PRIVATE_NATIVE_TRANSFER','transfer'),
        ledgerRow('PRIVATE_NATIVE_UNKNOWN','PRIVATE_NEW_TYPE',['direction'=>null]),
        ledgerRow('PRIVATE_NATIVE_FEE','fee',['direction'=>'out','amount'=>'0.25']),
        ledgerRow('PRIVATE_NATIVE_TRANSACTION','transaction'),
        ledgerRow('PRIVATE_NATIVE_DEPOSIT','deposit',['direction'=>null]),
        ledgerRow('PRIVATE_NATIVE_WITHDRAWAL','withdrawal',['direction'=>null]),
        ledgerRow('PRIVATE_NATIVE_TRADE','trade'),ledgerRow('PRIVATE_NATIVE_CONVERSION','conversion'),
        ledgerRow('PRIVATE_NATIVE_PENDING','transfer',['status'=>'pending']),
        ledgerRow('PRIVATE_NATIVE_FAILED','transfer',['status'=>'failed'])];
    $ledgerRest->balance = '5';
    $ledgerAdapter = new CcxtAccount('ndax',$ledgerSettings,$ledgerSettings['accounts'][0],$ledgerRest);
    $ledgerBatch = await($ledgerAdapter->snapshot(['symbols'=>[],'known_orders'=>[],'since_ms'=>0]));
    check(count($ledgerBatch['operations']) === 3 && $ledgerBatch['operations'][0]['kind'] === 'deposit'
        && $ledgerBatch['operations'][0]['amount'] === '5',
        'native transaction retains its deposit accounting while other ledger types follow classification');
    $nativeOperations = array_column($ledgerBatch['operations'],null,'id');
    check(($nativeOperations['deposit:PRIVATE_NATIVE_DEPOSIT']['kind'] ?? null) === 'deposit'
        && ($nativeOperations['withdrawal:PRIVATE_NATIVE_WITHDRAWAL']['kind'] ?? null) === 'withdrawal',
        'explicit deposit and withdrawal ledger types retain known direction without fallback labeling');
    check(count($ledgerBatch['unclassified_movements']) === 3,
        'completed transfer unknown and fee ledger rows reach classification without known trades or pending rows');
    $ledgerMovements = array_column($ledgerBatch['unclassified_movements'],null,'source_ref');
    $unknown = $ledgerMovements[hash('sha256','PRIVATE_NATIVE_UNKNOWN')] ?? null;
    check($unknown !== null && $unknown['kind'] === 'unknown' && $unknown['amount'] === '5'
        && $unknown['currency'] === 'USD' && $unknown['timestamp'] === 3000 && $unknown['direction'] === null,
        'unknown native type retains its quantity and time with a canonical safe classification');
    check(!str_contains(Numbers::json($ledgerBatch['unclassified_movements']),'PRIVATE_'),
        'unclassified ledger capture excludes raw identifiers descriptions and counterparties');
    $badLedger = new MovementLedgerRest();
    $badLedger->ledger = [ledgerRow('PRIVATE_MALFORMED','transfer',['amount'=>null])];
    $badAdapter = new CcxtAccount('ndax',$ledgerSettings,$ledgerSettings['accounts'][0],$badLedger);
    refuses(fn () => await($badAdapter->snapshot(['symbols'=>[],'known_orders'=>[],'since_ms'=>0])),
        'a completed unknown ledger row with a missing amount fails instead of becoming zero');
    $badLedger->ledger = [ledgerRow('PRIVATE_CONTRADICTORY_DIRECTION','withdrawal',['direction'=>'in'])];
    refuses(fn () => await($badAdapter->snapshot(['symbols'=>[],'known_orders'=>[],'since_ms'=>0])),
        'explicit withdrawal with contradictory incoming direction is rejected');
    $ledgerBefore = $ledger->accountSnapshot($ledgerAccount); $ledgerRevision = $db->revision($ledgerAccount);
    $ledgerProposals = $reviews->observeLedgerMovements($ledgerAccount,$ledgerBatch);
    check(count($ledgerProposals) === 3 && count(array_filter($ledgerProposals,
        static fn ($row) => $row['label'] === 'user_deposit' && $row['status'] === 'pending_confirmation')) === 3,
        'supported unknown ledger movements receive pending user-deposit proposals');
    check($ledger->accountSnapshot($ledgerAccount) === $ledgerBefore && $db->revision($ledgerAccount) === $ledgerRevision,
        'observing native ledger proposals cannot change financial rows or revision');
    $nativeReview = $reviews->pending($ledgerAccount);
    $nativeItem = $nativeReview['items'][0];
    check($nativeItem['source_kind'] === 'ledger_movement' && isset($nativeItem['amount'],$nativeItem['occurred_at'])
        && !isset($nativeItem['minimum_unexplained_amount'],$nativeItem['required_by']),
        'actual ledger proposals report observed amount and time without inventing shortfall context');
    check(count(array_filter($reviews->observeLedgerMovements($ledgerAccount,$ledgerBatch),
        static fn ($row) => $row['changed'])) === 0,
        'native ledger proposal replay is idempotent');
    $conflicting = $ledgerBatch; $conflicting['unclassified_movements'][0]['amount'] = '6';
    refuses(fn () => $reviews->observeLedgerMovements($ledgerAccount,$conflicting),
        'changed money under an existing native ledger identity is rejected');
    check($reviews->pending($ledgerAccount) === $nativeReview,
        'conflicting native ledger evidence preserves previous review values');
    $incompleteLedger = $ledgerBatch; $incompleteLedger['complete'] = false;
    check($reviews->observeLedgerMovements($ledgerAccount,$incompleteLedger) === [],
        'an incomplete native ledger snapshot cannot publish new proposals');
    $reviews->resolveInventoryGaps($ledgerAccount);
    check($reviews->pending($ledgerAccount) === $nativeReview,
        'resolved inventory gaps do not erase outstanding actual ledger movement reviews');
    $directLedgerAccount = $ledger->createAccount('ndax','Direct native ledger reconciliation');
    $directSettings = settings($directLedgerAccount);
    $directBatch = $ledgerBatch; $directBatch['operations'] = []; $directBatch['transfer_labels'] = [];
    $directResult = (new Reconciler($db))->apply($directLedgerAccount,$directBatch,$directSettings['accounts'][0]);
    check(!$directResult['reconciled'] && $reviews->pending($directLedgerAccount)['count'] === 3
        && $ledger->accountSnapshot($directLedgerAccount)['operations'] === [],
        'direct reconciliation retains ledger proposals without manufacturing deposits to balance the wallet');
    $directRejectedAccount = $ledger->createAccount('fixture','Direct rejected ledger reconciliation');
    $directRejectedBefore = $ledger->accountSnapshot($directRejectedAccount);
    $directRejectedSettings = settings($directRejectedAccount);
    refuses(fn () => (new Reconciler($db))->apply($directRejectedAccount,$directBatch,$directRejectedSettings['accounts'][0]),
        'direct reconciliation rejects unsupported unclassified ledger movements');
    check($reviews->pending($directRejectedAccount)['count'] === 3
        && $ledger->accountSnapshot($directRejectedAccount) === $directRejectedBefore,
        'direct rejected ledger history retains red flags without committing financial imports');
    $nativeFull = $ledger->accountSnapshot($ledgerAccount,true);
    $nativeRestore = new Database(':memory:');
    (new Ledger($nativeRestore))->restoreAccountSnapshot($nativeFull);
    check((new MovementReviews($nativeRestore))->pending($ledgerAccount) === $nativeReview,
        'native ledger movement review identities and amounts survive snapshot restoration');

    $nativeRuntimeAccount = $ledger->createAccount('ndax','Runtime native ledger');
    $nativeRuntimeSettings = settings($nativeRuntimeAccount); $nativeRuntimeRest = new MovementLedgerRest();
    $nativeRuntimeRest->ledger = [ledgerRow('PRIVATE_RUNTIME_NATIVE','transfer')]; $nativeRuntimeRest->balance = '5';
    $nativeRuntimeAdapter = new CcxtAccount('ndax',$nativeRuntimeSettings,$nativeRuntimeSettings['accounts'][0],$nativeRuntimeRest);
    $nativeEvents = [];
    $runtime = new Runtime($db,$nativeRuntimeSettings,fn () => $nativeRuntimeAdapter,
        function ($level,$event,$context,$message) use (&$nativeEvents): void { $nativeEvents[] = compact('level','event','context','message'); });
    try {
        $runtime->tick($now);
        $state = $runtime->status()['accounts'][$nativeRuntimeAccount];
        check(!$state['rest_ok'] && $state['unclassified_review']['count'] === 1
            && $state['unclassified_review']['items'][0]['label'] === 'user_deposit'
            && $ledger->accountSnapshot($nativeRuntimeAccount)['operations'] === [],
            'real CCXT ledger fallback reaches runtime pending review without creating financial inventory');
        check(in_array('exchange_user_deposit_review_pending',array_column($nativeEvents,'event'),true)
            && !str_contains(Numbers::json($nativeEvents),'PRIVATE_'),
            'native ledger pending-review event preserves safe metadata only');
    } finally { $runtime->close(); }
    $nativeRejectedAccount = $ledger->createAccount('fixture','Rejected native ledger');
    $nativeRejectedSettings = settings($nativeRejectedAccount); $nativeRejectedRest = new MovementLedgerRest();
    $nativeRejectedRest->id = 'fixture';
    $nativeRejectedRest->ledger = [ledgerRow('PRIVATE_REJECTED_NATIVE','transfer')];
    $nativeRejectedAdapter = new CcxtAccount('fixture',$nativeRejectedSettings,$nativeRejectedSettings['accounts'][0],$nativeRejectedRest);
    $nativeRejectedEvents = [];
    $runtime = new Runtime($db,$nativeRejectedSettings,fn () => $nativeRejectedAdapter,
        function ($level,$event,$context,$message) use (&$nativeRejectedEvents): void { $nativeRejectedEvents[] = compact('level','event','context','message'); });
    try {
        $runtime->tick($now);
        $state = $runtime->status()['accounts'][$nativeRejectedAccount];
        $redEvents = array_values(array_filter($nativeRejectedEvents,
            static fn ($event) => $event['event'] === 'exchange_movement_rejected'));
        check(!$state['rest_ok'] && $state['unclassified_review']['count'] === 1
            && $state['unclassified_review']['items'][0]['status'] === 'rejected'
            && count($redEvents) === 1 && ($redEvents[0]['context']['red_flag'] ?? false),
            'unsupported actual ledger movement is red-flagged even when the remaining financial balances match');
    } finally { $runtime->close(); }

    // A complete financial snapshot can also preserve review history for restoration.
    $full = $ledger->accountSnapshot($account,true);
    check(count($full['movement_reviews'] ?? []) === 2 && !array_key_exists('movement_reviews',$ledger->accountSnapshot($account)),
        'full recovery snapshot includes reviews while normal financial evidence stays unchanged');
    $restored = new Database(':memory:'); $restoredLedger = new Ledger($restored);
    $restoredLedger->restoreAccountSnapshot($full);
    check((new MovementReviews($restored))->pending($account) === $reviews->pending($account),
        'full snapshot restores pending unexplained-source reviews into a fresh database');
    $ledger->restoreAccountSnapshot($ledger->accountSnapshot($account));
    check($reviews->pending($account) === $restartExpected,
        'restoring an older financial-only snapshot preserves live review observations');
    $reviews->resolveInventoryGaps($unsupported);
    check($reviews->pending($unsupported)['count'] === 0,
        'resolved inventory history removes rejected proposals from the active queue');

    // A resolved annotation is archived without changing the original financial prefix.
    $hard = new HardPoints($db,$root); $candidate = $hard->capture($runtimeAccount);
    check($candidate['eligible'],'corrected and reconciled history remains eligible for a hard checkpoint');
    $corrected['at_ms'] = $candidate['cutoff_ms']+1;
    check($hard->verifyPending($runtimeAccount,$corrected)['verification'] === 'verified',
        'review history does not alter financial reconstruction during checkpoint verification');
    $hard->promote($runtimeAccount,$candidate['token']);
    $archive = $root.'/hard-points/'.$runtimeAccount.'/'.$candidate['id'];
    $hardHash = hash_file('sha256',$archive.'/hard.json'); $dbHash = hash_file('sha256',$archive.'/archive.sqlite');
    $reviews->observeInventoryGap($runtimeAccount,gap($runtimeAccount,id:'PRIVATE_NEW_GAP'));
    check(hash_file('sha256',$archive.'/hard.json') === $hardHash
        && hash_file('sha256',$archive.'/archive.sqlite') === $dbHash,
        'later unresolved-source review cannot alter immutable Hard H0 files');
    check(!str_contains(Numbers::json([$events,$rejectionEvents]),'PRIVATE_'),
        'runtime review events never leak private exchange identities');
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
echo json_encode(['suite'=>'m3-unclassified-movements','status'=>'passed','checks'=>$checks,'network_requests'=>0,
    'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT).PHP_EOL;
