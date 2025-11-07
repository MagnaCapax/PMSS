<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';

if (!function_exists('logmsg')) {
    function logmsg(string $message): void
    {
        RunStepLoggingTest::$logMessages[] = $message;
    }
}

class RunStepLoggingTest extends TestCase
{
    public static array $logMessages = [];

    private function reset(): void
    {
        self::$logMessages = [];
        unset($GLOBALS['PMSS_LAST_COMMAND_OUTPUT'], $GLOBALS['PMSS_PROFILE']);
    }

    public function testDryRunSkipsCommand(): void
    {
        $this->reset();
        putenv('PMSS_DRY_RUN=1');
        runStep('Dry run noop', 'echo should-not-run');
        putenv('PMSS_DRY_RUN');
        $this->assertTrue(empty($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? ''), 'stdout should be empty in dry run');
        $this->assertEquals('SKIP', $GLOBALS['PMSS_PROFILE'][0]['status']);
    }

    public function testStdoutStderrTruncated(): void
    {
        $this->reset();
        $long = str_repeat('x', 1000);
        runStep('Echo long', 'php -r '.escapeshellarg('fwrite(STDOUT, str_repeat("x", 1000)); fwrite(STDERR, str_repeat("y", 1000));'));
        $profile = $GLOBALS['PMSS_PROFILE'][0];
        $this->assertTrue(strlen($profile['stdout_excerpt']) <= 300, 'stdout excerpt should be truncated');
        $this->assertTrue(strlen($profile['stderr_excerpt']) <= 300, 'stderr excerpt should be truncated');
    }
}
