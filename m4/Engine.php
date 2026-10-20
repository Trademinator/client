<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;
use Trademinator\Client\M4\Decimal as D;

/** Deterministic spot exchange simulator. Has no exchange transport or credential capability. */
final class Engine
{
    public const VERSION='m4-paper-v1';
    public array $state;
    public function __construct(public readonly array $config, ?array $state=null)
    {
        $this->state=$state??['version'=>self::VERSION,'sequence'=>0,'time_ms'=>0,'balances'=>$config['balances'],
            'orders'=>[],'fills'=>[],'lots'=>[],'books'=>[],'candles'=>[],'campaigns'=>[],'pending'=>[],
            'live_observations'=>[],'gaps'=>[],'paused'=>[],'decisions'=>[],'fx'=>[],'assumptions'=>[]];
        if($this->state['version']!==self::VERSION) { throw new RuntimeException('Paper engine version mismatch.'); }
        if($state===null) {
            $totals=[];
            foreach($config['opening_lots'] as $i=>$lot) {
                $base=explode('/',$lot['symbol'])[0];$totals[$base]=D::add($totals[$base]??'0',$lot['quantity']);
                $this->state['lots'][]=['id'=>'opening-'.$i,'symbol'=>$lot['symbol'],'remaining'=>$lot['quantity'],'unit_cost'=>$lot['unit_cost']];
            }
            foreach($totals as $base=>$q) { if(D::cmp($q,$config['balances'][$base]??'0')>0) { throw new RuntimeException('Opening lots exceed starting balance.'); } }
        }
    }
    public static function open(array $o): bool { return in_array($o['status'],['pending','open','cancelling'],true); }
    public function apply(array $e): void
    {
        $t=$e['time_ms']??null;
        if(!is_int($t) || $t<0 || $t<$this->state['time_ms']) { throw new RuntimeException('Events must be chronological integer milliseconds.'); }
        $type=$e['type']??'';$symbol=$e['symbol']??'';
        if(!in_array($type,['book','trade','evaluate','candle','gap','resume','fx','clock'],true)) { throw new RuntimeException('Unknown paper event.'); }
        if(!in_array($type,['fx','clock'],true) && !isset($this->config['markets'][$symbol])) { throw new RuntimeException('Unknown paper market.'); }
        $this->state['time_ms']=$t;$this->state['sequence']++;
        $this->settle($t);
        if($type==='fx') {
            if(!is_string($e['currency']??null) || !isset($e['observed_at_ms']) || !is_int($e['observed_at_ms']) || $e['observed_at_ms']>$t || $e['observed_at_ms']<0) { throw new RuntimeException('Invalid FX observation.'); }
            $this->state['fx'][$e['currency']]=['rate'=>D::value($e['rate']??null,true),'observed_at_ms'=>$e['observed_at_ms']];
        } elseif($type==='gap') {
            $from=$e['from_ms']??$t;
            if(!is_int($from) || $from<0 || $from>$t) { throw new RuntimeException('Invalid gap interval.'); }
            $this->state['gaps'][]=['symbol'=>$symbol,'from_ms'=>$from,'to_ms'=>$t,'reason'=>$e['reason']??'unrecoverable','verified'=>false];
            $this->state['paused'][$symbol]=true;unset($this->state['books'][$symbol],$this->state['pending'][$symbol]);
            foreach($this->state['orders'] as &$o) { if($o['symbol']===$symbol && self::open($o)) { $o['queue']=null; } } unset($o);
        } elseif($type==='resume') {
            $this->state['paused'][$symbol]=false;
        } elseif($type==='book') {
            $bids=$this->levels($e['bids']??[],true);$asks=$this->levels($e['asks']??[],false);
            if(!$bids || !$asks || D::cmp($bids[0][0],$asks[0][0])>=0) { throw new RuntimeException('Book must be nonempty and uncrossed.'); }
            $observed=$e['observed_at_ms']??$t;
            if(!is_int($observed) || $observed<0 || $observed>$t) { throw new RuntimeException('Invalid book observation time.'); }
            $hash=hash('sha256',json_encode([$bids,$asks],JSON_THROW_ON_ERROR));
            $old=$this->state['books'][$symbol]??null;
            if($old===null || $observed>$old['time_ms']) {
                // Identical REST snapshots do not replenish previously consumed depth.
                $this->state['books'][$symbol]=['time_ms'=>$observed,'hash'=>$hash,'bids'=>$old!==null&&$old['hash']===$hash?$old['bids']:$bids,'asks'=>$old!==null&&$old['hash']===$hash?$old['asks']:$asks];
            }
            if(isset($e['approximation'])) { $this->state['assumptions']['ohlcv']=$e['approximation']; }
            if(in_array($e['transport']??null,['websocket','rest'],true)) {
                $live=$this->state['live_observations'][$symbol]??['first_ms'=>$t,'last_ms'=>$t,'books'=>0];$live['last_ms']=$t;$live['books']++;$this->state['live_observations'][$symbol]=$live;
            }
            $this->state['assumptions']['book']='Visible depth; unknown replenishment/hidden orders and queue changes are not inferred.';
        } elseif($type==='candle') {
            $c=$e['ohlcv']??null;$m=$this->config['markets'][$symbol];
            if(!is_array($c) || count($c)!==6 || !is_int($c[0]) || $c[0]+$m['period_ms']>$t) { throw new RuntimeException('Candle must be closed at the event time.'); }
            for($i=1;$i<6;$i++) { $c[$i]=D::value($c[$i],$i<5); }
            if(D::cmp($c[3],D::min($c[1],$c[4]))>0 || D::cmp($c[2],D::max($c[1],$c[4]))<0) { throw new RuntimeException('Invalid OHLC bounds.'); }
            $rows=$this->state['candles'][$symbol]??[];$last=$rows?end($rows):null;
            if($last && $last[0]>=$c[0]) { throw new RuntimeException('Duplicate or unordered candle.'); }
            $rows[]=$c;$this->state['candles'][$symbol]=array_slice($rows,-3);
        } elseif($type==='evaluate') {
            $action=strtolower($e['action']??'hold');if($action==='hodl') { $action='hold'; }
            if(!in_array($action,['buy','sell','hold'],true)) { throw new RuntimeException('Invalid intelligence action.'); }
            $expires=$e['expires_at_ms']??($t+$this->config['markets'][$symbol]['evaluation_ms']);
            if(!is_int($expires) || $expires<=$t) { throw new RuntimeException('Expired intelligence.'); }
            $this->state['pending'][$symbol]=[...$e,'action'=>$action,'expires_at_ms'=>$expires];
            foreach($this->state['orders'] as &$o) {
                if($o['symbol']===$symbol && self::open($o) && $o['status']!=='cancelling') {
                    $o['status']='cancelling';$o['cancel_at_ms']=$t+$this->config['cancellation_delay_ms'];
                }
            } unset($o);
            $this->settle($t);
        }
        foreach($this->state['pending'] as $s=>$signal) {
            if($signal['expires_at_ms']<=$t) { unset($this->state['pending'][$s]);continue; }
            if(!$this->hasOpen($s) && $this->fresh($s,$t)) { $this->plan($s,$signal,$t);unset($this->state['pending'][$s]); }
        }
        $this->settle($t);
        if($type==='trade') { $this->trade($symbol,$e,$t); }
        elseif(in_array($type,['book','evaluate','clock'],true)) { $this->cross($t); }
        foreach($this->state['balances'] as $amount) { if(D::cmp($amount,'0')<0) { throw new RuntimeException('Paper balance invariant violated.'); } }
    }
    private function levels(array $rows, bool $descending): array
    {
        if(count($rows)>1000) { throw new RuntimeException('Too many book levels.'); }$out=[];
        foreach($rows as $row) {
            if(!is_array($row) || count($row)<2) { throw new RuntimeException('Invalid book level.'); }
            $p=D::value($row[0],true);$q=D::value($row[1]);$out[$p]=D::add($out[$p]??'0',$q);
        }
        $result=[];foreach($out as $p=>$q) { $result[]=[(string)$p,$q]; }
        usort($result,static fn($a,$b)=>D::cmp($a[0],$b[0])*($descending?-1:1));return $result;
    }
    private function settle(int $t): void
    {
        foreach($this->state['orders'] as &$o) {
            if(!self::open($o)) { continue; }
            if($o['cancel_at_ms']===null && $o['evaluation_at_ms']<=$t) { $o['status']='cancelling';$o['cancel_at_ms']=$o['evaluation_at_ms']+$this->config['cancellation_delay_ms']; }
            if($o['cancel_at_ms']!==null && $o['cancel_at_ms']<=$t) { $o['status']='cancelled';$o['closed_at_ms']=$o['cancel_at_ms'];continue; }
            if($o['status']==='pending' && $o['accept_at_ms']<=$t) { $o['status']='open'; }
        } unset($o);
    }
    private function hasOpen(string $s): bool
    {
        foreach($this->state['orders'] as $o) { if($o['symbol']===$s && self::open($o)) { return true; } }return false;
    }
    private function fresh(string $s,int $t): bool
    {
        return !($this->state['paused'][$s]??false) && isset($this->state['books'][$s]) && $t-$this->state['books'][$s]['time_ms']<=$this->config['stale_ms'];
    }
    public function free(string $currency): string
    {
        $free=$this->state['balances'][$currency]??'0';
        foreach($this->state['orders'] as $o) {
            if(self::open($o) && $o['reserve_currency']===$currency) { $free=D::sub($free,D::mul($o['remaining'],$o['reserve_unit'])); }
        }return D::max('0',$free);
    }
    private function plan(string $s,array $e,int $t): void
    {
        $side=$e['action'];$m=$this->config['markets'][$s];
        $decision=['symbol'=>$s,'time_ms'=>$t,'action'=>$side,'reason'=>'hold','orders'=>[],'intelligence'=>$e['intelligence']??null];
        if($side==='hold') { $this->state['decisions'][]=$decision;return; }
        $book=$this->state['books'][$s];$p=$side==='buy'?$book['asks'][0][0]:$book['bids'][0][0];
        $bookPrice=$p;$p=D::step($p,$m['tick_size'],$side==='buy');
        $main=$this->submit($s,$side,$p,'main',null,$e,$t);
        if($main===null) { $decision['reason']='funding_profit_or_minimum_gate';$this->state['decisions'][]=$decision;return; }
        $decision['orders'][]=$main;$decision['reason']='submitted';
        if($this->config['policy']==='two_order') {
            $candles=$this->state['candles'][$s]??[];$speed='0';
            if(count($candles)>=2) {
                $last=$candles[count($candles)-1];$prev=$candles[count($candles)-2];
                $speed=D::div(D::sub($last[4],$prev[4]),D::div((string)($last[0]-$prev[0]),'1000'));
            }
            $p2=D::add($bookPrice,D::mul($speed,D::div((string)($e['expires_at_ms']-$t),'1000')));
            if(D::cmp($p2,'0')>0) {
                $p2=D::step($p2,$m['tick_size'],$side==='sell');
                if(($side==='buy' && D::cmp($p2,$p)<0) || ($side==='sell' && D::cmp($p2,$p)>0)) {
                    $backup=$this->submit($s,$side,$p2,'backup',$main,$e,$t);
                    if($backup!==null) { $decision['orders'][]=$backup; }
                }
            }
        }
        $this->state['decisions'][]=$decision;
    }
    private function submit(string $s,string $side,string $price,string $role,?string $parent,array $e,int $t): ?string
    {
        $m=$this->config['markets'][$s];[$base,$quote]=explode('/',$s);$fee=$m['taker_fee'];$feeAsset=self::feeAsset($m['fee_asset'],$side);
        if(isset($m['minimum_price']) && D::cmp($price,$m['minimum_price'])<0 || isset($m['maximum_price']) && D::cmp($price,$m['maximum_price'])>0) { return null; }
        $campaign=$this->state['campaigns'][$s]??null;
        if($parent!==null) { $campaign=['side'=>$side,'quantity'=>$this->state['orders'][$parent]['quantity'],'value'=>D::mul($this->state['orders'][$parent]['quantity'],$this->state['orders'][$parent]['price'])]; }
        if($campaign && $campaign['side']===$side && D::cmp($campaign['quantity'],'0')>0) {
            $average=D::div($campaign['value'],$campaign['quantity']);
            if(($side==='buy' && D::cmp($price,$average)>=0) || ($side==='sell' && D::cmp($price,$average)<=0)) { return null; }
        }
        $reserveUnit=$side==='buy'?D::mul($price,$feeAsset==='quote'?D::add('1',$fee):'1'):($feeAsset==='base'?D::add('1',$fee):'1');
        $currency=$side==='buy'?$quote:$base;$capacity=D::div($this->free($currency),$reserveUnit);$alloc=[];
        if($side==='buy') {
            $markets=count(array_filter(array_keys($this->config['markets']),fn($symbol)=>explode('/',$symbol)[1]===$quote));
            $risk=D::div(D::mul(D::div($this->free($quote),(string)$markets),$m['risk_factor']),$reserveUnit);
            $rows=$this->state['candles'][$s]??[];
            if(isset($e['q3bar'])) { $q3=D::value($e['q3bar']); }
            elseif(count($rows)===3) {
                $q3='0';foreach($rows as $row) { $q3=D::add($q3,$row[5]); }$q3=D::mul($q3,$m['volume_participation']);
                $this->state['assumptions']['q3bar']='Persistence forecast: next three bars repeat the latest three volumes, multiplied by configured participation.';
            } else { return null; }
            $quantity=D::min($q3,$risk,$capacity);
        } else {
            $netUnit=D::mul($price,$feeAsset==='quote'?D::sub('1',$fee):'1');$eligible='0';
            foreach($this->state['lots'] as $i=>$lot) {
                if($lot['symbol']!==$s || D::cmp($netUnit,D::mul(D::mul($lot['unit_cost'],$reserveUnit),D::add('1',$m['minimum_profit'])))<=0) { continue; }
                $available=$lot['remaining'];
                foreach($this->state['orders'] as $o) { if(self::open($o)) { $available=D::sub($available,$o['allocations'][$i]??'0'); } }
                if(D::cmp($available,'0')>0) { $alloc[$i]=$available;$eligible=D::add($eligible,$available); }
            }
            $quantity=D::min(D::div($eligible,$reserveUnit),$capacity);
        }
        if(isset($m['maximum_amount'])) { $quantity=D::min($quantity,$m['maximum_amount']); }
        if(isset($m['maximum_cost'])) { $quantity=D::min($quantity,D::div($m['maximum_cost'],$price)); }
        $minimum=D::step(D::max($m['minimum_amount'],D::div($m['minimum_cost'],$price)),$m['amount_step'],true);
        // Exchange minimum is allowed only while all capacity/risk/liquidity gates still hold.
        $quantity=D::step($quantity,$m['amount_step']);
        if(D::cmp($quantity,$minimum)<0) { return null; }
        if($side==='sell') {
            $needed=D::mul($quantity,$reserveUnit);
            foreach($alloc as $i=>$available) { $take=D::min($available,$needed);$alloc[$i]=$take;$needed=D::sub($needed,$take); }
        }
        $id='order-'.(count($this->state['orders'])+1);
        $this->state['orders'][$id]=['id'=>$id,'symbol'=>$s,'side'=>$side,'role'=>$role,'parent'=>$parent,'price'=>$price,'quantity'=>$quantity,'remaining'=>$quantity,
            'evaluation_at_ms'=>$e['expires_at_ms'],'filled'=>'0','status'=>'pending','created_at_ms'=>$t,'accept_at_ms'=>$t+$this->config['submission_delay_ms'],'cancel_at_ms'=>null,'closed_at_ms'=>null,
            'reserve_currency'=>$currency,'reserve_unit'=>$reserveUnit,'allocations'=>$alloc,'queue'=>null,'rested'=>false];
        return $id;
    }
    private function ready(array $o,int $t): bool
    {
        return self::open($o) && $o['accept_at_ms']<=$t && $this->fresh($o['symbol'],$t)
            && ($o['parent']===null || ($this->state['orders'][$o['parent']]['status']??null)==='filled');
    }
    private function cross(int $t): void
    {
        foreach(array_keys($this->state['orders']) as $id) {
            $o=$this->state['orders'][$id];
            if(self::open($o) && $o['accept_at_ms']<=$t && $this->fresh($o['symbol'],$t) && $o['queue']===null) {
                $queue='0';foreach($this->state['books'][$o['symbol']][$o['side']==='buy'?'bids':'asks'] as $level) { if(D::cmp($level[0],$o['price'])===0) { $queue=D::add($queue,$level[1]); } }
                $this->state['orders'][$id]['queue']=$queue;$this->state['orders'][$id]['rested']=true;
            }
            if(!$this->ready($o,$t)) { continue; }
            $side=$o['side']==='buy'?'asks':'bids';$symbol=$o['symbol'];
            foreach($this->state['books'][$symbol][$side] as $i=>$level) {
                $o=$this->state['orders'][$id];
                if(D::cmp($o['remaining'],'0')<=0 || ($o['side']==='buy'?D::cmp($level[0],$o['price'])>0:D::cmp($level[0],$o['price'])<0)) { break; }
                $q=D::min($o['remaining'],$level[1]);
                if(D::cmp($q,'0')<=0) { continue; }
                $this->fill($id,$q,$level[0],'taker',$t);
                $this->state['books'][$symbol][$side][$i][1]=D::sub($level[1],$q);
            }
            if(self::open($this->state['orders'][$id]) && $this->state['orders'][$id]['queue']===null) {
                $queue='0';foreach($this->state['books'][$symbol][$o['side']==='buy'?'bids':'asks'] as $level) { if(D::cmp($level[0],$o['price'])===0) { $queue=D::add($queue,$level[1]); } }
                $this->state['orders'][$id]['queue']=$queue;$this->state['orders'][$id]['rested']=true;
            }
        }
    }
    private function trade(string $s,array $e,int $t): void
    {
        if(isset($e['source_time_ms']) && (!is_int($e['source_time_ms']) || $e['source_time_ms']<0 || $e['source_time_ms']>$t)) { throw new RuntimeException('Invalid trade source time.'); }
        $price=D::value($e['price']??null,true);$remaining=D::value($e['quantity']??null);$aggressor=$e['side']??null;
        if(!in_array($aggressor,['buy','sell'],true)) { throw new RuntimeException('Trade aggressor side required.'); }
        foreach(array_keys($this->state['orders']) as $id) {
            $o=$this->state['orders'][$id];
            if($o['symbol']!==$s || (isset($e['source_time_ms']) && $e['source_time_ms']<$o['accept_at_ms']) || $o['side']===$aggressor || !$this->ready($o,$t) || !$o['rested'] || $o['queue']===null) { continue; }
            if(($o['side']==='buy' && D::cmp($price,$o['price'])>0) || ($o['side']==='sell' && D::cmp($price,$o['price'])<0)) { continue; }
            $ahead=D::min($remaining,$o['queue']);$remaining=D::sub($remaining,$ahead);$this->state['orders'][$id]['queue']=D::sub($o['queue'],$ahead);
            $q=D::min($remaining,$o['remaining']);
            if(D::cmp($q,'0')>0) { $this->fill($id,$q,$o['price'],($e['approximate']??false)?'taker':'maker',$t);$remaining=D::sub($remaining,$q); }
        }
    }
    private static function feeAsset(string $rule,string $side): string
    {
        return match($rule) { 'get'=>$side==='buy'?'base':'quote', 'give'=>$side==='buy'?'quote':'base', default=>$rule };
    }
    private function balance(string $c,string $delta): void { $this->state['balances'][$c]=D::add($this->state['balances'][$c]??'0',$delta); }
    private function fill(string $id,string $q,string $price,string $liquidity,int $t): void
    {
        $o=&$this->state['orders'][$id];$m=$this->config['markets'][$o['symbol']];[$base,$quote]=explode('/',$o['symbol']);$feeAsset=self::feeAsset($m['fee_asset'],$o['side']);
        $cost=D::mul($q,$price);$rate=$m[$liquidity.'_fee'];$fee=D::mul($feeAsset==='base'?$q:$cost,$rate);
        $feeCurrency=$feeAsset==='base'?$base:$quote;$matched=[];$profit=null;
        if($o['side']==='buy') {
            $netQ=$feeAsset==='base'?D::sub($q,$fee):$q;$paid=$feeAsset==='quote'?D::add($cost,$fee):$cost;
            $this->balance($base,$netQ);$this->balance($quote,D::sub('0',$paid));
            $this->state['lots'][]=['id'=>'fill-'.(count($this->state['fills'])+1),'symbol'=>$o['symbol'],'remaining'=>$netQ,'unit_cost'=>D::div($paid,$netQ)];
        } else {
            $spent=$feeAsset==='base'?D::add($q,$fee):$q;$proceeds=$feeAsset==='quote'?D::sub($cost,$fee):$cost;
            $needed=$spent;$basis='0';
            foreach($o['allocations'] as $i=>$available) {
                $take=D::min($available,$needed);if(D::cmp($take,'0')<=0) { continue; }
                $part=D::mul($take,$this->state['lots'][$i]['unit_cost']);$basis=D::add($basis,$part);
                $matched[]=['lot_id'=>$this->state['lots'][$i]['id'],'quantity'=>$take,'cost'=>$part];
                $this->state['lots'][$i]['remaining']=D::sub($this->state['lots'][$i]['remaining'],$take);$o['allocations'][$i]=D::sub($available,$take);$needed=D::sub($needed,$take);
            }
            if(D::cmp($needed,'0')>0 || D::cmp($proceeds,D::mul($basis,D::add('1',$m['minimum_profit'])))<=0) { throw new RuntimeException('Comptability profit/allocation invariant violated.'); }
            $profit=D::sub($proceeds,$basis);$this->balance($base,D::sub('0',$spent));$this->balance($quote,$proceeds);
        }
        $o['filled']=D::add($o['filled'],$q);$o['remaining']=D::sub($o['remaining'],$q);
        if(D::cmp($o['remaining'],'0')===0) { $o['status']='filled';$o['closed_at_ms']=$t; }
        $campaign=$this->state['campaigns'][$o['symbol']]??['side'=>$o['side'],'quantity'=>'0','value'=>'0'];
        if($campaign['side']!==$o['side']) { $campaign=['side'=>$o['side'],'quantity'=>'0','value'=>'0']; }
        $campaign['quantity']=D::add($campaign['quantity'],$q);$campaign['value']=D::add($campaign['value'],$cost);$this->state['campaigns'][$o['symbol']]=$campaign;
        $fx=$quote===$this->config['reporting_currency']?['rate'=>'1','observed_at_ms'=>$t]:($this->state['fx'][$quote]??null);
        if($fx!==null && $t-$fx['observed_at_ms']>$this->config['fx_max_age_ms']) { $fx=null; }
        $this->state['fills'][]=['order_id'=>$id,'role'=>$o['role'],'symbol'=>$o['symbol'],'side'=>$o['side'],'time_ms'=>$t,'quantity'=>$q,'price'=>$price,'cost'=>$cost,
            'fee'=>['amount'=>$fee,'currency'=>$feeCurrency,'rate'=>$rate,'liquidity'=>$liquidity],'allocations'=>$matched,'realized_profit'=>$profit,'profit_currency'=>$quote,
            'conversion'=>$fx,'reporting_profit'=>$profit!==null&&$fx!==null?D::mul($profit,$fx['rate']):null,'reporting_cost'=>$fx!==null?D::mul($cost,$fx['rate']):null];
    }
}
