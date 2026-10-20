<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\{Database,Ledger};
use Trademinator\Client\M3\{InventoryEvidenceError,InventoryPreview,Reconciler,Settings,SyncDiagnostics};

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks; echo 'PASS '.$message.PHP_EOL;
}
function failed(callable $action): Throwable {
    try { $action(); } catch (Throwable $error) { return $error; }
    throw new RuntimeException('Expected preview failure.');
}
function operation(string $id, string $kind, string $amount, int $at): array {
    return ['id'=>$id,'kind'=>$kind,'currency'=>'BTC','amount'=>$amount,'timestamp'=>$at,
        'fees'=>[],'txid'=>null,'address'=>null,'tag'=>null,'network'=>null];
}
function snapshot(array $operations): array {
    return ['complete'=>true,'at_ms'=>4000,'balances'=>[],'orders'=>[],'trades'=>[],'operations'=>$operations];
}
function readPreview(string $path): PDO {
    return new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
}
$root = sys_get_temp_dir().'/m3-disk-preview-'.bin2hex(random_bytes(8)); mkdir($root,0700);
try {
    $db = new Database($root.'/client.sqlite'); $ledger = new Ledger($db);
    $id = $ledger->createAccount('fixture','Preview'); $other = $ledger->createAccount('fixture','Other account');
    $ledger->registerMarket($id,'BTC/USD','BTC','USD');
    $settings = Settings::validate(['accounts'=>[['account_id'=>$id,'history_since_ms'=>0,
        'api_key_env'=>'UNUSED','secret_env'=>'UNUSED']]])['accounts'][0];
    $reconciler = new Reconciler($db);
    $bad = snapshot([operation('deposit','deposit','1',1000),operation('withdrawal','withdrawal','1.25',2000),operation('late','deposit','0.25',3000)]);
    $before = $ledger->accountSnapshot($id); $otherBefore = $ledger->accountSnapshot($other);
    $error = failed(fn()=>$reconciler->validateInventory($id,$bad,$settings));
    check($error instanceof InventoryEvidenceError && $error->diagnostic['inventory_shortfall']==='0.25', 'disk validation preserves chronological inventory rejection');
    $info = $reconciler->inventoryPreview($id); $path = $info['path'];
    check($path === $root.'/reconciliation/inventory-'.$id.'.sqlite' && is_file($path), 'failed preview remains at the documented account-specific disk path');
    check($info['status']==='failed' && $info['stage']==='rebuild' && $info['finished_at_ms'] >= $info['started_at_ms'], 'preview metadata identifies the failed stage and timestamps');
    $copy = readPreview($path);
    check((int)$copy->query('SELECT COUNT(*) FROM operations')->fetchColumn()===3, 'failed rebuild retains ingested history for SQLite inspection');
    check($copy->query('SELECT id FROM exchange_accounts')->fetchAll(PDO::FETCH_COLUMN)===[$id], 'preview contains only the selected account');
    check($copy->query('PRAGMA integrity_check')->fetchColumn()==='ok' && $copy->query('PRAGMA foreign_key_check')->fetchAll()===[], 'retained failure copy is a valid standalone SQLite database');
    check(!is_file($path.'-wal') && !is_file($path.'-shm'), 'retained history does not depend on a separate WAL file');
    unset($copy);
    check($before===$ledger->accountSnapshot($id) && $otherBefore===$ledger->accountSnapshot($other), 'preview failure leaves both live financial accounts unchanged');
    if (PHP_OS_FAMILY !== 'Windows') {
        check((fileperms($path)&0777)===0600 && (fileperms(dirname($path))&0777)===0700, 'financial preview files and directories use private Unix permissions');
    }
    $again = failed(fn()=>$reconciler->validateInventory($id,$bad,$settings));
    check($again instanceof InventoryEvidenceError && $again->diagnostic===$error->diagnostic
        && $reconciler->inventoryPreview($id)['path']===$path, 'retries replace one preview without changing financial diagnostics');
    check(count(glob($root.'/reconciliation/*.sqlite'))===1, 'retries do not accumulate database files');

    $good = snapshot([operation('fresh','deposit','2',1000),operation('fresh-out','withdrawal','1',2000)]);
    // Simulate sidecars left by an interrupted prior attempt; the account lock is free.
    file_put_contents($path.'-wal','stale'); file_put_contents($path.'-shm','stale'); file_put_contents($path.'-journal','stale');
    $reconciler->validateInventory($id,$good,$settings);
    $copy = readPreview($path);
    check((int)$copy->query('SELECT COUNT(*) FROM operations')->fetchColumn()===2
        && (int)$copy->query("SELECT COUNT(*) FROM operations WHERE exchange_operation_id='late'")->fetchColumn()===0,
        'next validation rebuilds from live state rather than reusing stale preview history');
    check($reconciler->inventoryPreview($id)['status']==='passed' && $copy->query('PRAGMA integrity_check')->fetchColumn()==='ok', 'successful validation also leaves an inspectable copy');
    unset($copy);
    check($before===$ledger->accountSnapshot($id), 'successful preview does not commit fetched history to the live ledger');
    unset($reconciler);
    check(is_file($path), 'disk preview survives reconciler destruction');

    $held = new InventoryPreview($db,$id); $heldDb = $held->database();
    check($heldDb->pdo()->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)[0]['file']===$path,
        'validation connection actually uses the disk database');
    check((int)$heldDb->pdo()->query('PRAGMA cache_size')->fetchColumn()===-8192
        && (int)$heldDb->pdo()->query('PRAGMA temp_store')->fetchColumn()===1,
        'disposable database bounds its page cache and spills SQLite temporary data to files');
    check((int)$db->pdo()->query('PRAGMA synchronous')->fetchColumn()===2
        && $db->pdo()->query('PRAGMA journal_mode')->fetchColumn()==='wal', 'live SQLite durability settings remain unchanged');
    $hash = hash_file('sha256',$path);
    $busy = failed(fn()=>new InventoryPreview($db,$id));
    check($busy->getMessage()==='Inventory validation is already running for this account.'
        && hash_file('sha256',$path)===$hash, 'concurrent writer is rejected before touching the active preview');
    $busyReconciler = new Reconciler($db);
    failed(fn()=>$busyReconciler->validateInventory($id,$good,$settings));
    check($busyReconciler->inventoryPreview($id)['status']==='failed'
        && $busyReconciler->inventoryPreview($id)['stage']==='open'
        && $busyReconciler->inventoryPreview($id)['path']===null && hash_file('sha256',$path)===$hash,
        'workspace setup failure cannot masquerade as a completed preview');
    unset($busyReconciler);
    $description = SyncDiagnostics::describe($busy,'fixture');
    check($description['detail']===$busy->getMessage() && $description['source_file']==='m3/InventoryPreview.php', 'workspace failures have explicit sanitized diagnostics');
    $otherPreview = new InventoryPreview($db,$other);
    check($otherPreview->path!==$path && is_file($otherPreview->path), 'another account has an independent preview and lock');
    $otherPreview->close(); unset($otherPreview,$heldDb); $held->close(); unset($held);

    if (PHP_OS_FAMILY !== 'Windows') {
        unlink($path); symlink($db->path(),$path);
        $hash = hash_file('sha256',$db->path());
        $unsafe = failed(fn()=>new InventoryPreview($db,$id));
        check($unsafe->getMessage()==='Cannot reset inventory validation database.' && is_link($path)
            && hash_file('sha256',$db->path())===$hash, 'preview symlink cannot overwrite the live database');
        unlink($path);
    }
    $reconciler = new Reconciler($db);
    $badSettings = $settings; $badSettings['currency_markets']['BTC']='ETH/USD';
    $generic = failed(fn()=>$reconciler->validateInventory($id,$good,$badSettings));
    check(!$generic instanceof InventoryEvidenceError && $reconciler->inventoryPreview($id)['status']==='failed'
        && $reconciler->inventoryPreview($id)['stage']==='ingest' && is_file($path), 'non-inventory failures also preserve the disk copy and failing stage');
    $reconciler->validateInventory($id,$good,$settings);
    check($reconciler->inventoryPreview($id)['status']==='passed', 'failure releases the lock for the next validation');
    $hash = hash_file('sha256',$path); $incomplete = $good; $incomplete['complete']=false;
    failed(fn()=>$reconciler->validateInventory($id,$incomplete,$settings));
    check(hash_file('sha256',$path)===$hash, 'incomplete snapshots do not replace the previous preview');

    $memory = new Database(':memory:'); $temporary = new InventoryPreview($memory,$id); $temporaryPath = $temporary->path;
    check(is_file($temporaryPath), 'in-memory fixtures still perform validation on disk');
    $temporary->close();
    check(!file_exists(dirname($temporaryPath)), 'ephemeral fixture previews clean up their private temporary directory');
    unset($temporary,$memory,$reconciler,$ledger,$db);
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
echo json_encode(['suite'=>'m3-inventory-preview','status'=>'passed','checks'=>$checks,'network_requests'=>0],JSON_PRETTY_PRINT).PHP_EOL;
