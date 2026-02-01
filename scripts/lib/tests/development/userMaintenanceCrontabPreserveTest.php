<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserMaintenanceCrontabPreserveTest extends TestCase
{
    public function testUserMaintenancePreservesCustomCrontabs(): void
    {
        $src = (string) file_get_contents('scripts/lib/update/userMaintenance.php');
        $posCondition = strpos($src, 'if ($shouldRestoreCrontab)');
        $posRestore = strpos($src, 'Restoring default crontab');
        $posPreserve = strpos($src, 'preserving existing crontab');

        $this->assertTrue($posCondition !== false, 'userMaintenance.php should gate crontab restoration on $shouldRestoreCrontab');
        $this->assertTrue($posRestore !== false, 'userMaintenance.php should still restore the default crontab when needed');
        $this->assertTrue($posPreserve !== false, 'userMaintenance.php should log when preserving an existing crontab');
        $this->assertTrue($posCondition < $posRestore, 'crontab restoration should be guarded by $shouldRestoreCrontab');
        $this->assertStringContainsString('$crontabTemplateTrimmed', $src, 'userMaintenance.php should compare against the crontab template');
    }
}
