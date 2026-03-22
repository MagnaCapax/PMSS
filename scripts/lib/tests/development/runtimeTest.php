<?php
namespace PMSS\Tests;

// Tests for runtime helpers (loaded via scripts/lib/update.php)
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';

class RuntimeTest extends TestCase
{
    public function testPmssPrepareCliEntrypointAppendsArgumentsToGlobalArgv(): void
    {
        $originalGlobalArgv = $GLOBALS['argv'] ?? null;
        $originalServerArgv = $_SERVER['argv'] ?? null;
        $GLOBALS['argv'] = ['wrapper.php'];
        $_SERVER['argv'] = ['wrapper.php'];

        try {
            \pmssPrepareCliEntrypoint(false, ['--quiet']);
            $this->assertEquals(['wrapper.php', '--quiet'], $GLOBALS['argv']);
            $this->assertEquals($GLOBALS['argv'], $_SERVER['argv']);
        } finally {
            if ($originalGlobalArgv === null) {
                unset($GLOBALS['argv']);
            } else {
                $GLOBALS['argv'] = $originalGlobalArgv;
            }

            if ($originalServerArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalServerArgv;
            }
        }
    }

    public function testDefaultCommandTimeoutIs1200Seconds(): void
    {
        $this->assertTrue(defined('PMSS_COMMAND_TIMEOUT_DEFAULT'));
        $this->assertEquals(1200, constant('PMSS_COMMAND_TIMEOUT_DEFAULT'));
    }

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

    public function testRunCommandAptTimeoutFloorIgnoresLowerEnvTimeout(): void
    {
        $prev = getenv('PMSS_COMMAND_TIMEOUT');
        putenv('PMSS_COMMAND_TIMEOUT=1');
        try {
            $rc = \runCommand('echo apt-get; sleep 2', false, function (string $m): void {});
        } finally {
            if ($prev === false) {
                putenv('PMSS_COMMAND_TIMEOUT');
            } else {
                putenv('PMSS_COMMAND_TIMEOUT='.$prev);
            }
        }
        $this->assertEquals(0, $rc);
    }

    public function testRunCommandAptExecHandlesLeadingEnvAssignments(): void
    {
        $rc = \runCommand(
            'DEBIAN_FRONTEND=noninteractive APT_LISTCHANGES_FRONTEND=none echo apt-get',
            false,
            function (string $m): void {}
        );
        $this->assertEquals(0, $rc);
        $out = $GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '';
        $this->assertTrue(strpos($out, 'apt-get') !== false);
    }

    // Note: logMessage() in lib/update.php targets a fixed log location; avoid writing system logs here.
}
