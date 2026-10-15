#!/usr/bin/env php
<?php

declare(strict_types=1);

$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install in the project directory first.\n");
    exit(1);
}
require $autoload;
exit((new Trademinator\Client\M0\Application())->run($argv));
