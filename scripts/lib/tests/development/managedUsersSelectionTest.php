<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/user/selection.php';

class ManagedUsersSelectionTest extends TestCase
{
    private $listUsersScript;

    protected function setUp(): void
    {
        $this->listUsersScript = $this->pmssMakeTempDir('pmss-list-users').'/listUsers.php';
    }

    private function writeListUsersScript(string $body): void
    {
        $this->pmssWriteExecutablePhpFile($this->listUsersScript, $body);
    }

    public function testManagedUsersSelectFromCommandReturnsSanitizedUsersWhenNoUsernameRequested(): void
    {
        $this->writeListUsersScript("echo \"user1\\nINVALID\\nuser1\\nuser2\\n\";");

        $selection = \pmssManagedUsersSelectFromCommand($this->listUsersScript);

        $this->assertEquals(0, $selection['exitCode']);
        $this->assertEquals('', $selection['username']);
        $this->assertEquals(['user1', 'user2'], $selection['users']);
    }

    public function testListManagedUsersResultKeepsExitCodeWhileSanitizingOutput(): void
    {
        $this->writeListUsersScript("fwrite(STDOUT, \" user1 \\nINVALID\\nuser1\\nuser2\\n\"); exit(7);");

        $result = \pmssListManagedUsersResult($this->listUsersScript);

        $this->assertEquals(7, $result['exitCode']);
        $this->assertEquals(['user1', 'user2'], $result['users']);
    }

    public function testListManagedUsersResultFailsClosedOnDiagnosticOutput(): void
    {
        $this->writeListUsersScript("echo \"PHP Fatal error: Uncaught Error: missing dependency\\nStack trace:\\n#0 /scripts/listUsers.php(1): demo()\\nuser1\\n\";");

        $result = \pmssListManagedUsersResult($this->listUsersScript);

        $this->assertEquals(1, $result['exitCode']);
        $this->assertEquals([], $result['users']);
    }

    public function testListManagedUsersReturnsEmptyListOnDiagnosticOutput(): void
    {
        $this->writeListUsersScript("echo \"Warning: require_once(/scripts/lib/users.php): Failed opening required\\nuser1\\n\";");

        $this->assertEquals([], \pmssListManagedUsers($this->listUsersScript));
    }

    public function testManagedUsersSelectFromListRejectsInvalidRawUsernamesBeforeNormalizing(): void
    {
        $selection = \pmssManagedUsersSelectFromList([' user1 ', 'INVALID', 'user1', 'user2']);

        $this->assertEquals(0, $selection['exitCode']);
        $this->assertEquals('', $selection['username']);
        $this->assertEquals(['user1', 'user2'], $selection['users']);
    }

    public function testManagedUsersSelectFromCommandFindsSpecificManagedUser(): void
    {
        $this->writeListUsersScript("echo \"user1\\nuser2\\n\";");

        $selection = \pmssManagedUsersSelectFromCommand($this->listUsersScript, ' user2 ');

        $this->assertEquals(0, $selection['exitCode']);
        $this->assertEquals('user2', $selection['username']);
        $this->assertEquals(['user2'], $selection['users']);
    }

    public function testManagedUsersSelectFromCommandPropagatesCommandFailure(): void
    {
        $this->writeListUsersScript("echo \"user1\\n\"; exit(7);");

        list($selection) = $this->pmssCaptureStdout(function (): array {
            return \pmssManagedUsersSelectFromCommand(
                $this->listUsersScript,
                '',
                ['commandFailedMessage' => '']
            );
        });

        $this->assertEquals(7, $selection['exitCode']);
        $this->assertEquals('', $selection['username']);
        $this->assertEquals([], $selection['users']);
    }

    public function testManagedUsersSelectFromCommandRejectsInvalidUsername(): void
    {
        $this->writeListUsersScript("echo \"user1\\n\";");

        list($selection) = $this->pmssCaptureStdout(function (): array { return \pmssManagedUsersSelectFromCommand($this->listUsersScript, 'User1', ['strictInput' => true]); });

        $this->assertEquals(1, $selection['exitCode']);
        $this->assertEquals('user1', $selection['username']);
        $this->assertEquals([], $selection['users']);
    }

    public function testManagedUsersSelectFromCommandRejectsUnknownManagedUser(): void
    {
        $this->writeListUsersScript("echo \"user1\\n\";");

        list($selection) = $this->pmssCaptureStdout(function (): array { return \pmssManagedUsersSelectFromCommand($this->listUsersScript, 'user2'); });

        $this->assertEquals(1, $selection['exitCode']);
        $this->assertEquals('user2', $selection['username']);
        $this->assertEquals([], $selection['users']);
    }

    public function testManagedUsersSelectFromCommandEmitsEmptyMessageWhenRequested(): void
    {
        $this->writeListUsersScript('');

        list($selection, $output) = $this->pmssCaptureStdout(function (): array { return \pmssManagedUsersSelectFromCommand($this->listUsersScript, '', ['emitEmptyMessage' => true]); });

        $this->assertEquals(0, $selection['exitCode']);
        $this->assertEquals([], $selection['users']);
        $this->assertEquals("No users setup - nothing to do\n", $output);
    }
}
