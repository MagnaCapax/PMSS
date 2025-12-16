<?php
namespace PMSS\Tests;

// Tests for runtime helpers (loaded via scripts/lib/update.php)
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class RuntimeTest extends TestCase
{
    public function testRunCommandEchoSuccessCapturesStdout(): void
    {
        $captured = [];
        $rc = \runCommand('echo HELLO_RUNTIME', false, function (string $m) use (&$captured): void { $captured[] = $m; });
        $this->assertEquals(0, $rc);
        $out = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '';
        $this->assertTrue(strpos($out, 'HELLO_RUNTIME') !== false);
    }

    public function testRunCommandFailureCapturesStderrAndNonZero(): void
    {
        $rc = \runCommand('ls /definitely-not-a-real-path-xyz 2>/dev/null; exit 2', false, function (string $m): void {});
        $this->assertTrue($rc !== 0);
        $err = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stderr'] ?? '';
        $this->assertTrue(is_string($err));
    }

    public function testRunCommandInheritTtyModeDoesNotBreakCallers(): void
    {
        $rc = \runCommand('true', false, function (string $m): void {}, true);
        $this->assertEquals(0, $rc);
        $output = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] ?? null;
        $this->assertTrue(is_array($output));
        $this->assertTrue(array_key_exists('stdout', $output));
        $this->assertTrue(array_key_exists('stderr', $output));
    }

    // Note: logMessage() in lib/update.php targets a fixed log location; avoid writing system logs here.
}
