<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class PortManagerCliTest extends TestCase
{
    private function runPortManager(array $arguments, array $environment = []): array
    {
        return $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', $arguments, $environment);
    }

    private function makePortDir(): string
    {
        return $this->pmssMakeTempDir('pmss-port-dir-', 0755);
    }

    private function runPortManagerInDir(array $arguments, string $portDir): array
    {
        return $this->runPortManager($arguments, ['PMSS_PORT_MANAGER_DIR' => $portDir]);
    }

    public function testAssignPersistsPortInsideOverrideDirectory(): void
    {
        $portDir = $this->makePortDir();
        $result = $this->runPortManagerInDir(['assign', 'alice', 'lighttpd'], $portDir);

        $this->assertSame(0, $result['rc']);
        $this->assertMatches('/^\d+$/', trim($result['output']));
        $this->assertTrue(is_file($portDir.'/lighttpd-alice'));
    }

    public function testDefaultServiceLifecycleKeepsStableCliOutputs(): void
    {
        $portDir = $this->makePortDir();

        $assign = $this->runPortManagerInDir(['assign', 'alice'], $portDir);
        $assignedPort = trim($assign['output']);
        $viewAssigned = $this->runPortManagerInDir(['view', 'alice'], $portDir);
        $release = $this->runPortManagerInDir(['release', 'alice'], $portDir);
        $viewReleased = $this->runPortManagerInDir(['view', 'alice'], $portDir);

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
        $portDir = $this->makePortDir();
        file_put_contents($portDir.'/lighttpd-alice', "24567\n");

        $result = $this->runPortManagerInDir(['assign', 'alice', 'lighttpd'], $portDir);

        $this->assertSame(0, $result['rc']);
        $this->assertSame('24567', trim($result['output']));
    }

    public function testAssignFailsWhenExistingPortFileIsMalformed(): void
    {
        $portDir = $this->makePortDir();
        file_put_contents($portDir.'/lighttpd-alice', "not-a-port\n");

        $result = $this->runPortManagerInDir(['assign', 'alice', 'lighttpd'], $portDir);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
    }

    public function testAssignIgnoresMalformedSiblingAssignments(): void
    {
        $portDir = $this->makePortDir();
        file_put_contents($portDir.'/lighttpd-bob', "not-a-port\n");

        $result = $this->runPortManagerInDir(['assign', 'alice', 'lighttpd'], $portDir);

        $this->assertSame(0, $result['rc']);
        $this->assertMatches('/^\d+$/', trim($result['output']));
        $this->assertTrue(is_file($portDir.'/lighttpd-alice'));
    }

    public function testReleaseRemovesAssignedPort(): void
    {
        $portDir = $this->makePortDir();
        file_put_contents($portDir.'/lighttpd-alice', "24567\n");

        $result = $this->runPortManagerInDir(['release', 'alice', 'lighttpd'], $portDir);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('Port released', $result['output']);
        $this->assertFalse(file_exists($portDir.'/lighttpd-alice'));
    }

    public function testViewFailsWhenStoredAssignmentIsMalformed(): void
    {
        $portDir = $this->makePortDir();
        file_put_contents($portDir.'/lighttpd-alice', "not-a-port\n");

        $result = $this->runPortManagerInDir(['view', 'alice', 'lighttpd'], $portDir);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
    }

    public function testRejectsInvalidUsernameBeforeTouchingFilesystem(): void
    {
        $portDir = $this->makePortDir();
        $result = $this->runPortManagerInDir(['assign', '../alice', 'lighttpd'], $portDir);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid username', $result['output']);
        $this->assertFalse(file_exists($portDir.'/lighttpd-../alice'));
    }

    public function testRejectsInvalidServiceBeforeTouchingFilesystem(): void
    {
        $portDir = $this->makePortDir();
        $result = $this->runPortManagerInDir(['assign', 'alice', '../lighttpd'], $portDir);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid service', $result['output']);
        $this->assertFalse(file_exists($portDir.'/../lighttpd-alice'));
    }

    public function testFailsWhenPortDirectoryCannotBeCreated(): void
    {
        $blockedParent = $this->pmssMakeTempFile('pmss-port-blocked-');
        $result = $this->runPortManagerInDir(['assign', 'alice', 'lighttpd'], $blockedParent.'/ports');

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: unable to initialize port directory', $result['output']);
    }

    public function testRejectsSymlinkedPortDirectory(): void
    {
        $realDir = $this->pmssMakeTempDir('pmss-port-real-', 0755);
        $linkDir = $this->pmssMakeTempPath('pmss-port-link-');
        $this->pmssCreateSymlinkOrSkip($realDir, $linkDir);

        $result = $this->runPortManagerInDir(['assign', 'alice', 'lighttpd'], $linkDir);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: unable to initialize port directory', $result['output']);
        $this->assertFalse(file_exists($realDir.'/lighttpd-alice'));
    }

    public function testAssignRejectsBrokenSymlinkAssignment(): void
    {
        $portDir = $this->makePortDir();
        $outsideTarget = $this->pmssMakeTempPath('pmss-port-outside-');
        $portFile = $portDir.'/lighttpd-alice';
        $this->pmssCreateSymlinkOrSkip($outsideTarget, $portFile);

        $result = $this->runPortManagerInDir(['assign', 'alice', 'lighttpd'], $portDir);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
        $this->assertTrue(is_link($portFile));
        $this->assertFalse(file_exists($outsideTarget));
    }

    public function testReleaseRejectsSymlinkAssignmentWithoutRemovingTarget(): void
    {
        $portDir = $this->makePortDir();
        $outsideTarget = $this->pmssMakeTempFile('pmss-port-outside-');
        file_put_contents($outsideTarget, "24567\n");
        $portFile = $portDir.'/lighttpd-alice';
        $this->pmssCreateSymlinkOrSkip($outsideTarget, $portFile);

        $result = $this->runPortManagerInDir(['release', 'alice', 'lighttpd'], $portDir);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
        $this->assertTrue(is_link($portFile));
        $this->assertSame("24567\n", (string) file_get_contents($outsideTarget));
    }

    public function testInvalidActionKeepsUsageContract(): void
    {
        $result = $this->runPortManager(['dance', 'alice']);

        $this->assertSame(0, $result['rc']);
        $this->assertSame('Usage: portManager.php [view|assign|release] USER [SERVICE]', $result['output']);
    }
}
