<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProcessSnapshotCronTest extends TestCase
{
    public function testProcessSnapshotCronContractsRemainWired(): void
    {
        $snapshotFragments = ['pmssRunSnapshotLogTask(__FILE__,', 'pmssSnapshotWriteWarn('];
        foreach (['processSnapshot', 'quotaSnapshot', 'resourceSnapshot'] as $script) {
            $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/'.$script.'.php', $snapshotFragments);
        }
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/root.cron', ['/scripts/cron/processSnapshot.php']);
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.logrotate.pmss',
            ['/var/log/pmss/process-snapshot.log', 'weekly', 'rotate 520', 'compress', 'delaycompress', 'create 0600 root root']);
    }
}
