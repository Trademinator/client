<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

/** Public Binance streams using the reset-compatible CCXT order book. */
final class BinancePro extends \ccxt\pro\binance
{
    public function order_book($snapshot = array(), $depth = PHP_INT_MAX)
    {
        return new BinanceOrderBook($snapshot,$depth);
    }
}
