<?php

declare(strict_types=1);

namespace Trademinator\Client\M0;

// Isolated test process only: use the real dispatcher/configuration with test
// doubles for math and the daemon. No Composer, network, or native SDK is needed.
require dirname(__DIR__, 2).'/src/Application.php';
require dirname(__DIR__, 2).'/src/Config.php';

function diagnosticFixtureFailure(string $secret, int $depth = 0): void
{
    if ($depth > 0) {
        diagnosticFixtureFailure($secret, $depth - 1);
        return;
    }
    try {
        strlen(); // Real engine ArgumentCountError, not a guessed error message.
    } catch (\ArgumentCountError $error) {
        // Prove the test exercises a trace that actually contains argument values.
        if (ini_get('zend.exception_ignore_args') === '0'
            && !str_contains(serialize($error->getTrace()), $secret)) {
            fwrite(STDERR, 'Fixture failed to capture its secret argument.'.PHP_EOL);
            exit(90);
        }
        throw $error;
    }
}

final class MathProbe
{
    public static function run(): array
    {
        $scenario = getenv('M0_DIAGNOSTIC_TEST_SCENARIO');
        if ($scenario === 'success' || $scenario === 'daemon') { return ['status' => 'passed']; }
        if ($scenario === 'message') { throw new \RuntimeException('M0_PRIVATE_MESSAGE_SENTINEL'); }
        diagnosticFixtureFailure('M0_PRIVATE_ARGUMENT_SENTINEL', $scenario === 'deep' ? 40 : 0);
        return [];
    }
}

final class Daemon
{
    public function __construct(Config $config) {}
    public function run(int $seconds = 0, int $samples = 0): int
    {
        diagnosticFixtureFailure('M0_PRIVATE_ARGUMENT_SENTINEL');
        return 0;
    }
}

exit((new Application())->run($argv));
