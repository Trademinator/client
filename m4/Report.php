<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use Trademinator\Client\M4\Decimal as D;

final class Report
{
    public static function build(array $run): array
    {
        $s=$run['state'];$c=$run['config'];$earnings=[];$sizes=[];$profit='0';$original=[];$fees=[];$coverage=0;$sells=0;$partials=0;$cancelled=0;$durations=[];$roles=[];$curve=[];$peak='0';$drawdown='0';
        foreach($s['fills'] as $f) {
            $fc=$f['fee']['currency'];$fees[$fc]=D::add($fees[$fc]??'0',$f['fee']['amount']);
            $role=$f['role'];$roles[$role]['fills']=($roles[$role]['fills']??0)+1;
            if($f['realized_profit']!==null) {
                $sells++;$currency=$f['profit_currency'];$original[$currency]=D::add($original[$currency]??'0',$f['realized_profit']);
                if($f['reporting_profit']!==null) {
                    $coverage++;$profit=D::add($profit,$f['reporting_profit']);$earnings[]=(float)$f['reporting_profit'];
                    $roles[$role]['realized_profit']=D::add($roles[$role]['realized_profit']??'0',$f['reporting_profit']);
                    $peak=D::max($peak,$profit);$drawdown=D::max($drawdown,D::sub($peak,$profit));$curve[]=['time_ms'=>$f['time_ms'],'realized_profit'=>$profit];
                }
            }
        }
        foreach($s['orders'] as $o) {
            if((D::cmp($o['filled'],'0')>0 && D::cmp($o['filled'],$o['quantity'])<0) || count(array_filter($s['fills'],fn($f)=>$f['order_id']===$o['id']))>1) { $partials++; }
            if($o['status']==='cancelled') { $cancelled++; }
            if($o['status']==='filled') { $durations[]=$o['closed_at_ms']-$o['created_at_ms']; }
            $sum='0';$has=false;$complete=true;
            foreach($s['fills'] as $f) { if($f['order_id']===$o['id']) { $has=true;if($f['reporting_cost']===null) { $complete=false; }else { $sum=D::add($sum,$f['reporting_cost']); } } }
            if($has&&$complete) { $sizes[]=(float)$sum; }
        }
        $initial=$c['balances'][$c['reporting_currency']]??'0';$basisComplete=true;$represented=[];
        foreach($c['opening_lots'] as $lot) {
            [$base,$quote]=explode('/',$lot['symbol']);$represented[$base]=D::add($represented[$base]??'0',$lot['quantity']);
            if($quote===$c['reporting_currency']) { $initial=D::add($initial,D::mul($lot['quantity'],$lot['unit_cost'])); }else { $basisComplete=false; }
        }
        foreach($c['balances'] as $currency=>$amount) { if($currency!==$c['reporting_currency'] && D::cmp($amount,$represented[$currency]??'0')!==0) { $basisComplete=false; } }
        $complete=$basisComplete && $coverage===$sells;
        return ['recovery'=>$c['recovery']??null,'engine'=>Engine::VERSION,'reporting_currency'=>$c['reporting_currency'],'source_exchange'=>$c['source_exchange'],'execution_exchange'=>$c['execution_exchange'],
            'realized_net_profit'=>$coverage===$sells?$profit:null,'converted_realized_subtotal'=>$profit,'realized_profit_by_currency'=>$original,'fees_by_currency'=>$fees,
            'capital_at_cost'=>$basisComplete?$initial:null,'realized_capital'=>$complete?D::add($initial,$profit):null,
            'realized_return'=>$complete&&D::cmp($initial,'0')>0?D::div($profit,$initial):null,'realized_drawdown'=>$coverage===$sells?$drawdown:null,'realized_curve'=>$curve,
            'orders'=>count($s['orders']),'fills'=>count($s['fills']),'partial_orders'=>$partials,'cancelled_orders'=>$cancelled,'time_to_fill_ms'=>self::distribution($durations),'roles'=>$roles,
            'earnings_distribution'=>self::distribution($earnings),'filled_order_size_distribution'=>self::distribution($sizes),
            'valuation_coverage'=>['converted_sales'=>$coverage,'sales'=>$sells,'opening_basis_complete'=>$basisComplete],
            'gaps'=>$s['gaps'],'verified'=>$s['gaps']===[],'assumptions'=>$s['assumptions'],'balances'=>$s['balances'],
            'live_observations'=>$s['live_observations'], 'live_24h_observed'=>$s['gaps']===[] && $s['live_observations']!==[] && min(array_map(fn($v)=>$v['last_ms']-$v['first_ms'],$s['live_observations']))>=86400000,
            'live_orders_submitted'=>0,'events'=>$s['sequence'],'state_sha256'=>hash('sha256',json_encode($s,JSON_THROW_ON_ERROR))];
    }
    public static function distribution(array $samples): array
    {
        $n=count($samples);if(!$n) { return ['count'=>0,'mean'=>null,'median'=>null,'stddev'=>null,'histogram'=>[],'normal_curve'=>[]]; }
        sort($samples,SORT_NUMERIC);$mean=array_sum($samples)/$n;$sum=0.0;foreach($samples as $x) { $sum+=($x-$mean)**2; }
        $sd=$n>1?sqrt($sum/($n-1)):0.0;$median=$n%2?$samples[intdiv($n,2)]:($samples[$n/2-1]+$samples[$n/2])/2;
        $low=$samples[0];$high=$samples[$n-1];$bins=min(20,max(1,(int)ceil(sqrt($n))));$width=$high>$low?($high-$low)/$bins:1.0;$counts=array_fill(0,$bins,0);
        foreach($samples as $x) { $counts[min($bins-1,(int)floor(($x-$low)/$width))]++; }
        $hist=[];foreach($counts as $i=>$count) { $hist[]=['from'=>$low+$i*$width,'to'=>$low+($i+1)*$width,'count'=>$count]; }
        $normal=[];if($sd>0) { for($i=0;$i<=60;$i++) { $x=$mean-3*$sd+$i*$sd/10;$normal[]=['x'=>$x,'density'=>exp(-0.5*(($x-$mean)/$sd)**2)/($sd*sqrt(2*M_PI))]; } }
        return ['count'=>$n,'mean'=>$mean,'median'=>$median,'stddev'=>$sd,'stddev_definition'=>'sample','histogram'=>$hist,'normal_curve'=>$normal,'fit'=>'descriptive normal fit; no normality assumption'];
    }
}
