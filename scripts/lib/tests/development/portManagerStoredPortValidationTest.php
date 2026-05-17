<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/portManager.php';

class PortManagerStoredPortValidationTest extends TestCase
{
    /** @var string */
    private $portDir;

    public function setUp(): void
    {
        $this->portDir = $this->pmssMakeTempDir('pmss-port-manager-');
    }

    public function testReadAssignedPortAcceptsDigitsOnlyPayload(): void
    {
        $path = $this->portDir.'/lighttpd-alice';
        file_put_contents($path, "22000\n");

        $this->assertSame(22000, \pmssPortManagerReadAssignedPort($path));
    }

    public function testReadAssignedPortRejectsTrailingGarbage(): void
    {
        $path = $this->portDir.'/lighttpd-alice';
        file_put_contents($path, "22000oops\n");

        $this->assertSame(null, \pmssPortManagerReadAssignedPort($path));
    }

    public function testReadAssignedPortRejectsOutOfRangeValues(): void
    {
        $path = $this->portDir.'/lighttpd-alice';
        file_put_contents($path, "70000\n");

        $this->assertSame(null, \pmssPortManagerReadAssignedPort($path));
    }

    public function testReadAssignedPortRejectsSymlink(): void
    {
        $realPath = $this->portDir.'/real';
        $linkPath = $this->portDir.'/lighttpd-alice';
        file_put_contents($realPath, "22000\n");
        $this->pmssCreateSymlinkOrSkip($realPath, $linkPath);

        $this->assertSame(null, \pmssPortManagerReadAssignedPort($linkPath));
    }

    public function testViewFailsWhenStoredAssignmentIsMalformed(): void
    {
        file_put_contents($this->portDir.'/lighttpd-alice', "22000oops\n");
        $command = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/util/portManager.php',
            ['view', 'alice'],
            ['PMSS_PORT_MANAGER_DIR' => $this->portDir],
            'pmss-port-view-stderr-'
        );
        $this->pmssAssertCommandFailsToStderr($command['result'], $command['stderrPath'], "Error: invalid stored port assignment\n");
    }

    public function testAssignFailsWhenExistingAssignmentIsMalformed(): void
    {
        file_put_contents($this->portDir.'/lighttpd-alice', "22000oops\n");
        $command = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/util/portManager.php',
            ['assign', 'alice'],
            ['PMSS_PORT_MANAGER_DIR' => $this->portDir],
            'pmss-port-assign-stderr-'
        );
        $this->pmssAssertCommandFailsToStderr($command['result'], $command['stderrPath'], "Error: invalid stored port assignment\n");
    }
}
