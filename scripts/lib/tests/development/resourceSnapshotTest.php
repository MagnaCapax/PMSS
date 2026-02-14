<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ResourceSnapshotTest extends TestCase
{
    public function testRootCronSchedulesResourceJobs(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $cron = (string) file_get_contents($repoRoot.'/etc/seedbox/config/root.cron');
        $this->assertTrue(strpos($cron, '/scripts/cron/resourceLog.php') !== false);
        $this->assertTrue(strpos($cron, '/scripts/cron/resourceStats.php') !== false);
        $this->assertTrue(strpos($cron, '/scripts/cron/resourceSnapshot.php') !== false);
    }

    public function testLogrotateKeepsResourceDailyRootOnly(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $policy = (string) file_get_contents($repoRoot.'/etc/seedbox/config/template.logrotate.pmss');
        $this->assertTrue(strpos($policy, '/var/log/pmss/resource-daily.log') !== false);
        $this->assertTrue(strpos($policy, 'rotate 24') !== false);
        $this->assertTrue(strpos($policy, 'create 0600 root root') !== false);
    }
}
