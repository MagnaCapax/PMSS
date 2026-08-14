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
        // Watchdog/health-check history is observability: kept forever (rotate 9999),
        // uncompressed, but size-bounded per-file by maxsize (ADR 0044).
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/check\*\.log\s*\{[^}]*rotate 9999[^}]*maxsize 64M[^}]*nocompress[^}]*copytruncate#s',
            'check*.log must persist forever (rotate 9999), uncompressed, size-bounded'
        );
    }

    public function testSystemStatsLogPersistsUncompressedForever(): void
    {
        // The richest per-server metric log (full PSI vector + ioping + mem/disk, 5-min
        // cadence) must be retained AS LONG AS POSSIBLE (rotate 9999 = effectively
        // unlimited) and UNCOMPRESSED so trend/forensic history stays directly greppable.
        // Regression lock against re-introducing ANY retention cap or compression
        // (operator directive 2026-08-14 "keep them all on server side"; ADR 0044).
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/system-stats\.log\s*\{[^}]*rotate 9999[^}]*nocompress[^}]*copytruncate#s',
            'system-stats.log must persist forever (rotate 9999), uncompressed'
        );
    }

    public function testDiskIostatHistoryLogsPersistUncompressedForever(): void
    {
        // Both parsed metrics and the fat raw forensic dump retained forever, uncompressed
        // (ADR 0044). maxsize stays as a non-deleting file-splitter, not a retention cap.
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/iostat-history\.log\s*\{[^}]*rotate 9999[^}]*nocompress[^}]*create 0644 root root#s',
            'iostat-history.log must persist forever (rotate 9999), uncompressed'
        );
        $this->pmssAssertRepoFileMatches(
            'etc/seedbox/config/template.logrotate.pmss',
            '#/var/log/pmss/iostat-history-raw\.log\s*\{[^}]*rotate 9999[^}]*nocompress[^}]*create 0644 root root#s',
            'iostat-history-raw.log must persist forever (rotate 9999), uncompressed'
        );
    }

    public function testAllPerformanceMetricLogsPersistUncappedUncompressed(): void
    {
        // Operator directive 2026-08-14: ALL performance/storage metric logs kept as long
        // as possible, uncompressed — not just the iostat/system-stats pair (ADR 0044).
        // Regression lock the rest of the metric set so a short cap cannot creep back in.
        foreach ([
            '/var/log/pmss/metrics/*',
            '/var/log/pmss/storage-health.jsonl /var/log/pmss/storageHealthSnapshot.log',
            '/var/log/pmss/resource-daily.log',
            '/var/log/pmss/quota-daily.log',
            '/var/log/pmss/process-snapshot.log',
        ] as $stanzaHead) {
            $pattern = '#'.preg_quote($stanzaHead, '#').'\s*\{[^}]*rotate 9999[^}]*nocompress#s';
            $this->pmssAssertRepoFileMatches(
                'etc/seedbox/config/template.logrotate.pmss',
                $pattern,
                $stanzaHead.' must persist uncapped (rotate 9999) and uncompressed'
            );
        }
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
