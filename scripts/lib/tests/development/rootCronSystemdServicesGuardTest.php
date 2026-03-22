<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootCronSystemdServicesGuardTest extends TestCase
{
    public function testRootCronIncludesSystemdServicesGuard(): void
    {
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');

        $this->assertTrue(strpos($cron, 'MAILTO=""') !== false, 'root.cron should suppress cron mail');
        $this->assertTrue(strpos($cron, '/scripts/cron/systemdServicesGuard.php') !== false);
        $this->assertEquals(2, substr_count($cron, '/scripts/cron/systemdServicesGuard.php'), 'Expected guard to run @reboot and periodically');
        $this->assertTrue(strpos($cron, '/var/log/pmss/systemdServicesGuard.log') !== false);
    }
}
