<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProcessSnapshotCronTest extends TestCase
{
    public function testSnapshotCronScriptsUseSharedSnapshotLogLifecycle(): void
    {
        foreach (['processSnapshot.php', 'quotaSnapshot.php', 'resourceSnapshot.php'] as $script) {
            $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/'.$script, [
                'pmssRunSnapshotLogTask(__FILE__,',
                'pmssSnapshotWriteWarn(',
            ], $script.' should use shared snapshot helpers: ');
        }
    }

    public function testRootCronSchedulesProcessSnapshots(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', '/scripts/cron/processSnapshot.php', 'root.cron should schedule processSnapshot.php');
    }

    public function testLogrotateKeepsProcessSnapshotHistoryRootOnly(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.pmss',
            ['/var/log/pmss/process-snapshot.log', 'weekly', 'rotate 8', 'create 0600 root root'],
            'logrotate policy is missing: '
        );
    }
}
