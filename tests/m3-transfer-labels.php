<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Backup,Database,Ledger};
use Trademinator\Client\M3\{CcxtAccount,HardPoints,Normalize,Numbers,Reconciler,Runtime,Settings,TransferLabels,TransferReviews};
use function React\Async\await;
use function React\Promise\resolve;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
    echo 'PASS '.$message.PHP_EOL;
}
function refuses(callable $fn, string $message): void
{
    try { $fn(); } catch (Throwable) { check(true,$message); return; }
    check(false,$message);
}
function rawTransfer(string $id = 'private-transfer-id', array $extra = []): array
{
    return array_replace(['id'=>$id,'status'=>'ok','timestamp'=>1700000000000,'currency'=>'USD','amount'=>'10',
        'fee'=>['currency'=>'USD','cost'=>'0.25'],'txid'=>null,'address'=>null,'tag'=>null,'network'=>null,'info'=>[]],$extra);
}
function settings(string $account): array
{
    return Settings::validate(['accounts'=>[['account_id'=>$account,'history_since_ms'=>0,
        'api_key_env'=>'TRANSFER_TEST_KEY','secret_env'=>'TRANSFER_TEST_SECRET']]]);
}
function batch(array $rows, array $labels, array $totals = []): array
{
    $balances = [];
    foreach ($totals as $currency=>$amount) { $balances[$currency] = ['total'=>$amount,'free'=>$amount,'used'=>'0']; }
    return ['at_ms'=>1700000001000,'since_ms'=>0,'complete'=>true,'orders'=>[],'trades'=>[],
        'operations'=>$rows,'balances'=>$balances,'prices'=>[],'transfer_labels'=>$labels];
}
function rawDatabase(string $path): PDO
{
    return new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}

final class TransferRest
{
    public string $id = 'bitso';
    public array $has = ['fetchDeposits'=>true,'fetchWithdrawals'=>true];
    public mixed $marketsLoading = null;
    public bool $reloadingMarkets = false;
    public array $deposits = [];
    public array $withdrawals = [];
    public array $ledger = [];
    public function load_markets(): object { return resolve(['USD/EUR'=>['symbol'=>'USD/EUR','spot'=>true]]); }
    public function fetch_balance(): object { return resolve(['total'=>[],'free'=>[],'used'=>[]]); }
    public function fetch_my_trades(...$args): object { return resolve([]); }
    public function fetch_open_orders(...$args): object { return resolve([]); }
    public function fetch_deposits(...$args): object { return resolve($this->deposits); }
    public function fetch_withdrawals(...$args): object { return resolve($this->withdrawals); }
    public function fetch_ledger(...$args): object { return resolve($this->ledger); }
    public function close(): void {}
}

// Hints are deliberately separate from the financial normalizer and retain no raw metadata.
$raw = rawTransfer();
foreach (['bitso','ndax','coinbase'] as $exchange) {
    foreach (['deposit','withdrawal'] as $kind) {
        $label = TransferLabels::suggest($exchange,$raw,$kind);
        check(($label['label'] ?? null) === 'user_'.$kind && $label['status'] === 'pending_confirmation'
            && $label['reason'] === 'supported_exchange_without_external_evidence',
            $exchange.' missing-route '.$kind.' receives only a pending user-transfer suggestion');
    }
}
check(TransferLabels::suggest('fixture',$raw,'deposit') === null,'an unsupported exchange does not acquire a user-transfer label');
check(TransferLabels::suggest('bitso',$raw,'trade') === null,'trade events cannot enter transfer labeling');
check(TransferLabels::suggest('bitso',array_replace($raw,['internal'=>false]),'deposit') === null,
    'explicit external-transfer flag is not treated as missing metadata');
foreach (['pending','failed','canceled'] as $status) {
    check(TransferLabels::suggest('bitso',array_replace($raw,['status'=>$status]),'deposit') === null,
        $status.' movements cannot receive confirmed-movement review labels');
}
$peer = rawTransfer(extra:['info'=>['method'=>'bt','network'=>'bt','protocol'=>'bt','integration'=>'bt',
    'recipient'=>'PRIVATE_RECIPIENT','email'=>'PRIVATE_EMAIL','details'=>['note'=>'PRIVATE_NOTE']]]);
$peerLabel = TransferLabels::suggest('bitso',$peer,'withdrawal');
check(($peerLabel['reason'] ?? null) === 'bitso_user_transfer_route' && $peerLabel['status'] === 'confirmed',
    'complete Bitso native route confirms the user-transfer classification');
foreach (['method','network','protocol','integration'] as $field) {
    $incompletePeer = $peer; unset($incompletePeer['info'][$field]);
    check(TransferLabels::suggest('bitso',$incompletePeer,'withdrawal') === null,
        'partial Bitso route missing '.$field.' cannot confirm a transfer');
}
$ndax = rawTransfer(extra:['info'=>['ReferenceType'=>'Transfer','Counterparty'=>'PRIVATE_COUNTERPARTY']]);
check((TransferLabels::suggest('ndax',$ndax,'deposit')['reason'] ?? null) === 'ndax_transfer_reference',
    'NDAX transfer reference does not silently assert counterparty ownership');
check(TransferLabels::suggest('ndax',array_replace($ndax,['info'=>['ReferenceType'=>'Transfer','Counterparty'=>7,'AccountId'=>7]]),'deposit') === null,
    'identified same-account NDAX movement does not receive a user-counterparty label');
$coinbase = rawTransfer(extra:['txid'=>'private-transfer-id','info'=>['type'=>'send','network'=>['status'=>'off_blockchain']]]);
check((TransferLabels::suggest('coinbase',$coinbase,'withdrawal')['reason'] ?? null) === 'coinbase_off_blockchain',
    'Coinbase off-blockchain movement recognizes its adapter transaction-ID fallback');
check((TransferLabels::suggest('bitso',array_replace($raw,['internal'=>true]),'deposit')['reason'] ?? null) === 'exchange_internal_flag',
    'explicit CCXT internal flag still awaits user confirmation');
foreach (['txid'=>'chain-hash','address'=>'destination','addressFrom'=>'source','addressTo'=>'destination','tag'=>'123','network'=>'BTC'] as $field=>$value) {
    check(TransferLabels::suggest('bitso',array_replace($raw,[$field=>$value]),'deposit') === null,
        'external '.$field.' evidence prevents fallback user-transfer labeling');
}
foreach (['trade','conversion','reward','fee'] as $type) {
    check(TransferLabels::suggest('coinbase',array_replace($raw,['info'=>['type'=>$type]]),'deposit') === null,
        'native '.$type.' evidence is not relabeled as a user deposit');
}
check(TransferLabels::suggest('bitso',array_replace($raw,['info'=>['method'=>'spei']]),'deposit') === null,
    'bank-route evidence is not relabeled as a user deposit');
check(TransferLabels::suggest('bitso',array_replace($raw,['info'=>['method'=>'unknown_future_route']]),'deposit') === null,
    'unrecognized explicit route remains unknown instead of being inferred internal');
check(TransferLabels::suggest('bitso',array_replace($peer,['txid'=>'contradictory-chain-hash']),'withdrawal') === null,
    'contradictory external evidence overrides a peer route');
check(TransferLabels::suggest('coinbase',array_replace($coinbase,['info'=>['type'=>'send','network'=>['status'=>'off_blockchain','hash'=>'chain-hash']]]),'withdrawal') === null,
    'actual Coinbase blockchain hash overrides off-blockchain labeling');
check(TransferLabels::suggest('coinbase',array_replace($raw,['info'=>['type'=>'transfer','source_portfolio_uuid'=>'private-source','target_portfolio_uuid'=>'private-target']]),'deposit') === null,
    'Coinbase portfolio transfers do not become incoming user payments');
check(TransferLabels::suggest('ndax',array_replace($ndax,['info'=>['ReferenceType'=>'Transfer','Counterparty'=>7,
    'TemplateForm'=>'{"ExternalAddress":"chain-address"}']]),'deposit') === null,
    'NDAX JSON payment routing is inspected before applying a fallback label');
check(!str_contains(Numbers::json([$peerLabel,TransferLabels::suggest('ndax',$ndax,'deposit')]),'PRIVATE_'),
    'label evidence contains no recipient, note or counterparty values');

$explicitPeer = $peer; $explicitPeerLabel = $peerLabel;
// Retain the original fallback/review regressions for movements without a native route.
$peer['info'] = ['details'=>['note'=>'PRIVATE_NOTE']];
$peerLabel = TransferLabels::suggest('bitso',$peer,'withdrawal');

$root = sys_get_temp_dir().'/trademinator-transfer-labels-'.bin2hex(random_bytes(6));
Backup::ensureDirectory($root);
try {
    $db = new Database($root.'/client.sqlite');
    $ledger = new Ledger($db);
    $account = $ledger->createAccount('bitso','Soft labels',null,['USD'=>'100']);
    $other = $ledger->createAccount('bitso','Other account');
    $s = settings($account); $a = $s['accounts'][0];
    $reviews = new TransferReviews($db);

    // Upgrade existing schema-6/7 annotations using a fresh complete snapshot,
    // without touching the independently validated financial records.
    $confirmedAccount = $ledger->createAccount('bitso','Explicit Bitso routes');
    $confirmedOps = []; $confirmedLabels = [];
    foreach (['deposit','withdrawal'] as $kind) {
        $native = array_replace($explicitPeer,['id'=>'explicit-'.$kind]);
        $native['info']['details'] = [$kind === 'deposit' ? 'sender_ref' : 'recipient_ref'=>'PRIVATE_PARTY'];
        $nativeOp = Normalize::operation($native,$kind);
        $confirmedOps[] = $nativeOp;
        $confirmedLabels[$nativeOp['id']] = TransferLabels::suggest('bitso',$native,$kind);
    }
    $confirmedBatch = batch($confirmedOps,$confirmedLabels);
    $legacyBatch = $confirmedBatch;
    foreach ($legacyBatch['transfer_labels'] as &$legacyLabel) { $legacyLabel['status'] = 'pending_confirmation'; }
    unset($legacyLabel);
    $reviews->observe($confirmedAccount,$legacyBatch);
    $legacyRows = $db->pdo()->query("SELECT * FROM transfer_labels WHERE account_id='$confirmedAccount' ORDER BY id")->fetchAll();
    $oldPendingSnapshot = $ledger->accountSnapshot($confirmedAccount,true);
    $confirmedFinancial = $ledger->accountSnapshot($confirmedAccount);
    $confirmedRevision = $db->revision($confirmedAccount);
    $incompleteBatch = $confirmedBatch; $incompleteBatch['complete'] = false;
    $reviews->observe($confirmedAccount,$incompleteBatch);
    check($reviews->pending($confirmedAccount)['count'] === 2 && $reviews->confirmed($confirmedAccount)['count'] === 0,
        'incomplete history cannot upgrade previously pending Bitso classifications');
    $conflictingBatch = $confirmedBatch; $conflictingBatch['operations'][0]['amount'] = '11';
    refuses(fn () => $reviews->observe($confirmedAccount,$conflictingBatch),
        'confirmation refuses a changed financial identity');
    check($reviews->pending($confirmedAccount)['count'] === 2,
        'a conflicting confirmation leaves the pending evidence untouched');
    $reviews->observe($confirmedAccount,$confirmedBatch);
    $confirmedProjection = $reviews->confirmed($confirmedAccount);
    $confirmedKinds = array_column($confirmedProjection['items'],'label','kind'); ksort($confirmedKinds);
    check($reviews->pending($confirmedAccount)['count'] === 0 && $confirmedProjection['count'] === 2
        && array_unique(array_column($confirmedProjection['items'],'status')) === ['confirmed']
        && $confirmedKinds === ['deposit'=>'user_deposit','withdrawal'=>'user_withdrawal'],
        'fresh Bitso evidence upgrades both directions and removes them from the pending queue');
    $confirmedRows = $db->pdo()->query("SELECT * FROM transfer_labels WHERE account_id='$confirmedAccount' ORDER BY id")->fetchAll();
    foreach ($confirmedRows as $index=>$row) {
        check(array_intersect_key($row,array_flip(['id','financial_fingerprint','first_observed_at','amount','currency','occurred_at']))
            === array_intersect_key($legacyRows[$index],array_flip(['id','financial_fingerprint','first_observed_at','amount','currency','occurred_at'])),
            'confirmation retains original review identity and financial evidence for '.$row['kind']);
    }
    check($ledger->accountSnapshot($confirmedAccount) === $confirmedFinancial && $db->revision($confirmedAccount) === $confirmedRevision,
        'confirming a transfer classification creates no financial inventory or revision');
    check(!str_contains(Numbers::json($confirmedProjection),'PRIVATE_')
        && !str_contains(Numbers::json($confirmedProjection),'explicit-deposit')
        && $confirmedProjection['items'][0]['evidence']['counterparty_present'],
        'confirmed projection retains only a counterparty-presence flag and safe evidence');
    $confirmedLimited = $reviews->confirmed($confirmedAccount,1);
    check($confirmedLimited['count'] === 2 && count($confirmedLimited['items']) === 1 && $confirmedLimited['truncated'],
        'confirmed transfers expose bounded status items and the complete count');
    $reviews->observe($confirmedAccount,$confirmedBatch);
    $reviews->observe($confirmedAccount,$legacyBatch);
    check($db->pdo()->query("SELECT * FROM transfer_labels WHERE account_id='$confirmedAccount' ORDER BY id")->fetchAll() === $confirmedRows,
        'replay and weaker subsequent evidence cannot downgrade or duplicate confirmed transfers');
    $ledger->restoreAccountSnapshot($oldPendingSnapshot);
    check($reviews->confirmed($confirmedAccount) === $confirmedProjection,
        'restoring an older pending snapshot preserves exchange-confirmed classifications');
    $confirmedRestore = new Database(':memory:');
    (new Ledger($confirmedRestore))->restoreAccountSnapshot($ledger->accountSnapshot($confirmedAccount,true));
    check((new TransferReviews($confirmedRestore))->confirmed($confirmedAccount) === $confirmedProjection,
        'confirmed classifications survive a full snapshot restore');
    $otherExchange = $ledger->createAccount('ndax','No implicit confirmation');
    refuses(fn () => $reviews->observe($otherExchange,$confirmedBatch),
        'Bitso confirmation evidence cannot confirm another exchange');
    $invalidConfirmation = $confirmedBatch;
    $invalidConfirmation['transfer_labels']['deposit:explicit-deposit']['evidence']['peer_route'] = false;
    refuses(fn () => $reviews->observe($confirmedAccount,$invalidConfirmation),
        'automatic confirmation requires explicit peer-route evidence');
    $invalidConfirmation['transfer_labels']['deposit:explicit-deposit'] = array_replace($peerLabel,['label'=>'user_deposit','status'=>'confirmed']);
    refuses(fn () => $reviews->observe($confirmedAccount,$invalidConfirmation),
        'a missing-route guess cannot be persisted as an exchange confirmation');
    foreach (['confirmed','explained','superseded'] as $humanStatus) {
        $humanAccount = $ledger->createAccount('bitso','Prior human decision '.$humanStatus);
        $reviews->observe($humanAccount,$legacyBatch);
        $db->pdo()->prepare('UPDATE transfer_labels SET status=? WHERE account_id=?')->execute([$humanStatus,$humanAccount]);
        $humanRows = $db->pdo()->query("SELECT * FROM transfer_labels WHERE account_id='$humanAccount' ORDER BY id")->fetchAll();
        $reviews->observe($humanAccount,$confirmedBatch);
        check($db->pdo()->query("SELECT * FROM transfer_labels WHERE account_id='$humanAccount' ORDER BY id")->fetchAll() === $humanRows,
            'native confirmation preserves a prior '.$humanStatus.' decision');
    }

    $op = Normalize::operation($peer,'withdrawal');
    $suggested = batch([$op],[$op['id']=>$peerLabel],['USD'=>'89.75']);

    // Native endpoint and unified-ledger paths both preserve the original normalized finances.
    $rest = new TransferRest();
    $rest->withdrawals = [$peer,rawTransfer('external',['txid'=>'chain-hash']),rawTransfer('pending',['status'=>'pending'])];
    $rest->deposits = [rawTransfer('incoming')];
    $adapter = new CcxtAccount('bitso',$s,$a,$rest);
    $snapshot = await($adapter->snapshot(['symbols'=>[],'known_orders'=>[],'since_ms'=>0]));
    check(count($snapshot['operations']) === 3 && count($snapshot['transfer_labels']) === 2,
        'snapshot labels only confirmed candidate transfers alongside external movements');
    $captured = array_column($snapshot['operations'],null,'id');
    check($captured[$op['id']] === $op && $captured[$op['id']]['fees'] === [['currency'=>'USD','cost'=>'0.25']],
        'snapshot labeling preserves normalized amounts, fees and evidence exactly');
    check(!str_contains(Numbers::json($snapshot),'PRIVATE_'),'snapshot excludes native private transfer metadata');
    $ledgerRest = new TransferRest();
    $ledgerRest->id = 'ndax'; $ledgerRest->has = ['fetchLedger'=>true];
    $ledgerRest->ledger = [array_replace($ndax,['id'=>'ledger-private-id','referenceId'=>'ledger-operation',
        'type'=>'transaction','direction'=>'in'])];
    $ledgerAdapter = new CcxtAccount('ndax',$s,$a,$ledgerRest);
    $ledgerSnapshot = await($ledgerAdapter->snapshot(['symbols'=>[],'known_orders'=>[],'since_ms'=>0]));
    check(($ledgerSnapshot['transfer_labels']['deposit:ledger-operation']['reason'] ?? null) === 'ndax_transfer_reference',
        'ledger fallback retains native evidence long enough to create the review label');

    $before = $ledger->accountSnapshot($account); $revision = $db->revision($account);
    $reviews->observe($account,$suggested);
    $pending = $reviews->pending($account);
    check($pending['count'] === 1 && count($pending['items']) === 1 && !$pending['truncated'],
        'a confirmed movement produces one durable pending review');
    $item = $pending['items'][0];
    check($item['label'] === 'user_withdrawal' && $item['status'] === 'pending_confirmation'
        && $item['currency'] === 'USD' && $item['amount'] === '10' && $item['occurred_at'] === Numbers::iso($op['timestamp']),
        'pending review exposes exact amount, currency, time and provisional meaning');
    check(!str_contains(Numbers::json($pending),'private-transfer-id') && !str_contains(Numbers::json($pending),'PRIVATE_'),
        'pending projection excludes raw exchange identifiers and private counterparty details');
    check($ledger->accountSnapshot($account) === $before && $db->revision($account) === $revision,
        'observation creates no financial row, price basis, checkpoint or revision');
    $stored = $db->pdo()->query('SELECT * FROM transfer_labels')->fetchAll();
    $reviews->observe($account,$suggested);
    check($db->pdo()->query('SELECT * FROM transfer_labels')->fetchAll() === $stored,
        'identical evidence replay does not rewrite the review row');
    $privateProposal = $suggested;
    $privateProposal['transfer_labels'][$op['id']]['note'] = 'PRIVATE_DESCRIPTOR';
    $privateProposal['transfer_labels'][$op['id']]['evidence']['counterparty'] = 'PRIVATE_EVIDENCE';
    $reviews->observe($account,$privateProposal);
    check($db->pdo()->query('SELECT * FROM transfer_labels')->fetchAll() === $stored,
        'unknown descriptor fields cannot persist private text or change a review');
    $badProposal = $suggested;
    $badProposal['transfer_labels'][$op['id']]['reason'] = 'PRIVATE_REASON';
    refuses(fn () => $reviews->observe($account,$badProposal),'arbitrary raw text cannot become a review reason');
    $reviews->observe($account,batch([],[]));
    $reviews->observe($account,batch([$op],[]));
    check($reviews->pending($account) === $pending,'omitted history and absent route metadata do not discard a pending review');
    $partial = $suggested; $partial['complete'] = false;
    $reviews->observe($other,$partial);
    check($reviews->pending($other)['count'] === 0,'an incomplete snapshot does not publish review observations');
    $reviews->observe($other,batch([],[$op['id']=>$peerLabel]));
    check($reviews->pending($other)['count'] === 0,'a label without its actual exchange operation cannot invent a movement');
    $reviews->observe($other,$suggested);
    $otherReview = $reviews->pending($other);
    check($otherReview['count'] === 1 && $otherReview['items'][0]['ref'] !== $item['ref'],
        'identical exchange IDs in separate accounts keep distinct review references');
    $changed = $suggested; $changed['operations'][0]['amount'] = '11';
    refuses(fn () => $reviews->observe($account,$changed),'conflicting financial identity cannot rewrite an earlier review');
    check($reviews->pending($account) === $pending,'conflicting observation leaves previous review evidence intact');

    $secondRaw = rawTransfer('second'); $secondOp = Normalize::operation($secondRaw,'deposit');
    $reviews->observe($other,batch([$secondOp],[$secondOp['id']=>TransferLabels::suggest('bitso',$secondRaw,'deposit')]));
    $limited = $reviews->pending($other,1);
    check($limited['count'] === 2 && count($limited['items']) === 1 && $limited['truncated'],
        'status pagination reports the total review backlog without dropping persisted items');

    $longAccount = $ledger->createAccount('bitso','Maximum exchange identity');
    foreach (['deposit','withdrawal'] as $index=>$kind) {
        $longRaw = rawTransfer(str_repeat('x',200));
        $longOp = Normalize::operation($longRaw,$kind);
        $reviews->observe($longAccount,batch([$longOp],[$longOp['id']=>TransferLabels::suggest('bitso',$longRaw,$kind)]));
        check($reviews->pending($longAccount)['count'] === $index+1,
            'review labeling accepts a maximum-length exchange identity after its '.$kind.' prefix');
    }

    // Financial failure occurs after durable review capture, and never fabricates its missing source.
    $failed = $ledger->createAccount('bitso','Missing acquisition');
    $ledger->registerMarket($failed,'USD/EUR','USD','EUR');
    $failedSettings = settings($failed);
    $failedBatch = batch([$op],[$op['id']=>$peerLabel]);
    refuses(fn () => (new Reconciler($db))->apply($failed,$failedBatch,$failedSettings['accounts'][0]),
        'soft labeling never permits an outflow without its earlier acquisition');
    check($reviews->pending($failed)['count'] === 1
        && $ledger->accountSnapshot($failed)['operations'] === [],
        'financial rollback retains the review while rolling back financial imports');
    $runtimeAccount = $ledger->createAccount('bitso','Runtime inventory failure');
    $ledger->registerMarket($runtimeAccount,'USD/EUR','USD','EUR');
    $runtimeSettings = settings($runtimeAccount);
    $runtimeRest = new TransferRest(); $runtimeRest->withdrawals = [$peer];
    $runtimeAdapter = new CcxtAccount('bitso',$runtimeSettings,$runtimeSettings['accounts'][0],$runtimeRest);
    $runtime = new Runtime($db,$runtimeSettings,fn () => $runtimeAdapter);
    try {
        $runtime->tick((int)floor(microtime(true)*1000));
        $status = $runtime->status()['accounts'][$runtimeAccount];
        check($status['state'] === 'REVIEW_REQUIRED' && !$status['rest_ok']
            && $status['warning'] === 'user_deposit_confirmation_required'
            && ($status['last_review']['label'] ?? null) === 'user_deposit'
            && ($status['last_review']['status'] ?? null) === 'pending_confirmation'
            && !isset($status['last_error']) && $status['transfer_review']['count'] === 1
            && ($status['unclassified_review']['count'] ?? 0) === 1,
            'runtime retains the withdrawal review and soft-labels its unattributed incoming funds for confirmation');
        check($ledger->accountSnapshot($runtimeAccount)['operations'] === [],
            'runtime review capture does not bypass inventory validation or create balancing deposits');
    } finally { $runtime->close(); }

    // Reconciliation and Hard H still use financial evidence, independently of the review label.
    $ledger->registerMarket($account,'BTC/USD','BTC','USD');
    $reconciler = new Reconciler($db);
    check($reconciler->apply($account,$suggested,$a)['reconciled']
        && $ledger->expectedBalances($account) === ['USD'=>'89.75'],
        'a soft-labeled withdrawal retains its independently confirmed fee in accounting');
    $snapshotWithReviews = $ledger->accountSnapshot($account,true);
    check(count($snapshotWithReviews['transfer_labels'] ?? []) === 1,
        'explicit full snapshot includes durable review evidence for recovery');
    $restored = new Database(':memory:');
    $restoredLedger = new Ledger($restored); $restoredLedger->restoreAccountSnapshot($snapshotWithReviews);
    check((new TransferReviews($restored))->pending($account) === $reviews->pending($account),
        'full snapshot restores pending reviews into a fresh database');
    $restored->pdo()->exec("UPDATE transfer_labels SET status='confirmed'");
    (new TransferReviews($restored))->observe($account,$suggested);
    $restoredLedger->restoreAccountSnapshot($snapshotWithReviews);
    check((new TransferReviews($restored))->pending($account)['count'] === 0
        && $restored->pdo()->query('SELECT status FROM transfer_labels')->fetchColumn() === 'confirmed',
        'future human confirmation survives exchange replay and restoration of an older pending snapshot');
    $conflictingSnapshot = $snapshotWithReviews;
    $conflictingSnapshot['transfer_labels'][0]['amount'] = '11';
    $restoredBefore = $restoredLedger->accountSnapshot($account,true);
    refuses(fn () => $restoredLedger->restoreAccountSnapshot($conflictingSnapshot),
        'snapshot restore rejects a review whose financial identity conflicts with live evidence');
    check($restoredLedger->accountSnapshot($account,true) === $restoredBefore,
        'review restore conflict rolls back financial and review changes together');
    $oldSnapshot = $ledger->accountSnapshot($account);
    $ledger->restoreAccountSnapshot($oldSnapshot);
    check($reviews->pending($account)['count'] === 1,'restoring an older financial-only snapshot retains live review evidence');
    $hard = new HardPoints($db,$root); $candidate = $hard->capture($account);
    check($candidate['eligible'],'a provisional description does not fabricate or invalidate otherwise reconciled financial evidence');
    $suggested['at_ms'] = $candidate['cutoff_ms']+1;
    check($hard->verifyPending($account,$suggested)['verification'] === 'verified','Hard H1 verifies the same financial movement with a pending label');
    $hard->promote($account,$candidate['token']);
    check($ledger->accountSnapshot($account)['operations'] === [] && $reviews->pending($account)['count'] === 1,
        'financial-prefix archival does not erase the outstanding review');
    $archivePath = $root.'/hard-points/'.$account.'/'.$candidate['id'];
    $hardHash = hash_file('sha256',$archivePath.'/hard.json');
    $sqliteHash = hash_file('sha256',$archivePath.'/archive.sqlite');
    $revision = $db->revision($account);
    $reviews->observe($account,$suggested);
    check(hash_file('sha256',$archivePath.'/hard.json') === $hardHash
        && hash_file('sha256',$archivePath.'/archive.sqlite') === $sqliteHash && $db->revision($account) === $revision,
        'reobserving an archived movement preserves Hard H0 files and financial revision');
    $archivedConfirmation = $suggested;
    $archivedConfirmation['transfer_labels'][$op['id']] = $explicitPeerLabel;
    $reviews->observe($account,$archivedConfirmation);
    check($reviews->pending($account)['count'] === 0 && $reviews->confirmed($account)['count'] === 1
        && hash_file('sha256',$archivePath.'/hard.json') === $hardHash
        && hash_file('sha256',$archivePath.'/archive.sqlite') === $sqliteHash && $db->revision($account) === $revision,
        'confirming a reobserved archived transfer updates only the live annotation and preserves Hard H0 hashes');
    unset($runtime,$runtimeAdapter,$adapter,$ledgerAdapter,$hard,$reconciler,$reviews,$ledger,$db);
    $db = new Database($root.'/client.sqlite'); $reviews = new TransferReviews($db);
    check($reviews->confirmed($account)['count'] === 1 && $reviews->confirmed($confirmedAccount)['count'] === 2
        && $reviews->pending($failed)['count'] === 1
        && $reviews->pending($runtimeAccount)['count'] === 1,
        'confirmed transfers and financially rejected reviews all survive daemon restart');
    check($db->integrityCheck()['ok'],'review retention and financial archival preserve database integrity');

    // Upgrade exactly schema 5, with a verified backup and an injected rollback case.
    $migrationDir = $root.'/migration'; Backup::ensureDirectory($migrationDir);
    $migration = new Database($migrationDir.'/client.sqlite');
    $migrationLedger = new Ledger($migration); $migrationAccount = $migrationLedger->createAccount('bitso','Schema five',null,['USD'=>'7']);
    $migrationBefore = $migrationLedger->accountSnapshot($migrationAccount);
    unset($migrationLedger,$migration);
    $pdo = rawDatabase($migrationDir.'/client.sqlite');
    $pdo->exec('DROP TABLE movement_reviews');
    $pdo->exec('DROP TABLE transfer_labels'); $pdo->exec('DELETE FROM schema_migrations WHERE version>=6');
    check(Database::schemaVersion($pdo) === 5,'migration regression starts from an exact schema-5 table set');
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); $pdo = null;
    Backup::ensureDirectory($migrationDir.'/fault'); copy($migrationDir.'/client.sqlite',$migrationDir.'/fault/client.sqlite');
    $pdo = rawDatabase($migrationDir.'/fault/client.sqlite');
    $pdo->exec("CREATE TRIGGER reject_v6 BEFORE INSERT ON schema_migrations WHEN NEW.version=6 BEGIN SELECT RAISE(ABORT,'migration fault'); END");
    $pdo = null;
    $migration = new Database($migrationDir.'/client.sqlite');
    check(Database::schemaVersion($migration->pdo()) === Database::SCHEMA_VERSION
        && (new Ledger($migration))->accountSnapshot($migrationAccount) === $migrationBefore,
        'schema-6 migration preserves all preexisting financial evidence');
    $backups = (new Backup($migration,$migrationDir))->list('pre-migration');
    check(count($backups) === 1 && Backup::verifySnapshot($backups[0]['path'])['schema_version'] === 5,
        'schema-6 migration publishes a verified schema-5 recovery backup');
    refuses(fn () => new Database($migrationDir.'/fault/client.sqlite'),'injected schema-6 migration failure aborts the upgrade');
    $pdo = rawDatabase($migrationDir.'/fault/client.sqlite');
    check(Database::schemaVersion($pdo) === 5
        && $pdo->query("SELECT name FROM sqlite_master WHERE name IN ('transfer_labels','movement_reviews')")->fetchColumn() === false,
        'failed schema-6 migration rolls back its review table and migration version');
} finally {
    unset($pdo,$migration,$restoredLedger,$restored,$reviews,$ledger,$db,$runtime,$hard,$reconciler);
    gc_collect_cycles(); Backup::removeTree($root);
}
echo json_encode(['suite'=>'m3-transfer-labels','status'=>'passed','checks'=>$checks,'network_requests'=>0,
    'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT).PHP_EOL;
