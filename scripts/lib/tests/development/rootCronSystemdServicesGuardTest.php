<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootCronSystemdServicesGuardTest extends TestCase
{
    public function testRootCronIncludesSystemdServicesGuard(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $cronPath = $repoRoot.'/etc/seedbox/config/root.cron';
        $cron = @file_get_contents($cronPath);
        $this->assertTrue(is_string($cron) && $cron !== '', 'Expected to read '.$cronPath);

        $this->assertTrue(strpos($cron, '/scripts/cron/systemdServicesGuard.php') !== false);
        $this->assertEquals(2, substr_count($cron, '/scripts/cron/systemdServicesGuard.php'), 'Expected guard to run @reboot and periodically');
        $this->assertTrue(strpos($cron, '/var/log/pmss/systemdServicesGuard.log') !== false);
    }
}

