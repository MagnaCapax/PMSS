<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootCronSystemdServicesGuardTest extends TestCase
{
    public function testRootCronIncludesSystemdServicesGuard(): void
    {
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');

        $this->assertStringContainsAllStrings(['MAILTO=""', '/scripts/cron/systemdServicesGuard.php', '/var/log/pmss/systemdServicesGuard.log'], $cron, 'root.cron should include: ');
        $this->assertEquals(2, substr_count($cron, '/scripts/cron/systemdServicesGuard.php'), 'Expected guard to run @reboot and periodically');
    }
}
