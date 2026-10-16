<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/m1/bootstrap.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\ConsoleEvents;
use Trademinator\Client\M1\Daemon;
use Trademinator\Client\M1\EventRecord;
use Trademinator\Client\M1\Paths;

$count = 0;
function check(bool $pass, string $message): void { global $count; if (!$pass) { throw new RuntimeException($message); } ++$count; }
function rejects(callable $operation): bool { try { $operation(); return false; } catch (Throwable) { return true; } }
function contents($stream): string { rewind($stream); return (string) stream_get_contents($stream); }
function render(array $record, string $format = 'human', string $color = 'never', bool $verbose = false): string {
    $stream = fopen('php://temp', 'w+b');
    try { (new ConsoleEvents($format, $color, $verbose, $stream))->write($record); return contents($stream); }
    finally { fclose($stream); }
}
function removeTree(string $path): void {
    if (is_dir($path) && !is_link($path)) { foreach (scandir($path) ?: [] as $name) { if ($name !== '.' && $name !== '..') { removeTree($path.'/'.$name); } } rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { unlink($path); }
}

$dir = sys_get_temp_dir().'/tm2-console-'.bin2hex(random_bytes(6));
$environment = [];
foreach (['NO_COLOR', 'FORCE_COLOR', 'TERM'] as $key) { $environment[$key] = getenv($key); }
$serverNoColor = $_SERVER['NO_COLOR'] ?? null;
try {
    $record = EventRecord::create('info', 'daemon_started', ['trading_enabled' => false], 'run-fixture', 'Client is running.');
    check(array_keys($record) === ['schema_version','at','level','event','message','run_id','context'], 'Stable versioned schema.');
    check($record['schema_version'] === 1 && $record['run_id'] === 'run-fixture', 'Schema version and process identity included.');
    check((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/D', $record['at']), 'Timestamp includes UTC and milliseconds.');
    check(EventRecord::create('warning','server_unavailable',[],'x')['message'] === 'Server unavailable', 'Readable fallback details.');
    check(rejects(fn() => EventRecord::create('invalid','event',[],'run')), 'Unsupported severity rejected.');

    $plain = render($record);
    $lines = explode("\n", rtrim($plain, "\n"));
    check(count($lines) === 2 && str_starts_with($lines[0], $record['at'].' INFO  [DAEMON_STARTED] Client is running.'), 'Human event has two readable lines with timestamp and severity first.');
    check(str_starts_with($lines[1], '        ') && json_decode(trim($lines[1]),true) === $record['context'], 'Second line is indented compact JSON.');
    check(!str_contains($plain,"\033") && str_contains($plain,$record['at']), 'Plain human output includes timestamp without ANSI.');
    $colored = render($record,'human','always');
    check(str_contains($colored,"\033[") && str_contains($colored,'32;1m'), 'Explicit color renders green info event.');
    $request = EventRecord::create('info','server_request_started',['endpoint'=>'markets'],'run','Fetching subscriptions.');
    check(str_contains(render($request,'human','always'),'36;1m'), 'Requests use cyan.');
    foreach (['warning'=>'33;1m','error'=>'31;1m'] as $level=>$code) {
        check(str_contains(render(EventRecord::create($level,'test',[],'run'),'human','always'),$code), 'Severity has distinct color.');
    }
    $json = render($record,'json','always');
    check(substr_count($json,"\n") === 1 && json_decode($json,true) === $record, 'JSON mode is one complete stable event per line.');
    check(!str_contains($json,"\033"), 'JSON ignores explicit color and remains ANSI-free.');
    $debug = EventRecord::create('debug','server_request_started',[],'run','Debug request.');
    check(render($debug) === '' && render($debug,'json') === '', 'Debug is hidden in normal human and JSON output.');
    check(str_contains(render($debug,'human','never',true),'[SERVER_REQUEST_STARTED]'), 'Verbose human output includes debug.');
    check(json_decode(render($debug,'json','never',true),true) === $debug, 'Verbose JSON includes debug.');

    putenv('NO_COLOR=1'); $_SERVER['NO_COLOR'] = '1'; putenv('FORCE_COLOR=1'); putenv('TERM=xterm-256color');
    check(!str_contains(render($record,'human','auto'),"\033"), 'NO_COLOR disables automatic ANSI.');
    check(str_contains(render($record,'human','always'),"\033"), 'Explicit always overrides NO_COLOR.');
    putenv('NO_COLOR'); unset($_SERVER['NO_COLOR']);
    check(!str_contains(render($record,'human','auto'),"\033"), 'Redirected stream stays plain even with FORCE_COLOR.');
    putenv('TERM=dumb');
    check(!str_contains(render($record,'human','auto'),"\033"), 'Dumb terminal stays plain.');
    check(!str_contains(render($record,'human','never'),"\033"), 'Explicit never stays plain.');
    check(rejects(fn() => new ConsoleEvents('xml')), 'Unsupported output format rejected.');
    check(rejects(fn() => new ConsoleEvents('human','sometimes')), 'Unsupported color mode rejected.');

    $secret = 'tmk_console_secret_fixture_abcdefghijklmnopqrstuvwxyz';
    EventRecord::hideSecret($secret);
    $hostile = "<error>Forged error</error>\033[2J\r\nInjected line\t</>";
    $safe = EventRecord::create('error','hostile_event',['api_key'=>'private','nested'=>['Authorization'=>'Bearer private','clientSecret'=>'private','password'=>'private'],
        'detail'=>'The key '.$secret.' failed.', 'text'=>$hostile, 'markup'=>'<info>literal</info>', 'valid'=>42], 'run', $hostile.' '.$secret);
    $encoded = json_encode($safe, JSON_THROW_ON_ERROR);
    check(!str_contains($encoded,$secret) && !str_contains($encoded,'Bearer private'), 'Registered secret values and credential keys are redacted.');
    check($safe['context']['api_key'] === '[REDACTED]' && $safe['context']['nested']['clientSecret'] === '[REDACTED]', 'Nested camelCase credential keys are redacted.');
    check($safe['context']['valid'] === 42, 'Ordinary context preserves types.');
    $safePlain = render($safe);
    check(substr_count($safePlain,"\n") === 2 && !str_contains($safePlain,"\033") && !str_contains($safePlain,"\r"), 'Terminal controls cannot forge lines or escape sequences.');
    check(str_contains($safePlain,'<error>Forged error</error>') && str_contains($safePlain,'<info>literal</info>'), 'Symfony markup-looking fields remain literal.');
    check(json_decode(trim(explode("\n",$safePlain)[1]),true) === $safe['context'], 'Escaped malicious context remains parseable unchanged.');
    $safeColored = render($safe,'human','always');
    check(!str_contains($safeColored,"\033[2J") && str_contains($safeColored,'<error>Forged error</error>'), 'Untrusted controls and markup cannot override configured colors.');
    check(str_contains($safePlain,'\\n') && str_contains($safePlain,'\\u001b'), 'Dangerous controls retain visible escaped representation.');
    $keyValue = EventRecord::create('info','event',[$secret=>'value'],'run',$secret);
    check(!str_contains(json_encode($keyValue),$secret), 'Secret values are redacted from field names and messages too.');

    $object = new class implements JsonSerializable {
        public function jsonSerialize(): mixed { throw new RuntimeException('Must never serialize objects.'); }
    };
    $resource = fopen('php://temp','w+b');
    $bounded = EventRecord::create('info','event',['object'=>$object,'stream'=>$resource,'nan'=>NAN,'infinity'=>INF,'invalid_utf8'=>"a\xffb"],'run');
    fclose($resource);
    check($bounded['context']['object'] === '[object omitted]', 'Objects are omitted without invoking serialization hooks.');
    check($bounded['context']['stream'] === '[resource omitted]', 'Resources are omitted.');
    check($bounded['context']['nan'] === '[non-finite number]' && $bounded['context']['infinity'] === '[non-finite number]', 'Non-finite numbers cannot break JSON encoding.');
    check(is_string(json_encode($bounded,JSON_THROW_ON_ERROR)), 'Invalid UTF-8 is normalized for JSON.');
    $nested = []; $nested['recursive'] =& $nested;
    check(str_contains(json_encode(EventRecord::create('info','event',$nested,'run')),'maximum depth'), 'Recursive arrays stop at depth limit.');
    $large = EventRecord::create('info','event',array_fill(0,5000,str_repeat('x',5000)),'run');
    check(strlen(json_encode($large)) < 20000 && str_contains(json_encode($large),'truncated'), 'Large context is bounded and reports truncation.');
    $wide = EventRecord::create('info','event',array_fill(0,5000,1),'run');
    check(count($wide['context']) <= 128, 'Wide context is bounded by node count.');
    check(strlen(EventRecord::safeText(str_repeat('x',5000))) === 2048, 'Individual strings have a fixed maximum size.');

    $paths = new Paths($dir); $paths->ensure();
    $consoleStream = fopen('php://temp','w+b');
    $console = new ConsoleEvents('json','never',false,$consoleStream);
    $seen = [];
    $daemon = new Daemon(new Config(['data_dir'=>$dir,'log_level'=>'error']),null,null,null,
        static function(array $event) use ($console,&$seen): void { $seen[]=$event; $console->write($event); });
    $daemon->log('debug','hidden_debug');
    $daemon->log('info','visible_info',['secret'=>$secret],'Visible even when disk logs only errors.');
    $daemon->log('error','visible_error',['detail'=>$secret],'Expected error.');
    $consoleRows = array_map(static fn(string $line): array => json_decode($line,true),explode("\n",trim(contents($consoleStream))));
    $diskRows = array_map(static fn(string $line): array => json_decode($line,true),file($paths->logDir.'/client.jsonl',FILE_IGNORE_NEW_LINES));
    check(count($seen) === 3 && count($consoleRows) === 2, 'Console verbosity is independent of configured file log level.');
    check(count($diskRows) === 1 && $diskRows[0]['event'] === 'visible_error', 'File log level still filters disk records.');
    check($consoleRows[1] === $diskRows[0] && $diskRows[0] === $seen[2], 'Console and disk consume the exact same sanitized event.');
    check(!str_contains(file_get_contents($paths->logDir.'/client.jsonl'),$secret), 'File logging shares credential redaction.');
    fclose($consoleStream);
    check(!rejects(fn() => $console->write($record)), 'Closed console stream is harmless.');
    $failedSink = new Daemon(new Config(['data_dir'=>$dir]),null,null,null,static function(array $event): void { throw new RuntimeException('Console failed.'); });
    check(!rejects(fn() => $failedSink->log('info','console_failure')), 'Throwing console sink does not interrupt file logging.');
    check(str_contains(file_get_contents($paths->logDir.'/client.jsonl'),'console_failure'), 'File log survives console failure.');

    $stopEvents = [];
    $stoppable = new Daemon(new Config(['data_dir'=>$dir]),null,null,null,static function(array $event) use (&$stopEvents): void { $stopEvents[]=$event; });
    $stoppable->stop(); $stoppable->stop();
    check(count($stopEvents) === 1 && $stopEvents[0]['event'] === 'daemon_stopping', 'Repeated stop requests emit one stopping event.');
    $stoppable->setHandler(static function(string $command,array $args): array { throw new RuntimeException('Invalid local command.'); });
    check(rejects(fn() => (new ReflectionMethod($stoppable,'dispatch'))->invoke($stoppable,'market-activate',['token'=>$secret])), 'Rejected mutating command still returns its failure.');
    check(end($stopEvents)['event'] === 'control_command_failed' && !isset(end($stopEvents)['context']['token']), 'Command failures emit safe context without raw arguments.');
    $before = count($stopEvents);
    rejects(fn() => (new ReflectionMethod($stoppable,'dispatch'))->invoke($stoppable,'markets',[]));
    check(count($stopEvents) === $before, 'Read-only polling failures do not create event spam.');
    $lifecycle = [];
    $broken = new Daemon(new Config(['data_dir'=>$dir]), static function() use ($secret): void { throw new RuntimeException('Fixture '.$secret); }, null, null,
        static function(array $event) use (&$lifecycle): void { $lifecycle[]=$event; });
    check($broken->run() === 1, 'Initialization error still exits unsuccessfully with an event sink.');
    check(array_column($lifecycle,'event') === ['daemon_starting','daemon_failed','daemon_stopped'], 'Initialization failure emits a complete event lifecycle.');
    check(!str_contains(json_encode($lifecycle),$secret) && !str_contains((string)$broken->status()['last_error'],$secret), 'Lifecycle errors and crash status redact registered credentials.');

    // An undrained subprocess pipe used to freeze foreground daemons. The sink
    // must return promptly while preserving a bounded amount of pending output.
    if (PHP_OS_FAMILY !== 'Windows') {
        $process = proc_open([PHP_BINARY,'-r','usleep(1500000);'],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot create backpressure test child.'); }
        $pipeConsole = new ConsoleEvents('json','never',true,$pipes[0]);
        $start = hrtime(true);
        for ($i=0;$i<500;$i++) { $pipeConsole->write($large); }
        $elapsed = (hrtime(true)-$start)/1e9;
        check($elapsed < 1.0, 'Undrained pipe cannot block the daemon.');
        check(strlen((new ReflectionProperty($pipeConsole,'pending'))->getValue($pipeConsole)) <= 65536, 'Backpressure buffer stays bounded.');
        fclose($pipes[0]); proc_close($process);
    }
    echo json_encode(['suite'=>'m2-console','status'=>'passed','assertions'=>$count],JSON_PRETTY_PRINT)."\n";
} finally {
    foreach ($environment as $key=>$value) { $value === false ? putenv($key) : putenv($key.'='.$value); }
    if ($serverNoColor === null) { unset($_SERVER['NO_COLOR']); } else { $_SERVER['NO_COLOR']=$serverNoColor; }
    removeTree($dir);
}
