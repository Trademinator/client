#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/m1/bootstrap.php';
exit((new Trademinator\Client\M1\Application())->run($argv));
