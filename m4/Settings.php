<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use InvalidArgumentException;

final class Settings
{
    public static function validate(array $v): array
    {
        foreach(['account','source_exchange','execution_exchange','reporting_currency'] as $key) {
            if(!is_string($v[$key]??null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$v[$key])) { throw new InvalidArgumentException('Invalid '.$key); }
        }
        if(empty($v['balances']) || empty($v['markets']) || count($v['markets'])>50) { throw new InvalidArgumentException('Supply balances and 1–50 markets.'); }
        foreach($v['balances'] as $c=>&$balance) {
            if(!preg_match('/^[A-Z0-9]{1,16}$/D',$c)) { throw new InvalidArgumentException('Invalid currency.'); }
            $balance=Decimal::value($balance);
        } unset($balance);
        foreach($v['markets'] as $symbol=>&$m) {
            if(!preg_match('/^[A-Z0-9]{1,16}\/[A-Z0-9]{1,16}$/D',$symbol)) { throw new InvalidArgumentException('Only exact spot base/quote pairs are supported.'); }
            foreach(['tick_size','amount_step','minimum_amount'] as $k) { $m[$k]=Decimal::value($m[$k]??null,true); }
            $m['minimum_cost']=Decimal::value($m['minimum_cost']??null);
            foreach(['maximum_amount','maximum_cost','minimum_price','maximum_price'] as $k) { if(isset($m[$k])) { $m[$k]=Decimal::value($m[$k],true); } }
            foreach(['maker_fee'=>'0.001','taker_fee'=>'0.002','risk_factor'=>'0.25','minimum_profit'=>'0','volume_participation'=>'0.01'] as $k=>$default) {
                $m[$k]=Decimal::value($m[$k]??$default);
                if(Decimal::cmp($m[$k],'1')>=0 && $k!=='risk_factor' || Decimal::cmp($m[$k],'1')>0) { throw new InvalidArgumentException('Invalid '.$k); }
            }
            if(Decimal::cmp($m['risk_factor'],'0.01')<0 || Decimal::cmp($m['volume_participation'],'0')<=0) { throw new InvalidArgumentException('Invalid risk or participation.'); }
            if(Decimal::cmp($m['maker_fee'],$m['taker_fee'])>0) { throw new InvalidArgumentException('Maker fee cannot exceed conservative taker reservation.'); }
            $m['fee_asset']??='quote';
            if(!in_array($m['fee_asset'],['base','quote','get','give'],true)) { throw new InvalidArgumentException('fee_asset must be base, quote, get or give.'); }
            foreach(['period_ms'=>60000,'evaluation_ms'=>20000] as $k=>$default) {
                $m[$k]??=$default;
                if(!is_int($m[$k]) || $m[$k]<1000 || $m[$k]>2592000000) { throw new InvalidArgumentException('Invalid '.$k); }
            }
            if(isset($m['intelligence']) && \Trademinator\Client\M4\Application::periodMs($m['intelligence']['period']??'')!==$m['period_ms']) { throw new InvalidArgumentException('Intelligence period must match period_ms.'); }
        } unset($m);
        $v['policy']??='two_order';
        if(!in_array($v['policy'],['two_order','main_only'],true)) { throw new InvalidArgumentException('Unknown order policy.'); }
        foreach(['submission_delay_ms'=>250,'cancellation_delay_ms'=>250,'stale_ms'=>30000,'fx_max_age_ms'=>86400000] as $k=>$d) {
            $v[$k]??=$d;
            if(!is_int($v[$k]) || $v[$k]<0 || $v[$k]>86400000) { throw new InvalidArgumentException('Invalid '.$k); }
        }
        $v['opening_lots']??=[];
        foreach($v['opening_lots'] as &$lot) {
            if(!isset($v['markets'][$lot['symbol']??''])) { throw new InvalidArgumentException('Unknown opening lot market.'); }
            $lot['quantity']=Decimal::value($lot['quantity']??null,true);
            $lot['unit_cost']=Decimal::value($lot['unit_cost']??null,true);
        } unset($lot);
        return $v;
    }
}
