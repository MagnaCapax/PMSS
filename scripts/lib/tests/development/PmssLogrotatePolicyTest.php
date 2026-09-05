<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PmssLogrotatePolicyTest extends TestCase
{
    private function assertPmssLogrotateStanza(string $stanzaHead, array $required, string $messagePrefix): void
    {
        $source = $this->pmssReadRepoFile('etc/seedbox/config/template.logrotate.pmss');
        $pattern = '#'.preg_quote($stanzaHead, '#').'\s*\{(?P<body>[^}]*)\}#s';
        $this->assertTrue((bool) preg_match($pattern, $source, $matches), $messagePrefix.'missing stanza');

        $body = (string) $matches['body'];
        foreach ($required as $needle) {
            $this->assertStringContainsString((string) $needle, $body, $messagePrefix.$needle);
        }

        $this->pmssAssertStringNotContainsString('nocompress', $body, $messagePrefix.'nocompress');
        $this->assertMatches('#\n\s+compress\n\s+delaycompress\n#', $body, $messagePrefix.'compress + delaycompress');
    }

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
        $this->assertPmssLogrotateStanza('/var/log/pmss/check*.log', [
            'monthly',
            'rotate 120',
            'maxsize 64M',
            'copytruncate',
        ], 'check*.log must be compressed and ten-year bounded: ');
    }

    public function testSystemStatsLogPersistsCompressedWithBoundedRetention(): void
    {
        $this->assertPmssLogrotateStanza('/var/log/pmss/system-stats.log', [
            'yearly',
            'rotate 10',
            'maxsize 1G',
            'copytruncate',
        ], 'system-stats.log must be compressed and bounded: ');
    }

    public function testDiskIostatHistoryLogsPersistCompressedWithBoundedRetention(): void
    {
        foreach (['/var/log/pmss/iostat-history.log', '/var/log/pmss/iostat-history-raw.log'] as $stanzaHead) {
            $this->assertPmssLogrotateStanza($stanzaHead, [
                'yearly',
                'rotate 10',
                'maxsize 1G',
                'create 0644 root root',
            ], $stanzaHead.' must be compressed and bounded: ');
        }
    }

    public function testAllPerformanceMetricLogsUseCompressedBoundedRetention(): void
    {
        $stanzas = [
            '/var/log/pmss/metrics/*' => ['daily', 'rotate 3650', 'maxsize 50M', 'nocreate'],
            '/var/log/pmss/storage-health.jsonl /var/log/pmss/storageHealthSnapshot.log' => ['daily', 'rotate 3650', 'create 0600 root root'],
            '/var/log/pmss/resource-daily.log' => ['monthly', 'rotate 120', 'create 0600 root root'],
            '/var/log/pmss/quota-daily.log' => ['monthly', 'rotate 120', 'create 0600 root root'],
            '/var/log/pmss/process-snapshot.log' => ['weekly', 'rotate 520', 'maxsize 128M', 'copytruncate', 'create 0600 root root'],
        ];

        foreach ($stanzas as $stanzaHead => $required) {
            $this->assertPmssLogrotateStanza($stanzaHead, $required, $stanzaHead.' must be compressed and bounded: ');
        }
    }

    public function testMetricsWildcardRotatesIntoArchiveDirectory(): void
    {
        $this->assertPmssLogrotateStanza('/var/log/pmss/metrics/*', [
            'olddir /var/log/pmss/metrics/archive',
            'createolddir 0755 root root',
        ], 'metrics wildcard must rotate outside the live glob path: ');
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
