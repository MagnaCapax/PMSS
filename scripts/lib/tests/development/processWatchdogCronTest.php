<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProcessWatchdogCronTest extends TestCase
{
    public function testRootCronSchedulesProcessWatchdog(): void
    {
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');
        $this->assertTrue(strpos($cron, '/scripts/cron/processWatchdog.sh') !== false, 'root.cron should schedule processWatchdog.sh');
        $this->assertTrue(strpos($cron, '*/15 * * * *') !== false, 'processWatchdog should run every 15 minutes');
    }

    public function testProcessWatchdogScriptContainsTwoStrikeKillFlow(): void
    {
        $script = $this->pmssReadRepoFile('scripts/cron/processWatchdog.sh');
        $this->assertStringContainsAllStrings(['ffmpeg', 'HandBrakeCLI', 'GRACE_SECONDS', 'kill -TERM', 'kill -KILL'], $script, 'watchdog script is missing: ');
    }
}
