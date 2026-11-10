<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class PortManagerCliTest extends TestCase
{
    public function testAssignPersistsPortInsideOverrideDirectory(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $result['rc']);
        $this->assertMatches('/^\d+$/', trim($result['output']));
        $this->assertTrue(is_file($portDir.'/lighttpd-fixtur01'));
    }

    public function testDefaultServiceLifecycleKeepsStableCliOutputs(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);

        $assign = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);
        $assignedPort = trim($assign['output']);
        $viewAssigned = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['view', 'fixtur01'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);
        $release = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['release', 'fixtur01'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);
        $viewReleased = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['view', 'fixtur01'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $assign['rc']);
        $this->assertMatches('/^\d+$/', $assignedPort);
        $this->assertSame(0, $viewAssigned['rc']);
        $this->assertSame($assignedPort, trim($viewAssigned['output']));
        $this->assertSame(0, $release['rc']);
        $this->assertSame('Port released', $release['output']);
        $this->assertSame(0, $viewReleased['rc']);
        $this->assertSame('No port assigned', $viewReleased['output']);
    }

    public function testAssignReusesExistingPort(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $this->pmssWriteFile($portDir.'/lighttpd-fixtur01', "24567\n");

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $result['rc']);
        $this->assertSame('24567', trim($result['output']));
    }

    public function testAssignDoesNotWriteWhenLockCannotBeAcquired(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $lockDir = $this->pmssMakeTempDir('pmss-port-lock-');
        $this->pmssCreateSymlinkOrSkip($this->pmssMakeTempFile('pmss-port-lock-target-'), $lockDir.'/pmss-portManager.lock');

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', 'lighttpd'], [
            'PMSS_PORT_MANAGER_DIR' => $portDir,
            'PMSS_RUNTIME_LOCK_DIR' => $lockDir,
        ]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: unable to acquire port lock', $result['output']);
        $this->assertFalse(file_exists($portDir.'/lighttpd-fixtur01'));
    }

    public function testReleaseDoesNotRemoveAssignmentWhenLockCannotBeAcquired(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $this->pmssWriteFile($portDir.'/lighttpd-fixtur01', "24567\n");
        $lockDir = $this->pmssMakeTempDir('pmss-port-lock-');
        $this->pmssCreateSymlinkOrSkip($this->pmssMakeTempFile('pmss-port-lock-target-'), $lockDir.'/pmss-portManager.lock');

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['release', 'fixtur01', 'lighttpd'], [
            'PMSS_PORT_MANAGER_DIR' => $portDir,
            'PMSS_RUNTIME_LOCK_DIR' => $lockDir,
        ]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: unable to acquire port lock', $result['output']);
        $this->assertSame("24567\n", file_get_contents($portDir.'/lighttpd-fixtur01'));
    }

    public function testAssignFailsWhenExistingPortFileIsMalformed(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $this->pmssWriteFile($portDir.'/lighttpd-fixtur01', "not-a-port\n");

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
    }

    public function testAssignIgnoresMalformedSiblingAssignments(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $this->pmssWriteFile($portDir.'/lighttpd-fixtur02', "not-a-port\n");

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $result['rc']);
        $this->assertMatches('/^\d+$/', trim($result['output']));
        $this->assertTrue(is_file($portDir.'/lighttpd-fixtur01'));
    }

    public function testReleaseRemovesAssignedPort(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $this->pmssWriteFile($portDir.'/lighttpd-fixtur01', "24567\n");

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['release', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('Port released', $result['output']);
        $this->assertFalse(file_exists($portDir.'/lighttpd-fixtur01'));
    }

    public function testViewFailsWhenStoredAssignmentIsMalformed(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $this->pmssWriteFile($portDir.'/lighttpd-fixtur01', "not-a-port\n");

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['view', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
    }

    public function testRejectsInvalidUsernameBeforeTouchingFilesystem(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', '../fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid username', $result['output']);
        $this->assertFalse(file_exists($portDir.'/lighttpd-../fixtur01'));
    }

    public function testRejectsInvalidServiceBeforeTouchingFilesystem(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', '../lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid service', $result['output']);
        $this->assertFalse(file_exists($portDir.'/../lighttpd-fixtur01'));
    }

    public function testFailsWhenPortDirectoryCannotBeCreated(): void
    {
        $blockedParent = $this->pmssMakeTempFile('pmss-port-blocked-');
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $blockedParent.'/ports']);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: unable to initialize port directory', $result['output']);
    }

    public function testRejectsSymlinkedPortDirectory(): void
    {
        $realDir = $this->pmssMakeTempDir('pmss-port-real-', 0755);
        $linkDir = $this->pmssMakeTempPath('pmss-port-link-');
        $this->pmssCreateSymlinkOrSkip($realDir, $linkDir);

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $linkDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: unable to initialize port directory', $result['output']);
        $this->assertFalse(file_exists($realDir.'/lighttpd-fixtur01'));
    }

    public function testAssignRejectsBrokenSymlinkAssignment(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $outsideTarget = $this->pmssMakeTempPath('pmss-port-outside-');
        $portFile = $portDir.'/lighttpd-fixtur01';
        $this->pmssCreateSymlinkOrSkip($outsideTarget, $portFile);

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['assign', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
        $this->assertTrue(is_link($portFile));
        $this->assertFalse(file_exists($outsideTarget));
    }

    public function testReleaseRejectsSymlinkAssignmentWithoutRemovingTarget(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $outsideTarget = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-port-outside-'), "24567\n");
        $portFile = $portDir.'/lighttpd-fixtur01';
        $this->pmssCreateSymlinkOrSkip($outsideTarget, $portFile);

        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['release', 'fixtur01', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
        $this->assertTrue(is_link($portFile));
        $this->assertSame("24567\n", (string) file_get_contents($outsideTarget));
    }

    public function testInvalidActionKeepsUsageContract(): void
    {
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', ['dance', 'fixtur01']);

        $this->assertSame(0, $result['rc']);
        $this->assertSame('Usage: portManager.php [view|assign|release] USER [SERVICE]', $result['output']);
    }
}
