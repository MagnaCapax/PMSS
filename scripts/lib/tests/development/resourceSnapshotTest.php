<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ResourceSnapshotTest extends TestCase
{
    public function testRootCronSchedulesResourceJobs(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/root.cron',
            [
                '/scripts/cron/resourceLog.php',
                '/scripts/cron/resourceStats.php',
                '/scripts/cron/resourceSnapshot.php',
            ]
        );
    }

    public function testLogrotateKeepsResourceDailyRootOnly(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.pmss',
            ['/var/log/pmss/resource-daily.log', 'rotate 24', 'create 0600 root root']
        );
    }
}
