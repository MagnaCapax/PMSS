<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageHealthCronTest extends TestCase
{
    public function testCronAndLogrotatePoliciesCoverStorageHealthSnapshots(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/root.cron',
            '0 6,18 * * *   root    /scripts/cron/storageHealthSnapshot.php >> /var/log/pmss/storageHealthSnapshot.log 2>&1',
            'root.cron should schedule storageHealthSnapshot.php twice daily'
        );

        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.pmss',
            [
                '/var/log/pmss/storage-health.jsonl /var/log/pmss/storageHealthSnapshot.log',
                'daily',
                'rotate 30',
                'create 0600 root root',
            ],
            'logrotate policy is missing: '
        );
    }
}
