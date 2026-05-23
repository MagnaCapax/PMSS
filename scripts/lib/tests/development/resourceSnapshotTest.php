<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ResourceSnapshotTest extends TestCase
{
    public function testSnapshotCronDelegatesToSharedResourceReaders(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/resourceSnapshot.php', ['readSnapshotMetricsFromPath($dataPath)', "collectWindowResultsFromData(\$dataLines, ['day' => \$threshold])"]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/cron/resourceSnapshot.php', ['@unserialize($raw)', 'new ResourceStatsAccumulator(']);
    }

    public function testRootCronSchedulesResourceJobs(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/root.cron',
            [
                '*/5 * * * *   root    /scripts/cron/resourceLog.php >> /var/log/pmss/resourceLog.log 2>&1',
                '0 0 * * *   root    /scripts/cron/resourceSnapshot.php >/dev/null 2>&1',
                '25,55 * * * *   root    /bin/sleep 30; /scripts/cron/resourceStats.php >> /var/log/pmss/resourceStats.log 2>&1',
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
