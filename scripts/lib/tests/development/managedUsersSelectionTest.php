<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/userLifecycle.php';

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

    public function testManagedUsersSelectFromCommandRejectsInvalidUsername(): void
    {
        $this->writeListUsersScript("echo \"user1\\n\";");

        ob_start();
        $selection = \pmssManagedUsersSelectFromCommand($this->listUsersScript, 'User1', ['strictInput' => true]);
        ob_end_clean();

        $this->assertEquals(1, $selection['exitCode']);
        $this->assertEquals('user1', $selection['username']);
        $this->assertEquals([], $selection['users']);
    }

    public function testManagedUsersSelectFromCommandRejectsUnknownManagedUser(): void
    {
        $this->writeListUsersScript("echo \"user1\\n\";");

        ob_start();
        $selection = \pmssManagedUsersSelectFromCommand($this->listUsersScript, 'user2');
        ob_end_clean();

        $this->assertEquals(1, $selection['exitCode']);
        $this->assertEquals('user2', $selection['username']);
        $this->assertEquals([], $selection['users']);
    }

    public function testManagedUsersSelectFromCommandEmitsEmptyMessageWhenRequested(): void
    {
        $this->writeListUsersScript('');

        ob_start();
        $selection = \pmssManagedUsersSelectFromCommand($this->listUsersScript, '', ['emitEmptyMessage' => true]);
        $output = ob_get_clean();

        $this->assertEquals(0, $selection['exitCode']);
        $this->assertEquals([], $selection['users']);
        $this->assertEquals("No users setup - nothing to do\n", $output);
    }
}
