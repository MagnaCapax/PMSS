<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapLockReleaseTest extends TestCase
{
    public function testBootstrapAndPhase2ShareTheStateDirectoryLockPath(): void
    {
        $bootstrapSource = (string) file_get_contents(__DIR__.'/../../../update.php');
        $step2Source = (string) file_get_contents(__DIR__.'/../../../util/update-step2.php');
        $expected = "define('PMSS_UPDATE_LOCK_FILE', '/var/lib/pmss/update.lock');";

        $this->assertStringContainsString($expected, $bootstrapSource, 'update.php should move the global lock outside /var/run');
        $this->assertStringContainsString($expected, $step2Source, 'update-step2.php should share the migrated lock path');
        $this->assertStringNotContainsString('/var/run/pmss/update.lock', $bootstrapSource, 'update.php should not keep the legacy /var/run lock path');
        $this->assertStringNotContainsString('/var/run/pmss/update.lock', $step2Source, 'update-step2.php should not keep the legacy /var/run lock path');
    }

    public function testReleaseUpdateLockDoesNotDependOnRuntimeHelper(): void
    {
        $result = $this->runBootstrapInline(
            '$path = tempnam(sys_get_temp_dir(), "pmss-lock-"); '
            .'$handle = fopen($path, "c+"); '
            .'$GLOBALS["PMSS_UPDATE_LOCK_HANDLE"] = $handle; '
            .'putenv("PMSS_UPDATE_LOCK_HELD=1"); '
            .'pmssReleaseUpdateLock(); '
            .'echo json_encode(['
            .'"env" => getenv("PMSS_UPDATE_LOCK_HELD"), '
            .'"handle_defined" => isset($GLOBALS["PMSS_UPDATE_LOCK_HANDLE"]), '
            .'"handle_is_resource" => is_resource($handle)'
            .']); '
            .'@unlink($path);'
        );

        $this->assertEquals(0, $result['rc'], 'pmssReleaseUpdateLock should complete without fatal shutdown errors');
        $payload = $this->pmssDecodeJsonArray($result['output']);
        $this->assertEquals(false, $payload['env'], 'pmssReleaseUpdateLock should clear the held-lock environment flag');
        $this->assertFalse($payload['handle_defined'], 'pmssReleaseUpdateLock should clear the global lock handle');
        $this->assertFalse($payload['handle_is_resource'], 'pmssReleaseUpdateLock should close the lock handle directly');
    }

    public function testReleaseUpdateLockIsSafeWhenHandleAlreadyMissing(): void
    {
        $result = $this->runBootstrapInline(
            'putenv("PMSS_UPDATE_LOCK_HELD=1"); '
            .'pmssReleaseUpdateLock(); '
            .'echo json_encode(['
            .'"env" => getenv("PMSS_UPDATE_LOCK_HELD"), '
            .'"handle_defined" => isset($GLOBALS["PMSS_UPDATE_LOCK_HANDLE"])'
            .']);'
        );

        $this->assertEquals(0, $result['rc'], 'pmssReleaseUpdateLock should tolerate a missing handle');
        $payload = $this->pmssDecodeJsonArray($result['output']);
        $this->assertEquals(false, $payload['env'], 'pmssReleaseUpdateLock should still clear the held-lock environment flag');
        $this->assertFalse($payload['handle_defined'], 'pmssReleaseUpdateLock should leave no global handle behind');
    }

    /** @return array{rc:int, output:string, lines:array<int, string>} */
    private function runBootstrapInline(string $script): array
    {
        $libraryPath = dirname(__DIR__).'/common/updateBootstrapShim.php';
        return $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('require '.var_export($libraryPath, true).'; '.$script),
            ['PMSS_TEST_MODE' => '1'],
            '2>&1'
        );
    }
}
