<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PmssLogrotatePolicyTest extends TestCase
{
    public function testHighVolumeSharedPmssLogsRotateBySize(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.pmss',
            [
                '/var/log/pmss/users.log /var/log/pmss/users.jsonl /var/log/pmss/trafficStats.log',
                'daily',
                'rotate 7',
                'size 100M',
                'copytruncate',
                'su root root',
            ],
            'logrotate policy is missing: '
        );
    }

    public function testWatchdogLogsStayCoveredByCheckWildcard(): void
    {
        $policy = $this->pmssReadRepoFile('etc/seedbox/config/template.logrotate.pmss');

        $this->assertMatches(
            '#/var/log/pmss/check\*\.log\s*\{[^}]*maxsize 64M[^}]*copytruncate#s',
            $policy,
            'checkInstances and checkLighttpdInstances must stay size-bounded'
        );
    }
}
