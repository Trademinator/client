<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

/** Public NDAX REST client with the PHP snapshot compatibility correction. */
final class NdaxRest extends \ccxt\ndax
{
    use NdaxBookSnapshot;
}
