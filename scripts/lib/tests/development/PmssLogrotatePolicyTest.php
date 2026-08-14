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

    public function testSystemStatsLogPersistsUncompressedLongTerm(): void
    {
        // The richest per-server metric log (full PSI vector + ioping + mem/disk, 5-min
        // cadence) must be retained long-term and UNCOMPRESSED so trend/forensic history
        // stays directly greppable. Regression lock against re-introducing a short
        // retention cap or compression (operator directive 2026-08-14; ADR 0044).
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/system-stats\.log\s*\{[^}]*yearly[^}]*rotate 100[^}]*maxsize 1G[^}]*nocompress[^}]*copytruncate#s',
            'system-stats.log must persist long-term, uncompressed, size-bounded'
        );
    }

    public function testDiskIostatHistoryLogsPersistUncompressedLongTerm(): void
    {
        // Parsed structured metrics — retained long-term, uncompressed (ADR 0044).
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/iostat-history\.log\s*\{[^}]*yearly[^}]*rotate 100[^}]*maxsize 1G[^}]*nocompress[^}]*create 0644 root root#s',
            'iostat-history.log must persist long-term, uncompressed, size-bounded'
        );

        // Fat raw forensic dump — uncompressed but disk-bounded (Munger backstop: a
        // pathological node must not fill /var/log). ~2GB worst-case (ADR 0044).
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/iostat-history-raw\.log\s*\{[^}]*yearly[^}]*rotate 10[^}]*maxsize 512M[^}]*nocompress[^}]*create 0644 root root#s',
            'iostat-history-raw.log must persist uncompressed but disk-bounded'
        );
    }

    public function testUpdateStep2RefreshesAndVerifiesLogrotatePolicy(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/update-step2.php', [
            "require_once __DIR__.'/../lib/update/services/logrotate.php';",
            "pmssRunProfiledCallable('Installing logrotate policies', 'pmssLogrotatePoliciesInstall', [], PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED);",
        ]);

        $this->pmssAssertRepoFileContract('scripts/lib/update/services/logrotate.php', [
            'required' => [
                "'template' => '/etc/seedbox/config/template.logrotate.pmss'",
                "'target' => '/etc/logrotate.d/pmss-update'",
                "'label' => 'PMSS update logs'",
                "install -m 0644 -T %s %s",
                "Verifying logrotate policy for ",
                "cmp -s %s %s",
                "throw new \\RuntimeException('logrotate policy install or verify failed for '.\$label.' rc='.\$rc);",
            ],
            'ordered' => [[
                'needles' => [
                    "\$installRc = runStep(",
                    "\$verifyRc = runStep(",
                    "throw new \\RuntimeException",
                ],
                'missingPrefix' => 'Missing logrotate update-step2 contract: ',
                'orderPrefix' => 'Logrotate policy install order changed near: ',
            ]],
        ]);
    }

    public function testRsyslogPolicyCapsOsLogsBySize(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.rsyslog',
            [
                '/var/log/syslog',
                '/var/log/auth.log',
                '/var/log/kern.log',
                '/var/log/daemon.log',
                'daily',
                'maxsize 500M',
                'rotate 7',
                'sharedscripts',
                '/usr/lib/rsyslog/rsyslog-rotate',
            ],
            'rsyslog logrotate policy is missing: '
        );
    }

    public function testUpdateStep2RefreshesAndVerifiesRsyslogLogrotatePolicy(): void
    {
        $this->pmssAssertRepoFileContract('scripts/lib/update/services/logrotate.php', [
            'required' => [
                "'template' => '/etc/seedbox/config/template.logrotate.rsyslog'",
                "'target' => '/etc/logrotate.d/rsyslog'",
                "'label' => 'rsyslog OS logs'",
                "Installing logrotate policy for '.\$label",
                "Verifying logrotate policy for '.\$label.' matches template",
                "cmp -s %s %s",
                "throw new \\RuntimeException('logrotate policy install or verify failed for '.\$label.' rc='.\$rc);",
            ],
            'ordered' => [[
                'needles' => [
                    "'template' => '/etc/seedbox/config/template.logrotate.pmss'",
                    "'template' => '/etc/seedbox/config/template.logrotate.rsyslog'",
                    "pmssLogrotatePolicyInstall(\$policy['template'], \$policy['target'], \$policy['label']);",
                ],
                'missingPrefix' => 'Missing rsyslog logrotate update-step2 contract: ',
                'orderPrefix' => 'Rsyslog logrotate policy order changed near: ',
            ]],
        ]);
    }
}
