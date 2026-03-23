<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProcessWatchdogCronTest extends TestCase
{
    public function testRootCronSchedulesProcessWatchdog(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/root.cron',
            ['/scripts/cron/processWatchdog.sh', '*/15 * * * *'],
            'root.cron is missing: '
        );
    }

    public function testProcessWatchdogScriptContainsTwoStrikeKillFlow(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/processWatchdog.sh',
            ['ffmpeg', 'HandBrakeCLI', 'GRACE_SECONDS', 'kill -TERM', 'kill -KILL'],
            'watchdog script is missing: '
        );
    }
}
