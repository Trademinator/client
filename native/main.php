<?php

declare(strict_types=1);

// AOT entry with embedded Zend/Composer dependencies; full AOT is a separate gate.
function main(int $argc, array $argv): void
{
    require_once __DIR__.'/../vendor/autoload.php';
    $application = new Trademinator\Client\M0\Application();
    exit($application->run($argv));
}
