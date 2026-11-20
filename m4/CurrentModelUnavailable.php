<?php
declare(strict_types=1);
namespace Trademinator\Client\M4;

use RuntimeException;

/** The subscription is valid, but the Server has no unique publishable current model yet. */
final class CurrentModelUnavailable extends RuntimeException
{
}
