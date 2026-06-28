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
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/check\*\.log\s*\{[^}]*maxsize 64M[^}]*copytruncate#s',
            'checkInstances and checkLighttpdInstances must stay size-bounded'
        );
    }

    public function testDiskIostatHistoryLogsPersistWithAnnualRetention(): void
    {
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/iostat-history\.log\s+/var/log/pmss/iostat-history-raw\.log\s*\{[^}]*monthly[^}]*rotate 12[^}]*create 0644 root root#s',
            'iostat history logs must persist outside tmpfs with annual rotation'
        );
    }

    public function testUpdateStep2RefreshesAndVerifiesLogrotatePolicy(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/update-step2.php', [
            'required' => [
                "\$logrotateTarget = '/etc/logrotate.d/pmss-update';",
                "install -m 0644 -T %s %s",
                "Verifying PMSS logrotate policy matches template",
                "cmp -s %s %s",
                "PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED",
                "'logrotate_policy_install_or_verify_failed'",
            ],
            'ordered' => [[
                'needles' => [
                    "\$logrotateInstallRc = runStep(",
                    "\$logrotateVerifyRc = runStep(",
                    "'logrotate_policy_install_or_verify_failed'",
                ],
                'missingPrefix' => 'Missing logrotate update-step2 contract: ',
                'orderPrefix' => 'Logrotate update-step2 order changed near: ',
            ]],
        ]);
    }
}
