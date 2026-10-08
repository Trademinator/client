<?php

declare(strict_types=1);

function main(): void
{
    require_once __DIR__.'/../vendor/autoload.php';
    echo json_encode(Trademinator\Client\M0\MathProbe::run(), JSON_THROW_ON_ERROR).PHP_EOL;
}
