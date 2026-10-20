#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
require dirname(__DIR__).'/vendor/autoload.php';
if (count($argv) !== 2 || !is_file($argv[1])) { fwrite(STDERR, "Internal paper worker requires a configuration snapshot.\n"); exit(1); }
exit(Trademinator\Client\M4\Worker::run($argv[1]));
