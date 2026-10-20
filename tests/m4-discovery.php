<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';

use Trademinator\Client\M1\Config;
use Trademinator\Client\M1\Database;
use Trademinator\Client\M1\Ledger;
use Trademinator\Client\M3\AccountSetup;
use Trademinator\Client\M3\Settings as ExchangeSettings;
use Trademinator\Client\M4\AutomaticSetup;
use Trademinator\Client\M4\IntelligenceSetup;
use Trademinator\Client\M4\MarketRules;
use Trademinator\Client\M4\Runtime;
use Trademinator\Client\M4\Server;
use Trademinator\Client\M4\Store;
use Trademinator\Client\M4\Worker;

function uuid(int $n): string { return '00000000-0000-4000-8000-'.str_pad((string)$n,12,'0',STR_PAD_LEFT); }
function catalog(): array {
    return ['api_version'=>1,'markets'=>[
        ['subscription_id'=>uuid(1),'active'=>true,'exchange'=>['class'=>'bitso','name'=>'Bitso'],'symbol'=>'ATOM/USD','period'=>'15m'],
        ['subscription_id'=>uuid(2),'active'=>true,'exchange'=>['class'=>'bitso','name'=>'Bitso'],'symbol'=>'XRP/USD','period'=>'15m'],
        ['subscription_id'=>uuid(3),'active'=>true,'exchange'=>['class'=>'kraken','name'=>'Kraken'],'symbol'=>'BTC/CAD','period'=>'15m'],
        ['subscription_id'=>uuid(4),'active'=>false,'exchange'=>['class'=>'bitso','name'=>'Bitso'],'symbol'=>'BTC/USD','period'=>'15m'],
    ]];
}
function answer(string $sub,string $resource,?array $body=null,array $query=[]): array {
    $modeFile=getenv('TM4_DISCOVERY_MODE_FILE');
    $mode=$modeFile && is_file($modeFile)?trim(file_get_contents($modeFile)):'';
    if($mode==='unavailable' && $resource==='markets') { throw new RuntimeException('Fixture Server is temporarily unavailable.'); }
    if($resource==='markets') { return catalog(); }
    if($resource==='risk-factor') { return ['api_version'=>1,'risk_factor'=>'0.37','source'=>'questionnaire','algorithm_version'=>'questionnaire-risk-v1']; }
    $row=array_values(array_filter(catalog()['markets'],fn($m)=>$m['subscription_id']===$sub))[0];
    if($resource==='market') { return ['api_version'=>1,'market'=>[...$row,'settings'=>['paper_initial_quote'=>$sub===uuid(2)?'20000':'10000']]]; }
    if($resource==='replay/models') { return ['api_version'=>1,'symbol'=>$row['symbol'],'models'=>[
        ['model_id'=>uuid(80),'family'=>'production','current'=>false],
        ['model_id'=>uuid(90),'family'=>'shadow','current'=>true],
        ['model_id'=>uuid(100+(int)substr($sub,-2)),'family'=>'production','current'=>true],
    ]]; }
    if($resource==='replay/infer') { return ['signal'=>['action'=>'buy'],'provenance'=>['model_id'=>$body['model_id'],'fixture'=>true]]; }
    throw new RuntimeException('Unexpected request: '.$resource);
}
function feed(string $exchange): object {
    return new class {
        public function market(string $symbol): array { return ['spot'=>true,'active'=>true,'precision'=>['price'=>0.01,'amount'=>0.001],
            'limits'=>['amount'=>['min'=>0.01,'max'=>10],'cost'=>['min'=>1]],'maker'=>0.001,'taker'=>0.002,'feeSide'=>'get']; }
        public function rules(string $symbol): array { return MarketRules::from($this->market($symbol),\ccxt\TICK_SIZE); }
        public function book(string $symbol): array { return ['timestamp'=>(int)floor(microtime(true)*1000),'transport'=>'fixture','bids'=>[['9','10']],'asks'=>[['10','10']]]; }
        public function trades(string $symbol,?int $since): array { return []; }
        public function candles(string $symbol,string $period,int $limit): array {
            $t=intdiv((int)floor(microtime(true)*1000),900000)*900000;
            return [[$t-2700000,'10','10','10','10','10'],[$t-1800000,'10','10','10','10','10'],[$t-900000,'10','10','10','10','10']];
        }
        public function close(): void {}
    };
}
if(($argv[1]??'')==='--worker') { exit(Worker::run($argv[2],'feed',Closure::fromCallable('answer'))); }

$checks=0;
function check(bool $pass,string $label): void { global $checks;if(!$pass) { throw new RuntimeException($label); }$checks++;echo 'PASS '.$label.PHP_EOL; }
function rejects(callable $f,string $message=''): bool {try{$f();return false;}catch(Throwable $e){return $message===''||str_contains($e->getMessage(),$message);}}
$dir=sys_get_temp_dir().'/tm4-discovery-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);
$before=getenv('TM4_DISCOVERY_KEY');$beforeMode=getenv('TM4_DISCOVERY_MODE_FILE');putenv('TM4_DISCOVERY_KEY=only-fixture-key');$runtime=null;
try {
    $template=json_decode(file_get_contents(dirname(__DIR__).'/config.example.json'),true);
    check(!str_contains(json_encode($template),'UUID') && !isset($template['paper_trading']['markets']), 'Template has no UUIDs, pairs, periods, funds or trading parameters to fill');
    $minimal=new Config(['server_api_key_env'=>'TM4_DISCOVERY_KEY','paper_trading'=>['enabled'=>true],'data_dir'=>$dir.'/paper-state']);
    check($minimal->serverUrl==='https://server.trademinator.com','Credentials-only configuration selects the standard Server origin');
    check((new Config(['server_api_key_env'=>'TM4_DISCOVERY_KEY','server_url'=>'https://custom.invalid']))->serverUrl==='https://custom.invalid','Custom Server origins remain supported');
    $settings=ExchangeSettings::validate($template['exchange']);
    check(!isset($settings['accounts'][0]['account_id']) && $settings['accounts'][0]['history_since_ms']===0,'Exchange keys need no local UUID or invented history boundary');
    $db=new Database($dir.'/client.sqlite');
    $configured=AccountSetup::resolve($db,$settings,$dir);$account=$configured['accounts'][0]['account_id'];
    check($db->pdo()->query('SELECT COUNT(*) FROM exchange_accounts')->fetchColumn()===1,'First startup registers the local exchange account');
    check(AccountSetup::resolve($db,$settings,$dir)['accounts'][0]['account_id']===$account,'Subsequent startups reuse the same account binding');
    check(!str_contains(file_get_contents($dir.'/exchange-account-bindings.json'),'only-fixture-key'),'Account bindings store no credential values');
    $legacy=new Database($dir.'/legacy/client.sqlite');$id=(new Ledger($legacy))->createAccount('bitso','Existing wallet',openingBalances:['USD'=>'123']);
    $legacySettings=AccountSetup::resolve($legacy,$settings,$dir.'/legacy');
    check($legacySettings['accounts'][0]['account_id']===$id && $legacy->pdo()->query('SELECT COUNT(*) FROM exchange_accounts')->fetchColumn()===1,'Migration reuses the sole existing exchange ledger instead of duplicating it');
    check(json_decode($legacy->pdo()->query('SELECT wallet_balances_json FROM exchange_accounts')->fetchColumn(),true)['USD']==='123','Automatic registration preserves existing financial balances');

    $calls=[];$server=new Server($minimal,static function($sub,$resource,$body,$query)use(&$calls){$calls[]=$resource;return answer($sub,$resource,$body,$query);});
    $result=AutomaticSetup::discover($minimal,$server,'feed');
    check(count($result['plans'])===2 && $result['warnings']===[],'Active Server subscriptions produce one isolated paper account per exchange');
    $bitso=$result['plans'][0];$kraken=$result['plans'][1];
    check(count($bitso['markets'])===2 && !isset($bitso['markets']['BTC/USD']),'Only active subscribed markets enter automatic paper trading');
    check(bccomp($bitso['balances']['USD'],'20000',24)===0,'Pairs sharing a quote currency share one Server-funded paper budget');
    check($bitso['reporting_currency']==='USD' && $kraken['reporting_currency']==='CAD','Reporting currencies derive from the subscribed quote currencies');
    $market=$bitso['markets']['ATOM/USD'];
    check($market['intelligence']['subscription']===uuid(1) && $market['intelligence']['model_id']===uuid(101),'Subscription and current production model UUIDs are selected automatically');
    check($market['period_ms']===900000 && $market['evaluation_ms']===300000,'Server-selected period determines the default three evaluations per candle');
    check($market['risk_factor']==='0.37','Server questionnaire supplies the risk factor');
    check(bccomp($market['tick_size'],'0.01',24)===0 && bccomp($market['amount_step'],'0.001',24)===0,'Exchange metadata supplies price and quantity precision');
    check(bccomp($market['maker_fee'],'0.001',24)===0 && $market['fee_asset']==='get','Published fees and side-dependent fee currency are preserved');
    check($bitso['policy']==='two_order' && $bitso['opening_lots']===[],'Default setup preserves Two Order Rule and does not invent starting crypto cost basis');
    $calls=[];$again=AutomaticSetup::discover($minimal,$server,static fn()=>throw new RuntimeException('Should reuse saved rules'));
    check($again['plans']===$result['plans'] && $calls===['markets'] && $again['created']===[],'Restart reuses the saved configuration and models without resetting funds or refreshing market rules');
    $store=new Store($minimal->dataDir.'/paper');
    check($store->automaticIds()===array_column($result['plans'],'run_id'),'Reports can find automatic run IDs without user configuration');

    $custom=$store->run($bitso['run_id'])['config'];unset($custom['markets']['ATOM/USD']['intelligence']['subscription'],$custom['markets']['ATOM/USD']['intelligence']['model_id']);
    $resolved=IntelligenceSetup::resolve($custom,$server);
    check($resolved['markets']['ATOM/USD']['intelligence']['subscription']===uuid(1),'Custom configurations can also omit UUIDs');
    $bad=new Server($minimal,static function($sub,$resource,$body,$query){$a=answer($sub,$resource,$body,$query);if($resource==='replay/models'){$a['models']=[];}return $a;});
    check(rejects(fn()=>IntelligenceSetup::resolve($custom,$bad),'production model'),'Missing current models cause a clear wait condition rather than selection of an unrelated model');
    $duplicate=new Server($minimal,static function($sub,$resource,$body,$query){$a=answer($sub,$resource,$body,$query);if($resource==='markets'){$copy=$a['markets'][0];$copy['subscription_id']=uuid(9);$a['markets'][]=$copy;}return $a;});
    check(rejects(fn()=>IntelligenceSetup::resolve($custom,$duplicate),'found 2'),'Ambiguous subscriptions are not guessed');
    $wrong=new Server($minimal,static function($sub,$resource,$body,$query){$a=answer($sub,$resource,$body,$query);if($resource==='replay/models'){$a['symbol']='ATOM/USDT';}return $a;});
    check(rejects(fn()=>IntelligenceSetup::resolve($custom,$wrong),'discovery response'),'Exact pair validation rejects USD versus USDT mismatches');
    $store->create('custom-auto',$custom);$pinned=$store->resolve('custom-auto',fn($c)=>IntelligenceSetup::resolve($c,$server));
    check($store->resolve('custom-auto',fn()=>throw new RuntimeException('No rediscovery'))===$pinned,'Resolved UUIDs remain pinned for consistent run restarts');
    $rules=feed('bitso')->market('ATOM/USD');$rules['precision']=['price'=>2,'amount'=>3];
    check(MarketRules::from($rules,\ccxt\DECIMAL_PLACES)['tick_size']==='0.01','Decimal-place precision converts to a fixed step');
    check(rejects(fn()=>MarketRules::from($rules,\ccxt\SIGNIFICANT_DIGITS),'fixed paper'),'Unsupported variable precision is not fabricated');
    $feeConfig=$store->run($bitso['run_id'])['config'];$feeConfig['markets']=['ATOM/USD'=>$market];$feeConfig['submission_delay_ms']=0;
    $engine=new \Trademinator\Client\M4\Engine($feeConfig);$sequence=0;
    $apply=static function(array $event)use($engine,&$sequence):void{$event['id']='fee-'.++$sequence;$event['symbol']='ATOM/USD';$engine->apply($event);};
    $apply(['type'=>'book','time_ms'=>1000,'bids'=>[['9','10']],'asks'=>[['10','10']]]);
    $apply(['type'=>'evaluate','time_ms'=>1000,'action'=>'buy','q3bar'=>'1','expires_at_ms'=>2000]);
    $apply(['type'=>'book','time_ms'=>2000,'bids'=>[['20','10']],'asks'=>[['21','10']]]);
    $apply(['type'=>'evaluate','time_ms'=>2000,'action'=>'sell','expires_at_ms'=>3000]);
    check(count($engine->state['fills'])===2 && $engine->state['fills'][1]['fee']['currency']==='USD','Received-asset fees switch correctly from base on BUY to quote on SELL');
    check(bccomp($engine->state['fills'][1]['realized_profit'],'9.92008',20)===0,'Side-dependent fee accounting preserves independently calculated realized profit');
    $limitedBuy=static function(array $limits)use($feeConfig):array {
        $c=$feeConfig;$c['markets']['ATOM/USD']=array_replace($c['markets']['ATOM/USD'],$limits);
        $e=new \Trademinator\Client\M4\Engine($c);
        $e->apply(['id'=>'limits-book','symbol'=>'ATOM/USD','type'=>'book','time_ms'=>1000,'bids'=>[['9','10']],'asks'=>[['10','10']]]);
        $e->apply(['id'=>'limits-buy','symbol'=>'ATOM/USD','type'=>'evaluate','time_ms'=>1000,'action'=>'buy','q3bar'=>'5','expires_at_ms'=>2000]);
        return $e->state;
    };
    $limited=$limitedBuy(['maximum_amount'=>'0.7','maximum_cost'=>'5']);
    check(bccomp($limited['orders']['order-1']['quantity'],'0.5',24)===0,'Published maximum order cost caps paper order size');
    $limited=$limitedBuy(['maximum_amount'=>'0.3','maximum_cost'=>'5']);
    check(bccomp($limited['orders']['order-1']['quantity'],'0.3',24)===0,'Published maximum amount applies when it is the tighter limit');
    check($limitedBuy(['maximum_price'=>'9'])['orders']===[],'Out-of-range prices cannot create a paper order');

    $events=[];$runtime=new Runtime($minimal,static function($level,$name,$context,$message)use(&$events){$events[]=$name;},[PHP_BINARY,__FILE__,'--worker']);
    $deadline=microtime(true)+6;
    do {
        $runtime->tick();$status=$runtime->status();$ready=count($status['runs']??[])===2 && count(array_filter($status['runs'],fn($s)=>$s['state']==='running'))===2;
        if($ready && count($store->run($bitso['run_id'])['state']['fills'])>=2){break;}usleep(20000);
    }while(microtime(true)<$deadline);
    check($ready,'Ordinary daemon supervisor discovers and starts both exchange paper workers');
    $state=$store->run($bitso['run_id'])['state'];
    check(count($state['fills'])===2,'Both discovered pairs reach actual paper fills with fixture data');
    check($state['fills'][0]['fee']['currency']==='ATOM','Automatic received-asset fee rule charges BUY fees in the acquired asset');
    check(in_array('paper_evaluated',$events,true),'Automatically discovered paper activity reaches daemon output');
    $modeFile=$dir.'/discovery-mode';putenv('TM4_DISCOVERY_MODE_FILE='.$modeFile);file_put_contents($modeFile,'unavailable');
    $nextDiscovery=new ReflectionProperty(Runtime::class,'nextDiscovery');$nextDiscovery->setValue($runtime,0.0);
    $deadline=microtime(true)+6;
    do {$runtime->tick();$status=$runtime->status();if(isset($status['error'])){break;}usleep(20000);}while(microtime(true)<$deadline);
    check(str_contains($status['error']??'','temporarily unavailable') && count(array_filter($status['runs'],fn($s)=>$s['state']==='running'))===2,'A discovery outage preserves existing paper workers and reports the failure');
    check($nextDiscovery->getValue($runtime)>microtime(true),'Failed discovery schedules an automatic retry');
    file_put_contents($modeFile,'');$nextDiscovery->setValue($runtime,0.0);$deadline=microtime(true)+6;
    do {$runtime->tick();$status=$runtime->status();if(($status['state']??null)==='running' && $nextDiscovery->getValue($runtime)>microtime(true)){break;}usleep(20000);}while(microtime(true)<$deadline);
    check($status['state']==='running' && !isset($status['error']) && count($status['runs'])===2,'Discovery recovers automatically without retaining a stale failure');
    check(count($store->run($bitso['run_id'])['state']['fills'])===2,'Discovery refresh retains the same paper account without duplicate fills or a balance reset');
    $runtime->close();$saved=$store->run($bitso['run_id']);usleep(100000);
    check($store->run($bitso['run_id'])===$saved,'Daemon shutdown stops all discovered writers');
    $file=$dir.'/config.json';file_put_contents($file,json_encode($minimal->toArray()));
    $process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/client.php','paper-report','--config='.$file],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($process)===0 && count(json_decode($out,true)['runs']??[])===2,'paper-report uses the same credentials-only config without a run UUID');
    echo json_encode(['suite'=>'m4-automatic-discovery','checks'=>$checks,'status'=>'passed','network_requests'=>0,'exchange_orders_submitted'=>0],JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    $runtime?->close();if($before===false){putenv('TM4_DISCOVERY_KEY');}else{putenv('TM4_DISCOVERY_KEY='.$before);}
    if($beforeMode===false){putenv('TM4_DISCOVERY_MODE_FILE');}else{putenv('TM4_DISCOVERY_MODE_FILE='.$beforeMode);}
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir()&&!$file->isLink()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}rmdir($dir);
}
