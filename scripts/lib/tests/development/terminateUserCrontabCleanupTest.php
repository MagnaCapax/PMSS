<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserCrontabCleanupTest extends TestCase
{
    public function testTerminateUserClearsCrontabBeforeUserdel(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ["'crontab_remove'", "'userdel_initial'"],
            'terminateUser.php should define step ',
            'terminateUser.php should clear crontab before deleting the user account: '
        );
    }
}
