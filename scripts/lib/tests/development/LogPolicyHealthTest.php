<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/logPolicyHealth.php';

/** All observations use in-memory files; no host configuration or services are touched. */
class LogPolicyHealthTest extends TestCase
{
    private function snapshot(array $changes = [], int $age = 0, int $rootBytes = 107374182400): array
    {
        $cfg = \pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        $now = (int) strtotime('2026-11-01 12:00:00');
        $version = 'git/main@'.date('Y-m-d H:i', $now - $age);
        $journal = $this->pmssReadRepoFile('etc/seedbox/config/template.journald.conf.d-pmss-limits.conf');
        $rotate = $this->pmssReadRepoFile('etc/seedbox/config/template.logrotate.rsyslog');
        $files = array_replace([
            $cfg.'/version' => $version, $cfg.'/version.completed' => $version,
            $cfg.'/template.logrotate.rsyslog' => $rotate,
            $cfg.'/template.journald.conf.d-pmss-limits.conf' => $journal,
            '/etc/logrotate.d/rsyslog' => $rotate,
            '/etc/systemd/journald.conf.d/pmss-limits.conf' => str_replace([
                '%%PMSS_JOURNALD_SYSTEM_MAX_USE%%', '%%PMSS_JOURNALD_SYSTEM_KEEP_FREE%%',
                '%%PMSS_JOURNALD_RUNTIME_MAX_USE%%', '%%PMSS_JOURNALD_RATE_LIMIT_INTERVAL%%',
                '%%PMSS_JOURNALD_RATE_LIMIT_BURST%%',
            ], ['20G', '5G', '2G', '10s', '2000'], $journal),
            '/etc/rsyslog.conf' => "# fixture\nmodule(load=\"imklog\" RatelimitInterval=\"10\" RatelimitBurst=\"2000\")\n",
        ], $changes);
        return \pmssLogPolicyHealthCollect(static function (string $path) use ($files) {
            return $files[$path] ?? null;
        }, $rootBytes, $now);
    }

    public function testCurrentManagedPoliciesAreHealthyAndPayloadContainsNoConfigContents(): void
    {
        $result = $this->snapshot();
        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['flags']);
        $this->assertSame(['logrotate' => 'ok', 'journald' => 'ok', 'rsyslog' => 'ok'], $result['policies']);
        $this->assertSame(0, $result['snapshot_age_seconds']);
        $this->assertSame('log_policy_health', $result['event']);
        $this->assertSame(false, strpos(json_encode($result), 'module('));
    }

    public function testSnapshotAgeBoundaryAndPerHostInterval(): void
    {
        $week = 7 * 86400;
        $cfg = \pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        foreach ([[0, null, false], [4 * $week, null, false], [4 * $week + 60, null, true],
            [7 * $week, null, true], [7 * $week, '8', false], [2 * $week, '1', true]] as [$age, $weeks, $stale]) {
            $result = $this->snapshot([$cfg.'/logPolicyMaxAgeWeeks' => $weeks], $age);
            $this->assertSame($stale, in_array('snapshot_stale', $result['flags'], true));
        }
    }

    public function testUnknownContentDatesCannotReportHealthy(): void
    {
        $cfg = \pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        foreach ([null, '', 'git/main', 'git/main@2026-02-30 12:00', 'git/main@2026-13-01 12:00',
            'git/main@2027-01-01 12:00', "git/main@2026-11-01 12:00\0", 'git/main@2026-11-01 12:00 junk'] as $marker) {
            $result = $this->snapshot([$cfg.'/version' => $marker, $cfg.'/version.completed' => $marker]);
            $this->assertSame(null, $result['snapshot_age_seconds']);
            $this->assertTrue(in_array('snapshot_age_unknown', $result['flags'], true));
            $this->assertSame('warn', $result['status']);
        }
    }

    public function testMissingOrDivergentManagedPoliciesWarnIndependently(): void
    {
        $cfg = \pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        foreach ([
            ['/etc/logrotate.d/rsyslog', null, 'logrotate_unknown'],
            ['/etc/logrotate.d/rsyslog', "rotate 4\n", 'logrotate_drift'],
            ['/etc/systemd/journald.conf.d/pmss-limits.conf', null, 'journald_unknown'],
            ['/etc/systemd/journald.conf.d/pmss-limits.conf', "[Journal]\nSystemMaxUse=1G\n", 'journald_drift'],
            [$cfg.'/template.logrotate.rsyslog', '', 'logrotate_unknown'],
            [$cfg.'/template.journald.conf.d-pmss-limits.conf', null, 'journald_unknown'],
            [$cfg.'/template.journald.conf.d-pmss-limits.conf', '%%UNKNOWN%%', 'journald_unknown'],
            [$cfg.'/template.logrotate.rsyslog', "\0", 'logrotate_unknown'],
            ['/etc/systemd/journald.conf.d/pmss-limits.conf', "\0", 'journald_unknown'],
        ] as [$path, $body, $flag]) {
            $result = $this->snapshot([$path => $body]);
            $this->assertTrue(in_array($flag, $result['flags'], true), $flag);
            $this->assertSame('warning', $result['level']);
        }
        $this->assertTrue(in_array('journald_unknown', $this->snapshot([], 0, 0)['flags'], true));
    }

    public function testCommentedCustomAndDuplicateKernelLimitsCannotPass(): void
    {
        $limited = 'module(load="imklog" RatelimitInterval="10" RatelimitBurst="2000")';
        foreach ([null, '', '#'.$limited, 'module(load="imklog")', $limited."\nmodule(load=\"imklog\")",
            $limited."\n".$limited, $limited."\0"] as $body) {
            $result = $this->snapshot(['/etc/rsyslog.conf' => $body]);
            $this->assertTrue($result['policies']['rsyslog'] !== 'ok');
            $this->assertSame('warn', $result['status']);
        }
    }

    public function testInvalidAgeConfigurationAndIncompleteUpdateWarn(): void
    {
        $cfg = \pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        foreach (['', '0', '-1', '1.5', '521', '99999999999999999999', "4\0", []] as $weeks) {
            $result = $this->snapshot([$cfg.'/logPolicyMaxAgeWeeks' => $weeks]);
            $this->assertTrue(in_array('age_limit_invalid', $result['flags'], true));
            $this->assertSame(4, $result['max_age_weeks']);
        }
        foreach ([null, '', 'git/main@2026-10-01 12:00', "git/main@2026-11-01 12:00\0"] as $completed) {
            $result = $this->snapshot([$cfg.'/version.completed' => $completed]);
            $this->assertTrue(in_array('update_incomplete', $result['flags'], true));
        }
    }

    public function testCronDeliveryAndRetentionAreWiredToTheReadOnlyCollector(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron',
            '0 6,18 * * *   root    /usr/bin/php /scripts/cron/logPolicyHealthSnapshot.php');
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.logrotate.pmss', '/var/log/pmss/log-policy-health.jsonl');
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/logPolicyHealthSnapshot.php', [
            'pmssPrepareCliEntrypoint(true)', 'pmssLogWritePathIsSafe($path)',
            'pmssJsonLineAppend($path, pmssLogPolicyHealthCollect())',
        ]);
    }
}
