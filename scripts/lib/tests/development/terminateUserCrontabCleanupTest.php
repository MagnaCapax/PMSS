<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserCrontabCleanupTest extends TestCase
{
    public function testTerminateUserClearsCrontabBeforeUserdel(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../terminateUser.php');
        $posCrontab = strpos($src, "'crontab_remove'");
        $posUserdel = strpos($src, "'userdel_initial'");

        $this->assertTrue($posCrontab !== false, 'terminateUser.php should define a crontab_remove step');
        $this->assertTrue($posUserdel !== false, 'terminateUser.php should define a userdel_initial step');
        $this->assertTrue($posCrontab < $posUserdel, 'terminateUser.php should clear crontab before deleting the user account');
    }
}
