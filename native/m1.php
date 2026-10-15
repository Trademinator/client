<?php
declare(strict_types=1);

// M1 embedded-runtime entry. Validate separately from the M0 AOT experiments.
function main(int $argc, array $argv): void
{
    require_once __DIR__.'/../m1/bootstrap.php';
    exit((new Trademinator\Client\M1\Application())->run($argv));
}
