<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProcessSnapshotCronTest extends TestCase
{
    public function testProcessSnapshotCronContractsRemainWired(): void
    {
        $snapshotHelperCase = ['required' => ['pmssRunSnapshotLogTask(__FILE__,', 'pmssSnapshotWriteWarn(']];
        $this->pmssAssertRepoFileContractCases([
            'scripts/cron/processSnapshot.php' => $snapshotHelperCase,
            'scripts/cron/quotaSnapshot.php' => $snapshotHelperCase,
            'scripts/cron/resourceSnapshot.php' => $snapshotHelperCase,
            'etc/seedbox/config/root.cron' => ['required' => ['/scripts/cron/processSnapshot.php']],
            'etc/seedbox/config/template.logrotate.pmss' => [
                'required' => ['/var/log/pmss/process-snapshot.log', 'weekly', 'rotate 9999', 'create 0600 root root'],
            ],
        ]);
    }
}
