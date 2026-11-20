<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;

/**
 * CCXT 4.5.57's PHP NDAX snapshot parser appends levels to a copied array.
 * Rebuild the two sides while keeping CCXT's timestamp, nonce and symbol metadata.
 * The same override covers REST and the Pro subscription's initial snapshot.
 */
trait NdaxBookSnapshot
{
    public function parse_order_book($orderbook,$symbol,$timestamp=null,$bidsKey='bids',$asksKey='asks',int|string $priceKey=6,int|string $amountKey=8,int|string $countOrIdKey=2): array
    {
        $book=parent::parse_order_book($orderbook,$symbol,$timestamp,$bidsKey,$asksKey,$priceKey,$amountKey,$countOrIdKey);
        // Replace, rather than append to, the parent sides so an upstream fix
        // does not duplicate the snapshot's liquidity.
        $book[$bidsKey]=[];$book[$asksKey]=[];
        foreach($orderbook as $row) {
            if(!is_array($row)) { throw new RuntimeException('Invalid NDAX book snapshot row.'); }
            $side=$this->safe_integer($row,9);
            if($side!==0 && $side!==1) { throw new RuntimeException('Invalid NDAX book snapshot side.'); }
            $key=$side===0?$bidsKey:$asksKey;
            $book[$key][]=$this->parse_order_book_bid_ask($row,$priceKey,$amountKey,$countOrIdKey);
        }
        $book[$bidsKey]=$this->sort_by($book[$bidsKey],0,true);
        $book[$asksKey]=$this->sort_by($book[$asksKey],0);
        return $book;
    }
}
