<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M2\Scheduler;

$checks = [];
function check(string $name, bool $condition): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($name); }
    $checks[$name] = 'passed';
}
function timestamp(string $utc): int { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp() * 1000; }
function expectInvalid(callable $operation): bool
{
    try { $operation(); } catch (InvalidArgumentException) { return true; }
    return false;
}

check('One minute checks are 20s, 40s and 59s', Scheduler::offsets('1m', 3) === [20000, 40000, 59000]);
check('Fifteen minute timing scales from the one-minute base', Scheduler::offsets('15m', 3) === [300000, 600000, 885000]);
check('One check is the final pre-close check', Scheduler::offsets('1m', 1) === [59000]);
check('Check count is configurable through 59 without duplicate slots', count(array_unique(Scheduler::offsets('1m', 59))) === 59);
foreach ([0, 60, 3.0, '03', '3.0', null] as $invalid) {
    check('Invalid check count rejected: '.json_encode($invalid), expectInvalid(static fn() => Scheduler::checks($invalid)));
}
check('Numeric check count accepted', Scheduler::checks('3') === 3);
check('Unsupported period rejected', expectInvalid(static fn() => Scheduler::periodMilliseconds('2M')));
foreach (['1m','3m','5m','15m','30m','45m','1h','2h','3h','4h','6h','8h','12h','1d','3d','7d','1w','2w','1M','3M','4M','1y'] as $period) {
    check('Server period accepted: '.$period, Scheduler::periodMilliseconds($period, timestamp('2026-10-15')) > 0);
}

$s = new Scheduler();
$s->configure('one', '1m', 3, 2000);
check('First check not early', $s->due(19999) === []);
check('First scheduled check is claimable', $s->claim('one', 20000) === ['at_ms'=>20000,'deadline_ms'=>21000]);
check('Claimed check is never repeated', $s->claim('one', 20000) === null);
check('Second check accepts its inclusive deadline', $s->claim('one', 41000) === ['at_ms'=>40000,'deadline_ms'=>41000]);
check('Final window starts one tolerance before close', $s->claim('one', 59000) === ['at_ms'=>59000,'deadline_ms'=>61000]);
check('Final check cannot replay in the new candle', $s->claim('one', 60000) === null);
check('Successful checks do not count as missed', $s->status('one', 60000)['missed_windows'] === 0);

foreach ([59000, 60000, 61000] as $now) {
    $s = new Scheduler();
    $s->configure('one', '1m', 3, 58000);
    check('Final window accepts '.$now.'ms', $s->claim('one', $now) === ['at_ms'=>59000,'deadline_ms'=>61000]);
}
$s = new Scheduler();
$s->configure('one', '1m', 3, 58000);
check('Final window expires immediately after close plus tolerance', $s->claim('one', 61001) === null);
check('Expired final advances to a future check', $s->status('one', 61001)['next_check_at_ms'] === 80000);
check('Expired final is counted once', $s->status('one', 61001)['missed_windows'] === 1);
check('Backwards clock does not revive an expired check', $s->claim('one', 60000) === null);

$s = new Scheduler();
$s->configure('one', '1m', 3, 2000);
check('An expired ordinary window is discarded', $s->claim('one', 21001) === null);
check('Ordinary expiry advances to the next scheduled check', $s->status('one', 21001)['next_check_at_ms'] === 40000);
check('A long outage does not create catch-up requests', $s->due(182000) === []);
check('Long outage counts discarded windows without replay', $s->status('one', 182000)['missed_windows'] === 9);
check('Backward clock cannot replay checks discarded during outage', $s->claim('one', 40000) === null);
check('Scheduler resumes at the next real slot', $s->claim('one', 200000)['at_ms'] === 200000);
check('Backward clock cannot duplicate a successful claim', $s->claim('one', 20000) === null);

$s = new Scheduler();
$s->configure('one', '1m', 3, 58000);
$s->claim('one', 59000);
$s->configure('one', '1m', 4, 59000);
check('Changing the check count cannot repeat the final slot', $s->claim('one', 59000) === null);
check('New check count applies without resetting the daemon', $s->status('one', 59000)['next_check_at_ms'] === 75000);
$s->remove('one');
check('Removing a market clears its timetable', $s->status('one', 59000) === null && $s->claim('one', 59000) === null);
$s->configure('one', '1m', 3, 62000);
check('Reactivation schedules only the current and future live windows', $s->status('one', 62000)['next_check_at_ms'] === 80000);
$s->reset();
check('Reset clears all market schedules', $s->due(80000) === []);

$s = new Scheduler();
$s->configure('a', '1m', 3, 2000);
$s->configure('b', '1m', 3, 2000);
$s->claim('a', 20000);
check('An unserved market retains priority', array_column($s->due(20000), 'market_id') === ['b']);
check('Equal urgency rotates toward the least recently served market', array_column($s->due(40000), 'market_id') === ['b', 'a']);
$s = new Scheduler();
$s->configure('short', '1m', 3, 58000);
$s->configure('long', '3m', 3, 58000);
check('Earliest deadline takes priority across different periods', array_column($s->due(60000), 'market_id') === ['short', 'long']);

check('February leap-year month uses 29 days', Scheduler::periodMilliseconds('1M', timestamp('2024-02-20')) === 29*86400000);
check('Ordinary February uses 28 days', Scheduler::periodMilliseconds('1M', timestamp('2025-02-20')) === 28*86400000);
check('March uses 31 days', Scheduler::periodMilliseconds('1M', timestamp('2025-03-20')) === 31*86400000);
check('Leap year candle uses 366 days', Scheduler::periodMilliseconds('1y', timestamp('2024-02-20')) === 366*86400000);
check('Ordinary year candle uses 365 days', Scheduler::periodMilliseconds('1y', timestamp('2025-02-20')) === 365*86400000);
check('Quarter candle begins in January', Scheduler::periodMilliseconds('3M', timestamp('2024-02-20')) === 91*86400000);
check('Four-month candle begins in January', Scheduler::periodMilliseconds('4M', timestamp('2025-03-20')) === 120*86400000);
$s = new Scheduler();
$februaryClose = timestamp('2024-03-01');
$februaryTolerance = intdiv(29*86400000, 60);
$s->configure('calendar', '1M', 3, $februaryClose - $februaryTolerance - 1);
check('Month final window survives the calendar boundary with its own duration', $s->claim('calendar', $februaryClose + $februaryTolerance) === ['at_ms'=>$februaryClose-$februaryTolerance,'deadline_ms'=>$februaryClose+$februaryTolerance]);
check('Next month recalculates actual candle length', $s->status('calendar', $februaryClose + $februaryTolerance)['next_check_at_ms'] === $februaryClose + intdiv(31*86400000, 3));
$s = new Scheduler();
$weekStart = timestamp('2026-10-12');
$s->configure('week', '1w', 3, $weekStart+12000000);
check('Week candles align to Monday UTC', $s->status('week', $weekStart+12000000)['next_check_at_ms'] === $weekStart+intdiv(7*86400000,3));
$s = new Scheduler();
$s->configure('year', '1y', 3, timestamp('2024-02-01'));
check('Year candles align to January 1 UTC', $s->status('year', timestamp('2024-02-01'))['next_check_at_ms'] === timestamp('2024-01-01')+intdiv(366*86400000,3));

echo json_encode(['milestone'=>'M2','suite'=>'scheduler','status'=>'passed','checks'=>$checks], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
