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
        $this->assertTrue(strpos($script, 'ffmpeg') !== false, 'watchdog should target ffmpeg');
        $this->assertTrue(strpos($script, 'HandBrakeCLI') !== false, 'watchdog should target HandBrakeCLI');
        $this->assertTrue(strpos($script, 'GRACE_SECONDS') !== false, 'watchdog should implement strike grace window');
        $this->assertTrue(strpos($script, 'kill -TERM') !== false, 'watchdog should send SIGTERM first');
        $this->assertTrue(strpos($script, 'kill -KILL') !== false, 'watchdog should escalate to SIGKILL when needed');
    }
}
