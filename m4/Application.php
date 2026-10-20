<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\ConsoleEvents;

final class Application
{
    private static function json(string $path): array
    {
        if(!is_file($path) || filesize($path)>134217728) { throw new RuntimeException('Input missing or larger than 128 MiB.'); }
        return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }
    private static function output(mixed $value,?string $path=null): void
    {
        $json=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
        if($path===null) { echo $json;return; }
        $h=fopen($path,'xb');if(!$h) { throw new RuntimeException('Output already exists or cannot be created.'); }
        try { if(fwrite($h,$json)!==strlen($json)) { throw new RuntimeException('Output write failed.'); } }finally { fclose($h); }
    }
    public function run(array $argv): int
    {
        try {
            $options=[];
            foreach(array_slice($argv,2) as $arg) {
                if(!preg_match('/^--(data-dir|config|paper-config|run|new-run|input|output|sets|prefix|symbol|subscription|source-exchange|model-exchange|period|from-ms|to-ms|model-id|family|intelligence|path|warmup|seconds|color)=(.+)$/D',$arg,$m) || isset($options[$m[1]])) { throw new RuntimeException('Unknown or duplicate paper option. Use paper-help.'); }
                $options[$m[1]]=$m[2];
            }
            $command=$argv[1];
            if($command==='paper-help') {
                echo "M4 paper commands (all financial amounts in JSON are decimal strings):\n";
                echo "paper-create --config=FILE [--run=ID] [--paper-config=LEGACY_FILE]\npaper-reset --run=OLD --new-run=NEW\npaper-import --run=ID --input=events.jsonl\npaper-replay --run=SOURCE --new-run=NEW\npaper-recover --run=SOURCE --new-run=NEW --input=complete-capture.json\npaper-report --run=ID [--output=FILE]\npaper-export --run=ID --output=events.jsonl\npaper-sweep --run=SOURCE --sets=FILE --prefix=ID\npaper-models --config=FILE --subscription=UUID --model-exchange=bitso --period=15m\npaper-history --config=FILE --subscription=UUID --source-exchange=binance --period=15m --from-ms=N --to-ms=N --output=FILE\npaper-backtest --run=ID --input=history.json --symbol=BTC/USD --intelligence=recorded|historical|current [--config=FILE --subscription=UUID --model-id=UUID --family=production|shadow --period=15m --model-exchange=bitso --warmup=50000 --path=low_first|high_first]\npaper-live --run=ID --config=FILE --seconds=86400 [--color=auto]\nAll paper commands accept --config=FILE; run defaults to paper_trading.run_id. All local commands accept --data-dir=DIR. Restart paper-live with the same run to resume. Reset always creates a new ID.\n";
                return 0;
            }
            $config=Config::load($options['config']??null,$options['data-dir']??null);
            if(in_array($command,['paper-models','paper-history'],true)) {
                $server=new Server($config);$sub=$options['subscription']??'';$period=$options['period']??'15m';
                if($command==='paper-models') { self::output($server->request($sub,'replay/models',query:['model_exchange'=>$options['model-exchange']??'','period'=>$period]),$options['output']??null);return 0; }
                $from=self::integer($options,'from-ms',0,PHP_INT_MAX);$to=self::integer($options,'to-ms',1,PHP_INT_MAX);$rows=[];$signals=[];$gaps=[];$cursor=$from;$source=$options['source-exchange']??'';$symbol=null;
                do {
                    $page=$server->request($sub,'replay/history',query:['source_exchange'=>$source,'period'=>$period,'from_ms'=>$cursor,'to_ms'=>$to,'limit'=>500]);
                    array_push($rows,...$page['candles']);foreach($page['signals'] as $signal) { $signals[$signal['id']]=$signal; }array_push($gaps,...$page['gaps']);$symbol=$page['symbol'];
                    $next=$page['next_from_ms'];if($next!==null && $next<=$cursor) { throw new RuntimeException('History cursor did not advance.'); }$cursor=$next;
                    if(count($rows)>1000000) { throw new RuntimeException('Split history exports larger than one million candles.'); }
                }while($cursor!==null);
                self::output(['source_exchange'=>$source,'symbol'=>$symbol,'period'=>$period,'candles'=>$rows,'signals'=>array_values($signals),'gaps'=>$gaps],$options['output']??null);return 0;
            }
            $store=new Store($config->dataDir.'/paper');
            if ($command === 'paper-report' && !isset($config->paperTrading['run_id']) && !isset($options['run']) && !isset($config->paperTrading['markets'])) {
                $reports=[];foreach($store->automaticIds() as $id) { $reports[$id]=Report::build($store->run($id)); }
                self::output(['runs'=>$reports],$options['output']??null);return 0;
            }
            $run=$options['run']??$config->paperTrading['run_id']??'';Store::id($run);
            switch($command) {
                case 'paper-create':
                    $settings=isset($options['paper-config'])?self::json($options['paper-config']):PaperConfig::settings($config->paperTrading);
                    $store->create($run,$settings);$result=['run'=>$run,'status'=>'created'];break;
                case 'paper-reset': $store->create($options['new-run']??'', $store->run($run)['config']);$result=['run'=>$options['new-run'],'status'=>'new_run'];break;
                case 'paper-import': $result=['events'=>Replay::import($store,$run,$options['input']??'')];break;
                case 'paper-recover': $result=Recovery::replay($store,$run,$options['new-run']??'',self::json($options['input']??''));break;
                case 'paper-replay': $result=$store->replay($run,$options['new-run']??'');break;
                case 'paper-report': $result=Report::build($store->run($run));break;
                case 'paper-export':
                    $h=fopen($options['output']??'','xb');if(!$h) { throw new RuntimeException('Cannot create event export.'); }
                    try { foreach($store->events($run) as $event) { $line=json_encode($event,JSON_THROW_ON_ERROR).PHP_EOL;if(fwrite($h,$line)!==strlen($line)) { throw new RuntimeException('Event export write failed.'); } } }finally { fclose($h); }
                    self::output(['exported'=>$options['output']]);return 0;
                case 'paper-sweep': $result=Replay::sweep($store,$run,$options['prefix']??'',self::json($options['sets']??''));break;
                case 'paper-live':
                    require_once dirname(__DIR__).'/vendor/autoload.php';
                    $result=Live::run($store,$run,new Server($config),self::integer($options,'seconds',1,604800),new ConsoleEvents('human',$options['color']??'auto',false));break;
                case 'paper-backtest':
                    $history=self::json($options['input']??'');$r=$store->run($run);$symbol=$options['symbol']??'';$mode=$options['intelligence']??'recorded';
                    if(!in_array($mode,['recorded','historical','current'],true)) { throw new RuntimeException('Unknown intelligence mode.'); }
                    if(($history['symbol']??null)!==$symbol || ($history['source_exchange']??null)!==$r['config']['source_exchange']) { throw new RuntimeException('History pair/source must match the paper account.'); }
                    $period=$options['period']??$history['period'];$periodMs=self::periodMs($period);
                    if($period!==$history['period'] || $periodMs!==($r['config']['markets'][$symbol]['period_ms']??null)) { throw new RuntimeException('History and run periods must match.'); }
                    $signals=$history['signals']??[];usort($signals,static fn($a,$b)=>$a['recorded_at_ms']<=>$b['recorded_at_ms']);
                    $server=$mode==='recorded'?null:new Server($config);$index=0;$last=null;
                    $infer=static function(array $window,int $at)use($server,$mode,$signals,&$index,&$last,$options,$r,$period,$periodMs):array {
                        if($mode==='recorded') {
                            while(isset($signals[$index]) && $signals[$index]['recorded_at_ms']<=$at) { $last=$signals[$index++]; }
                            if($last===null || $last['decision_at_ms']>$at || $at-$last['decision_at_ms']>=2*$periodMs) { return ['action'=>'hold','reason'=>'recorded_signal_missing_or_stale']; }
                            return ['source'=>'recorded',...$last];
                        }
                        $answer=$server->request($options['subscription']??'','replay/infer',['source_exchange'=>$r['config']['source_exchange'],
                            'model_exchange'=>$options['model-exchange']??$r['config']['execution_exchange'],'period'=>$period,'model_id'=>$options['model-id']??'',
                            'family'=>$options['family']??'production','intelligence'=>$mode,'decision_at_ms'=>$at,'candles'=>$window]);
                        return ['action'=>$answer['signal']['action'],'signal'=>$answer['signal'],'provenance'=>$answer['provenance']];
                    };
                    $result=['candles'=>Replay::candles($store,$run,$symbol,$history['candles'],$infer,$options['path']??'low_first',isset($options['warmup'])?self::integer($options,'warmup',1,50000):500),
                        'report'=>Report::build($store->run($run))];break;
                default: throw new RuntimeException('Unknown paper command. Use paper-help.');
            }
            self::output($result,$options['output']??null);return 0;
        } catch(\Throwable $e) { fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES).PHP_EOL);return 1; }
    }
    private static function integer(array $o,string $key,int $min,int $max): int
    {
        $value=$o[$key]??'';if(!ctype_digit($value) || strlen($value)>16 || (int)$value<$min || (int)$value>$max) { throw new RuntimeException('Invalid '.$key); }return (int)$value;
    }
    public static function periodMs(string $period): int
    {
        if(!preg_match('/^([1-9][0-9]*)([mhdw])$/D',$period,$m)) { throw new RuntimeException('A fixed candle period is required.'); }
        return (int)$m[1]*match($m[2]) { 'm'=>60000,'h'=>3600000,'d'=>86400000,'w'=>604800000 };
    }
}
