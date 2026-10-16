<?php

declare(strict_types=1);

namespace Trademinator\Client\M3;

/** Only locally constructed, allowlisted diagnostics; never exchange response text. */
final class CredentialError extends \ccxt\AuthenticationError
{
    public function __construct(public readonly array $diagnostic)
    {
        parent::__construct($diagnostic['detail']);
    }
}
