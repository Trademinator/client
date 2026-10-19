<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** Safe rejection context projected from persisted review metadata. */
final class UnclassifiedMovementError extends \RuntimeException
{
    public readonly array $diagnostic;

    public function __construct(array $review)
    {
        $this->diagnostic = ['reason' => 'unclassified_movement_rejected', 'red_flag' => true,
            'movement_ref' => $review['ref'], 'source_kind' => $review['source_kind'],
            'currency' => $review['currency'], 'amount' => $review['amount']];
        parent::__construct('Unclassified movement was rejected: inter-user transfer support is not established for this exchange.');
    }
}
