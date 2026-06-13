<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapLockReleaseTest extends TestCase
{
    public function testBootstrapAndPhase2ShareTheStateDirectoryLockPath(): void
    {
        $expected = "define('PMSS_UPDATE_LOCK_FILE', '/var/lib/pmss/update.lock');";

        $this->pmssAssertRepoFileContainsString('scripts/update.php', $expected, 'update.php should move the global lock outside /var/run');
        $this->pmssAssertRepoFileContainsString('scripts/util/update-step2.php', $expected, 'update-step2.php should share the migrated lock path');
        $this->pmssAssertRepoFileNotContainsString('scripts/update.php', '/var/run/pmss/update.lock', 'update.php should not keep the legacy /var/run lock path');
        $this->pmssAssertRepoFileNotContainsString('scripts/util/update-step2.php', '/var/run/pmss/update.lock', 'update-step2.php should not keep the legacy /var/run lock path');
    }

    public function testBootstrapLockAcquisitionIsBoundedAndNonBlocking(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/update.php',
            ['PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS', 'LOCK_EX | LOCK_NB', "logEvent('update_lock_busy_skip'"],
            'update.php lock handling should contain: '
        );
        $this->pmssAssertRepoFileNotContainsString('scripts/update.php', 'flock($fh, LOCK_EX))', 'update.php should not use a blocking exclusive flock');
    }

    public function testPhase2StandaloneLockAcquisitionIsBoundedAndNonBlocking(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/update-step2.php',
            [
                'PMSS_UPDATE_LOCK_MAX_WAIT_SECONDS',
                "pmssLockFileAcquire(PMSS_UPDATE_LOCK_FILE, true, 'c', true",
                "'event' => 'update_lock_busy_skip'",
            ],
            'update-step2.php lock handling should contain: '
        );
        $this->pmssAssertRepoFileNotContainsString('scripts/util/update-step2.php', "pmssLockFileAcquire(PMSS_UPDATE_LOCK_FILE, false, 'c', true)", 'update-step2.php should not use a blocking lock acquire');
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

    public function testBootstrapExportsHeldLockFdClosePrefix(): void
    {
        $result = $this->runBootstrapInline(
            '$path = tempnam(sys_get_temp_dir(), "pmss-lock-"); '
            .'$handle = fopen($path, "c+"); '
            .'flock($handle, LOCK_EX); '
            .'pmssUpdateLockExportChildCloseFds($handle); '
            .'echo json_encode(['
            .'"env" => getenv("PMSS_UPDATE_LOCK_FDS"), '
            .'"prefix" => pmssUpdateLockChildClosePrefix(), '
            .'"command" => pmssShellCommandWithoutInheritedUpdateLock("printf ok")'
            .']); '
            .'fclose($handle); @unlink($path);'
        );

        $this->assertEquals(0, $result['rc'], 'bootstrap lock fd export should complete');
        $payload = $this->pmssDecodeJsonArray($result['output']);
        $this->assertTrue(is_string($payload['env']) && preg_match('/^\d+(,\d+)*$/', $payload['env']) === 1, 'Expected numeric lock fd env list');
        $this->assertStringContainsString('>&-; ', $payload['prefix']);
        $this->assertStringContainsString($payload['prefix'], $payload['command']);
    }

    public function testRuntimeShellInvocationClosesExportedUpdateLockFds(): void
    {
        $runtime = dirname(__DIR__, 3).'/lib/runtime.php';
        $result = $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
                'putenv("PMSS_UPDATE_LOCK_FDS=4,9"); '
                .'require '.var_export($runtime, true).'; '
                .'echo pmssCommandBashInvocation("printf ok");'
            ),
            ['PMSS_TEST_MODE' => '1'],
            '2>&1'
        );

        $this->assertEquals(0, $result['rc'], 'runtime shell invocation should complete');
        $this->assertStringContainsString('exec 4>&- 9>&-; ', $result['output']);
        $this->assertStringContainsString('printf ok', $result['output']);
    }

    public function testRuntimeLockFdListRejectsUnsafeFdRootFailSoft(): void
    {
        $runtime = dirname(__DIR__, 3).'/lib/runtime.php';
        $result = $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
                'require '.var_export($runtime, true).'; '
                .'$path = tempnam(sys_get_temp_dir(), "pmss-lock-"); '
                .'$handle = fopen($path, "c+"); '
                .'echo json_encode(['
                .'"empty" => pmssLockHandleFdList($handle, ""), '
                .'"nul" => pmssLockHandleFdList($handle, "/tmp".chr(0)."fd")'
                .']); '
                .'fclose($handle); @unlink($path);'
            ),
            ['PMSS_TEST_MODE' => '1'],
            '2>&1'
        );

        $this->assertEquals(0, $result['rc'], 'runtime lock fd scan should reject unsafe roots without warnings');
        $payload = $this->pmssDecodeJsonArray($result['output']);
        $this->assertSame([], $payload['empty']);
        $this->assertSame([], $payload['nul']);
    }

    public function testRuntimeLockReleaseIgnoresInvalidHandleFailSoft(): void
    {
        $runtime = dirname(__DIR__, 3).'/lib/runtime.php';
        $result = $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
                'require '.var_export($runtime, true).'; '
                .'pmssLockHandleRelease(false); '
                .'echo "ok";'
            ),
            ['PMSS_TEST_MODE' => '1'],
            '2>&1'
        );

        $this->assertEquals(0, $result['rc'], 'runtime lock release should tolerate invalid handles');
        $this->assertSame('ok', trim($result['output']));
    }

    public function testPhase2ExportsAndClearsLockFdList(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            'pmssLockHandleExportChildCloseFds($fh);',
            'putenv(PMSS_UPDATE_LOCK_FDS_ENV);',
            "passthru(pmssLockChildClosePrefix().'systemctl start nginx",
            "passthru(pmssLockChildClosePrefix().'/scripts/util/createNginxConfig.php --restart'",
        ]);
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
