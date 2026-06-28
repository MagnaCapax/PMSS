<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/portManager.php';

class PortManagerStoredPortValidationTest extends TestCase
{
    /** @var string */
    private $portDir;

    public function setUp(): void
    {
        $this->portDir = $this->pmssMakeTempDir('pmss-port-manager-');
    }

    private function assignmentPath(?string $payload = null): string
    {
        $path = $this->portDir.'/lighttpd-alice';
        if ($payload !== null) file_put_contents($path, $payload);

        return $path;
    }

    private function assertMalformedAssignmentFailsForAction(string $action): void
    {
        $this->assignmentPath("22000oops\n");
        $command = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/util/portManager.php',
            [$action, 'alice'],
            ['PMSS_PORT_MANAGER_DIR' => $this->portDir],
            'pmss-port-'.$action.'-stderr-'
        );
        $this->pmssAssertCommandFailsToStderr($command['result'], $command['stderrPath'], "Error: invalid stored port assignment\n");
    }

    public function testReadAssignedPortAcceptsDigitsOnlyPayload(): void
    {
        $path = $this->assignmentPath("22000\n");

        $this->assertSame(22000, \pmssPortManagerReadAssignedPort($path));
    }

    public function testReadAssignedPortRejectsTrailingGarbage(): void
    {
        $path = $this->assignmentPath("22000oops\n");

        $this->assertSame(null, \pmssPortManagerReadAssignedPort($path));
    }

    public function testReadAssignedPortRejectsOutOfRangeValues(): void
    {
        $path = $this->assignmentPath("70000\n");

        $this->assertSame(null, \pmssPortManagerReadAssignedPort($path));
    }

    public function testReadAssignedPortRejectsSymlink(): void
    {
        $realPath = $this->portDir.'/real';
        $linkPath = $this->assignmentPath();
        file_put_contents($realPath, "22000\n");
        $this->pmssCreateSymlinkOrSkip($realPath, $linkPath);

        $this->assertSame(null, \pmssPortManagerReadAssignedPort($linkPath));
    }

    public function testWriteAssignedPortPersistsReadablePortWithSafeMode(): void
    {
        $path = $this->assignmentPath();

        $this->assertTrue(\pmssPortManagerWriteAssignedPort($this->portDir, $path, 22000));
        $this->assertSame(22000, \pmssPortManagerReadAssignedPort($path));
        $this->assertSame(0640, fileperms($path) & 0777);
    }

    public function testWriteAssignedPortRejectsSymlinkWithoutTouchingTarget(): void
    {
        $realPath = $this->pmssMakeTempFile('pmss-port-real-');
        $linkPath = $this->assignmentPath();
        file_put_contents($realPath, "22000\n");
        $this->pmssCreateSymlinkOrSkip($realPath, $linkPath);

        $this->assertFalse(\pmssPortManagerWriteAssignedPort($this->portDir, $linkPath, 23000));
        $this->assertSame("22000\n", (string) file_get_contents($realPath));
    }

    public function testWriteAssignedPortRejectsOutOfRangePort(): void
    {
        $path = $this->assignmentPath();

        $this->assertFalse(\pmssPortManagerWriteAssignedPort($this->portDir, $path, 80));
        $this->assertFalse(file_exists($path));
    }

    public function testSelectAvailablePortReturnsNullWhenRangeExhausted(): void
    {
        $used = [];
        for ($port = \PMSS_PORT_MANAGER_MIN_PORT; $port <= \PMSS_PORT_MANAGER_MAX_PORT; $port++) {
            $used[$port] = true;
        }

        $this->assertSame(null, \pmssPortManagerSelectAvailablePort($used));
    }

    public function testSelectAvailablePortFindsOnlyGap(): void
    {
        $used = [];
        for ($port = \PMSS_PORT_MANAGER_MIN_PORT; $port <= \PMSS_PORT_MANAGER_MAX_PORT; $port++) {
            $used[$port] = true;
        }
        unset($used[24567]);

        $this->assertSame(24567, \pmssPortManagerSelectAvailablePort($used));
    }

    public function testViewFailsWhenStoredAssignmentIsMalformed(): void
    {
        $this->assertMalformedAssignmentFailsForAction('view');
    }

    public function testAssignFailsWhenExistingAssignmentIsMalformed(): void
    {
        $this->assertMalformedAssignmentFailsForAction('assign');
    }
}
