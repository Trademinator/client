<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

/** NDAX Pro inherits the affected async snapshot parser. */
final class NdaxPro extends \ccxt\pro\ndax
{
    use NdaxBookSnapshot;
}
