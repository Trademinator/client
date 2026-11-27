<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

/** Keep CCXT's reset price index aligned with its actual Binance book rows. */
final class BinanceOrderBook extends \ccxt\pro\OrderBook
{
    public function reset($snapshot = array())
    {
        parent::reset($snapshot);
        foreach(['asks','bids'] as $name) {
            $side=$this[$name];
            // CCXT 4.5.57 appends two index sentinels without matching rows.
            // Remove them before depth limiting or cached deltas can use them.
            // count($side) may be a shorter n-limited view of the actual rows.
            $side->index=array_slice($side->index,0,count($side->getArrayCopy()));
        }
    }
}
