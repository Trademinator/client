<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;

/** Local scheduling advice; no HTTP request was sent and no inference failed. */
final class RequestDeferred extends RuntimeException
{
    public function __construct(public readonly int $retryAfterMs)
    {
        parent::__construct('Replay API request deferred by shared pacing; retry in '.$retryAfterMs.' ms.');
    }
}
