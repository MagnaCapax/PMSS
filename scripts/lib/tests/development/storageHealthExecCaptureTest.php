<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthExecCaptureTest extends TestCase
{
    public function testExecCaptureCollectsStdoutAndStderr(): void
    {
        $this->pmssAssertArraySubsetSame(['rc' => 7, 'stdout' => "alpha\n", 'stderr' => "beta\n"], \pmssStorageHealthExecCapture("printf 'alpha\\n'; printf 'beta\\n' >&2; exit 7", 5));
    }

    public function testExecCaptureReturnsTimeoutCodeAndKeepsPartialStdout(): void
    {
        $result = \pmssStorageHealthExecCapture("printf 'before\\n'; sleep 2; printf 'after\\n'", 1);

        $this->assertEquals(124, $result['rc']);
        $this->assertStringContainsString("before\n", $result['stdout']);
        $this->assertTrue(strpos($result['stdout'], "after\n") === false, 'Timed out command should not report output produced after termination');
    }

    public function testExecCaptureReturnsShellFailureCodeForMissingCommand(): void
    {
        $result = \pmssStorageHealthExecCapture('command_that_should_not_exist_pmss', 5);

        $this->assertEquals(127, $result['rc']);
        $this->assertTrue($result['stderr'] !== '');
    }
}
