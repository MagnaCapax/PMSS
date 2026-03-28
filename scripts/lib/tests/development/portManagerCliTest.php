<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class PortManagerCliTest extends TestCase
{
    private function runPortManager(array $arguments, array $environment = []): array
    {
        return $this->pmssRunRepoPhpScriptCommand('scripts/util/portManager.php', $arguments, $environment);
    }

    public function testAssignPersistsPortInsideOverrideDirectory(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $result = $this->runPortManager(['assign', 'alice', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $result['rc']);
        $this->assertMatches('/^\d+$/', trim($result['output']));
        $this->assertTrue(is_file($portDir.'/lighttpd-alice'));
    }

    public function testAssignReusesExistingPort(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        file_put_contents($portDir.'/lighttpd-alice', "24567\n");

        $result = $this->runPortManager(['assign', 'alice', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $result['rc']);
        $this->assertSame('24567', trim($result['output']));
    }

    public function testAssignFailsWhenExistingPortFileIsMalformed(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        file_put_contents($portDir.'/lighttpd-alice', "not-a-port\n");

        $result = $this->runPortManager(['assign', 'alice', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
    }

    public function testAssignIgnoresMalformedSiblingAssignments(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        file_put_contents($portDir.'/lighttpd-bob', "not-a-port\n");

        $result = $this->runPortManager(['assign', 'alice', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $result['rc']);
        $this->assertMatches('/^\d+$/', trim($result['output']));
        $this->assertTrue(is_file($portDir.'/lighttpd-alice'));
    }

    public function testReleaseRemovesAssignedPort(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        file_put_contents($portDir.'/lighttpd-alice', "24567\n");

        $result = $this->runPortManager(['release', 'alice', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('Port released', $result['output']);
        $this->assertFalse(file_exists($portDir.'/lighttpd-alice'));
    }

    public function testViewFailsWhenStoredAssignmentIsMalformed(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        file_put_contents($portDir.'/lighttpd-alice', "not-a-port\n");

        $result = $this->runPortManager(['view', 'alice', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid stored port assignment', $result['output']);
    }

    public function testRejectsInvalidUsernameBeforeTouchingFilesystem(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $result = $this->runPortManager(['assign', '../alice', 'lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid username', $result['output']);
        $this->assertFalse(file_exists($portDir.'/lighttpd-../alice'));
    }

    public function testRejectsInvalidServiceBeforeTouchingFilesystem(): void
    {
        $portDir = $this->pmssMakeTempDir('pmss-port-dir-', 0755);
        $result = $this->runPortManager(['assign', 'alice', '../lighttpd'], ['PMSS_PORT_MANAGER_DIR' => $portDir]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: invalid service', $result['output']);
        $this->assertFalse(file_exists($portDir.'/../lighttpd-alice'));
    }

    public function testFailsWhenPortDirectoryCannotBeCreated(): void
    {
        $blockedParent = $this->pmssMakeTempFile('pmss-port-blocked-');
        $result = $this->runPortManager(
            ['assign', 'alice', 'lighttpd'],
            ['PMSS_PORT_MANAGER_DIR' => $blockedParent.'/ports']
        );

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Error: unable to initialize port directory', $result['output']);
    }
}
