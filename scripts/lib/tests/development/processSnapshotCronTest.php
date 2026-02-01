<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProcessSnapshotCronTest extends TestCase
{
    public function testRootCronSchedulesProcessSnapshots(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $cron = (string) file_get_contents($repoRoot.'/etc/seedbox/config/root.cron');
        $this->assertTrue(strpos($cron, '/scripts/cron/processSnapshot.php') !== false, 'root.cron should schedule processSnapshot.php');
    }

    public function testLogrotateKeepsProcessSnapshotHistoryRootOnly(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $policy = (string) file_get_contents($repoRoot.'/etc/seedbox/config/template.logrotate.pmss');
        $this->assertTrue(strpos($policy, '/var/log/pmss/process-snapshot.log') !== false, 'logrotate policy should include process-snapshot.log');
        $this->assertTrue(strpos($policy, 'weekly') !== false, 'process snapshot log should rotate weekly');
        $this->assertTrue(strpos($policy, 'rotate 8') !== false, 'process snapshot log should keep 8 rotations');
        $this->assertTrue(strpos($policy, 'create 0600 root root') !== false, 'process snapshot log should remain root-only');
    }
}

