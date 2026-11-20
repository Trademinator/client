<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M4\{Engine, Settings, Store};

function batchConfig(): array
{
    return Settings::validate([
        'account' => 'batch-fixture', 'source_exchange' => 'bitso', 'execution_exchange' => 'bitso',
        'reporting_currency' => 'USD', 'balances' => ['USD' => '10000', 'XRP' => '0'],
        'markets' => ['XRP/USD' => [
            'tick_size' => '0.01', 'amount_step' => '0.01', 'minimum_amount' => '0.01',
            'minimum_cost' => '1', 'period_ms' => 1000, 'evaluation_ms' => 1000,
        ]],
    ]);
}
function batchCandles(int $count): array
{
    $events = [];
    for ($i = 0; $i < $count; $i++) {
        $events[] = [
            'id' => 'warmup-'.$i, 'type' => 'candle', 'time_ms' => ($i + 1) * 1000,
            'symbol' => 'XRP/USD', 'ohlcv' => [$i * 1000, '2', '2.1', '1.9', '2', '100'],
        ];
    }
    return $events;
}
if (($argv[1] ?? '') === '--writer') {
    $dir = $argv[2]; $id = $argv[3]; $store = new Store($dir);
    file_put_contents($dir.'/ready-'.$id, 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($dir.'/start-writers')) {
        if (microtime(true) >= $deadline) { throw new RuntimeException('Writer barrier timed out.'); }
        usleep(5000);
    }
    foreach (array_chunk(batchCandles(500), Store::MAX_BATCH_EVENTS) as $events) { $store->applyBatch($id, $events); }
    echo "writer complete\n";
    exit(0);
}

$checks = 0;
function batchCheck(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($name); }
    $checks++; echo 'PASS '.$name.PHP_EOL;
}
function batchReject(callable $operation, string $name): void
{
    try { $operation(); } catch (RuntimeException) { batchCheck(true, $name); return; }
    throw new RuntimeException($name);
}
$dir = sys_get_temp_dir().'/m4-batch-'.bin2hex(random_bytes(6));
$store = new Store($dir); $config = batchConfig();
$store->create('individual', $config); $store->create('batch', $config);
$events = batchCandles(80);
foreach ($events as $event) { $store->apply('individual', $event); }
foreach (array_chunk($events, Store::MAX_BATCH_EVENTS) as $chunk) { $result = $store->applyBatch('batch', $chunk); }
batchCheck($result === ['sequence' => 80, 'orders' => 0, 'fills' => 0], 'Batch returns final committed sequence and accounting counts');
batchCheck($store->run('batch')['state'] === $store->run('individual')['state'], 'Batch state exactly matches individual event application');
batchCheck(iterator_to_array($store->events('batch')) === $events, 'Batch preserves every original event and its order');
batchCheck($store->replay('batch', 'replayed')['matched'], 'Individual replay of batched events exactly reconstructs persisted state');
$before = $store->run('batch')['state'];
batchCheck($store->applyBatch('batch', array_slice($events, 0, 50)) === ['duplicate' => true], 'Exact batch retry is idempotent');
batchCheck($store->run('batch')['state'] === $before, 'Duplicate batch does not change persisted state');

$clock = ['id' => 'clock-1', 'type' => 'clock', 'time_ms' => 81000];
$result = $store->applyBatch('batch', [$events[0], $clock, $clock]);
batchCheck($result['sequence'] === 81 && count(iterator_to_array($store->events('batch'))) === 81, 'Existing and within-batch duplicates do not consume sequence numbers');
$before = $store->run('batch')['state']; $savedEvents = iterator_to_array($store->events('batch'));
batchReject(static fn() => $store->applyBatch('batch', [
    ['id' => 'rolled-back', 'type' => 'clock', 'time_ms' => 82000],
    [...$clock, 'time_ms' => 83000],
]), 'A conflicting later event rejects the whole batch');
batchCheck($store->run('batch')['state'] === $before && iterator_to_array($store->events('batch')) === $savedEvents, 'Conflict rolls back earlier batch events and state');
batchReject(static fn() => $store->applyBatch('batch', [
    ['id' => 'valid-before-malformed', 'type' => 'clock', 'time_ms' => 82000],
    ['id' => 'malformed', 'type' => 'clock', 'time_ms' => 81000],
]), 'A malformed later event rejects the whole batch');
batchCheck($store->run('batch')['state'] === $before && iterator_to_array($store->events('batch')) === $savedEvents, 'Malformed batch leaves ledger and state unchanged');
batchReject(static fn() => $store->applyBatch('batch', []), 'Empty batches are rejected');
batchReject(static fn() => $store->applyBatch('batch', array_slice($events, 0, 51)), 'Batch bound prevents unbounded writer transactions');
$store = new Store($dir);
batchCheck($store->run('batch')['state'] === $before, 'Restart resumes the exact last committed batch');

// An external writer does not honor the cooperative gate. Its bounded SQLite
// failure must roll back cleanly and release our gate before an exact retry.
$store->create('external-lock', $config);
$blockedStore = new Store($dir);
$blockedDb = (new ReflectionProperty(Store::class, 'db'))->getValue($blockedStore);
$blockedDb->exec('PRAGMA busy_timeout=20');
$external = new PDO('sqlite:'.$dir.'/paper.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$captured = [['id'=>'captured-before-lock','type'=>'clock','time_ms'=>1], ['id'=>'captured-after-lock','type'=>'clock','time_ms'=>2]];
$external->exec('BEGIN IMMEDIATE'); $failure = null;
try { $blockedStore->applyBatch('external-lock', $captured); }
catch (PDOException $error) { $failure = $error; }
finally { $external->exec('ROLLBACK'); $blockedDb->exec('PRAGMA busy_timeout=5000'); }
batchCheck($failure !== null && str_contains($failure->getMessage(), 'database is locked'),
    'An external lock preserves the original bounded BEGIN failure without a spurious rollback error');
batchCheck($store->run('external-lock')['state']['sequence'] === 0 && iterator_to_array($store->events('external-lock')) === [],
    'An unsuccessful transaction start persists none of its captured events');
$recoveredStore = new Store($dir); $recoveredStore->applyBatch('external-lock', $captured);
batchCheck(iterator_to_array($recoveredStore->events('external-lock')) === $captured
    && $recoveredStore->run('external-lock')['state']['sequence'] === 2,
    'The released writer gate permits another connection to retry the exact captured IDs once');

$workers = [];
for ($i = 0; $i < 4; $i++) { $store->create('writer-'.$i, $config); }
try {
    for ($i = 0; $i < 4; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', __FILE__, '--writer', $dir, 'writer-'.$i], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start concurrent fixture writer.'); }
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 15;
    while (count(glob($dir.'/ready-writer-*')) !== 4) {
        if (microtime(true) >= $deadline) { throw new RuntimeException('Concurrent writers did not reach barrier.'); }
        usleep(5000);
    }
    $started = microtime(true); file_put_contents($dir.'/start-writers', 'start');
    foreach ($workers as $index => [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process); unset($workers[$index]);
        batchCheck($code === 0 && trim($out) === 'writer complete' && $error === '', 'Concurrent writer '.$index.' completes without SQLite lock failures');
    }
    $elapsed = microtime(true) - $started;
} finally {
    foreach ($workers as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        proc_close($process);
    }
}
$expected = new Engine($config);
foreach (batchCandles(500) as $event) { $expected->apply($event); }
for ($i = 0; $i < 4; $i++) {
    batchCheck($store->run('writer-'.$i)['state'] === $expected->state && count(iterator_to_array($store->events('writer-'.$i))) === 500, 'Concurrent writer '.$i.' preserves all 500 events and deterministic state');
}
$db = new PDO('sqlite:'.$dir.'/paper.sqlite');
batchCheck($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Shared paper database remains intact after concurrent batches');
echo json_encode(['checks' => $checks, 'concurrent_events' => 2000, 'concurrent_seconds' => round($elapsed, 3), 'network_requests' => 0, 'live_orders_submitted' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
