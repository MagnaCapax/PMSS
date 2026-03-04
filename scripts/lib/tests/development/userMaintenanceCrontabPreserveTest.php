<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserMaintenanceCrontabPreserveTest extends TestCase
{
    public function testUserMaintenanceDoesNotOverwriteUserCrontab(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../update/userMaintenance.php');
        $this->assertTrue($src !== '', 'Expected to read userMaintenance.php');
        $this->assertTrue(strpos($src, 'user.crontab.default') === false, 'userMaintenance.php must not reference user crontab templates');
        $this->assertTrue(strpos($src, "pmssBuildCommand('crontab'") === false, 'userMaintenance.php must not invoke crontab for users');
        $this->assertTrue(strpos($src, 'Restoring default crontab') === false, 'userMaintenance.php must not restore default user crontabs');
        $this->assertTrue(strpos($src, '$shouldRestoreCrontab') === false, 'userMaintenance.php must not carry crontab overwrite logic');
    }
}
