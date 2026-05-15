<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserCrontabCleanupTest extends TestCase
{
    public function testTerminateUserClearsCrontabBeforeUserdel(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../terminateUser.php');
        $this->assertOrderedStrings(
            ["'crontab_remove'", "'userdel_initial'"],
            $src,
            'terminateUser.php should define step ',
            'terminateUser.php should clear crontab before deleting the user account: '
        );
    }
}
