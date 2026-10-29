<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/quotaSnapshot.php';

class UpdateQuotasGuardTest extends TestCase
{
    public function testCronJobsRetainSharedNonBlockingLocks(): void
    {
        // All six migrated jobs keep their original names and stderr skip policy (ADR 0049).
        foreach (['updateQuotas', 'checkRootlessDocker', 'trafficIngressLog', 'trafficIngressStats', 'trafficLimits', 'trafficStats'] as $name) {
            $this->pmssAssertRepoFileContainsString('scripts/cron/'.$name.'.php', '$'.$name.'Lock = pmssCronLockAcquire(\''.$name.'\');');
        }
    }

    public function testUpdateQuotasSkipsEmptyAndInvalidUsers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/updateQuotas.php', "pmssListManagedUsers('/scripts/listUsers.php')", 'updateQuotas.php must use the shared listUsers helper');
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/cron/updateQuotas.php',
            ['$thisUser = trim($thisUser);', "if (\$thisUser === '') {", "shell_exec('/scripts/listUsers.php')"],
            'updateQuotas.php should not keep redundant user filtering: '
        );

        // Quota handling must be split into safe PHP filesystem operations and a
        // single, quoted quota invocation. Snapshot writes must use the shared
        // same-dir replacer so refreshes never leave ~/.quota missing.
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/updateQuotas.php',
            [
                '$quotaFile = "/home/{$thisUser}/.quota";',
                "pmssReadRegularFileContents(\$quotaFile) ?? ''",
                "require_once __DIR__.'/../lib/quotaSnapshot.php';",
                '$realHome === false || $realHome !== $expectedHome',
                'pmssQuotaSnapshotWrite($quotaFile, $fallbackContent)',
                'pmssQuotaSnapshotWrite($quotaFile, $content)',
                'pmssQuotaSnapshotNormalizeHumanReadableOutput($content)',
                "strpos(\$existingQuotaContent, 'Disk quotas') !== false",
                'Disk quotas for user {$thisUser} (uid 0):',
                '/dev/null      0K      0K      0K',
            ],
            'updateQuotas.php should keep quota snapshot guard: '
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/cron/updateQuotas.php',
            ['strpos($realHome, $expectedHome) !== 0', 'unlink($quotaFile)', 'file_put_contents($quotaFile'],
            'updateQuotas.php should keep safe quota snapshot writes, not: '
        );

        // The quota binary itself must be invoked once with a safely quoted username.
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/updateQuotas.php',
            ["'quota -u '.escapeshellarg(\$thisUser).' -v -s 2>&1'", 'escapeshellarg($thisUser)'],
            'updateQuotas.php should keep quoted quota command: '
        );
    }

    public function testQuotaCallsRunUnderTheSharedDeadline(): void
    {
        // Both live quota calls go through the bounded runner; a timeout is logged with the
        // account and the deadline, then stops the sweep without touching ~/.quota (GH #1183).
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/updateQuotas.php',
            [
                'pmssLockHandleExportChildCloseFds($updateQuotasLock);',
                '$quotaRun = pmssQuotaReportCapture($quotaCmd);',
                "\"quota command for {\$thisUser} exceeded its \".PMSS_QUOTA_REPORT_TIMEOUT_SECONDS.'s deadline and was killed'",
                'pmssQuotaSweepRun($users, $refreshUserQuota, array($logger, \'msg\'));',
            ],
            'updateQuotas.php should bound and stop on hung quota calls: '
        );
        $this->pmssAssertRepoFileNotContainsStrings('scripts/cron/updateQuotas.php', ['exec($quotaCmd'], 'updateQuotas.php should not run quota unbounded: ');
        $this->pmssAssertRepoFileContainsString('scripts/lib/lighttpd/watchdogQuotaProbe.php', 'pmssQuotaReportCapture(escapeshellarg($quotaPath)');
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/lighttpd/watchdogQuotaProbe.php', ["pmssLighttpdWatchdogCommandCapture('quota'"], 'watchdog quota probe should not run quota unbounded: ');
        $this->assertTrue(PMSS_QUOTA_REPORT_TIMEOUT_SECONDS + PMSS_COMMAND_TIMEOUT_KILL_AFTER_DEFAULT < 60, 'A hung call must end well inside the one-minute cron cadence');
    }

    public function testQuotaReportCaptureKillsACallThatExceedsItsDeadline(): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-quota-hang-');
        $fireLog = $binDir.'/timeout-fires.jsonl';
        $this->pmssWriteExecutableFiles($binDir, ['quota' => "#!/bin/sh\nprintf 'Disk quotas for user demo (uid 1000):\\n'\nexec sleep 30\n"]);
        $this->pmssWithPathPrefixedEnv($binDir, ['PMSS_TIMEOUT_FIRE_LOG' => $fireLog], function () use ($fireLog): void {
            $started = microtime(true);
            $result = pmssQuotaReportCapture('quota -u '.escapeshellarg('demo-user').' -v -s 2>&1', 1);
            $elapsed = microtime(true) - $started;

            $this->assertTrue($result['timed_out']);
            $this->assertTrue(in_array($result['rc'], [124, 137], true));
            $this->assertSame([], $result['lines'], 'Partial output of a killed call is not a snapshot');
            $this->assertTrue($elapsed >= 1 && $elapsed < 1 + PMSS_COMMAND_TIMEOUT_KILL_AFTER_DEFAULT + 3, 'The caller must stop waiting at deadline plus kill grace');
            $this->assertStringContainsAllStrings(['"intended_seconds":1', 'demo-user'], (string) file_get_contents($fireLog));
        });
    }

    public function testQuotaReportCapturePreservesNormalOutputAndExitCode(): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-quota-ok-');
        $this->pmssWriteExecutableFiles($binDir, ['quota' => "#!/bin/sh\nprintf 'Disk quotas for user demo (uid 1000):  \\n  /dev/sda1  10G*  9G  10G\\n'\nexit 1\n"]);
        $this->pmssWithPathPrefix($binDir, function (): void {
            $result = pmssQuotaReportCapture('quota -u demo -v -s 2>&1', 5);
            $this->assertSame(['rc' => 1, 'lines' => ['Disk quotas for user demo (uid 1000):', '  /dev/sda1  10G*  9G  10G'], 'timed_out' => false], $result);
        });
    }

    public function testQuotaReportCaptureKeepsTheCronLockFromItsChild(): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-quota-lockfd-');
        $lockPath = $binDir.'/pmss-updateQuotas.lock';
        $this->pmssWriteExecutableFiles($binDir, ['quota' => "#!/bin/sh\nls -l /proc/\$\$/fd/\n"]);
        $handle = pmssLockFileAcquire($lockPath, true);
        $this->assertTrue(is_resource($handle));
        $this->assertTrue(pmssLockHandleFdList($handle) !== [], 'The lock must hold a descriptor the child could inherit');
        try {
            $this->pmssWithPathPrefixedEnv($binDir, [PMSS_UPDATE_LOCK_FDS_ENV => null], function () use ($handle, $lockPath): void {
                pmssLockHandleExportChildCloseFds($handle);
                $result = pmssQuotaReportCapture('quota', 5);
                $this->assertFalse($result['timed_out']);
                $this->assertTrue($result['lines'] !== []);
                $this->assertStringNotContainsString($lockPath, implode("\n", $result['lines']), 'A stuck quota child must not hold the cron lock');
            });
        } finally {
            pmssLockHandleRelease($handle);
        }
    }

    public function testQuotaSweepStopsAtTheFirstTimedOutAccountAndSaysSo(): void
    {
        $visited = $logs = [];
        $log = static function (string $message) use (&$logs): void { $logs[] = $message; };
        $refresh = static function (string $user) use (&$visited): bool {
            $visited[] = $user;
            return $user !== 'bob';
        };

        $this->assertSame(2, pmssQuotaSweepRun(['alice', 'bob', 'carol', 'dave'], $refresh, $log));
        $this->assertSame(['alice', 'bob'], $visited, 'No further quota call may start after a hung one');
        $this->assertSame(['Quota sweep stopped after a hung quota call for bob; 2 remaining account(s) keep their previous snapshot until the next run'], $logs);

        $visited = $logs = [];
        $this->assertSame(0, pmssQuotaSweepRun(['alice', 'carol'], $refresh, $log));
        $this->assertSame(['alice', 'carol'], $visited);
        $this->assertSame([], $logs);
    }

    public function testPreviousSnapshotReadRejectsSpecialFilesAndPreservesBytes(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-quota-read-');
        $content = "Disk quotas for user sample (uid 1000):\r\n  /dev/example 1K 2K 3K\n";
        file_put_contents($directory.'/regular', $content);
        file_put_contents($directory.'/empty', '');
        $this->assertTrue(symlink($directory.'/regular', $directory.'/link'));
        $this->assertTrue(symlink($directory.'/missing', $directory.'/dangling'));
        $this->assertTrue(posix_mkfifo($directory.'/fifo', 0600));

        // Execute only the actual snapshot read/recognition block, never cron
        // setup or quota commands. Bound the child so a FIFO regression fails.
        $source = file_get_contents($this->pmssRepoPath('scripts/cron/updateQuotas.php'));
        $this->assertSame(1, preg_match('/    \$existingQuotaContent = .*?;\n    \$hasExistingSnapshot = .*?;/s', $source, $matches));
        $paths = [$directory.'/regular', $directory.'/empty', $directory.'/missing',
            $directory, $directory.'/link', $directory.'/dangling', $directory.'/fifo'];
        $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).';'
            .'$results = []; foreach ('.var_export($paths, true).' as $quotaFile) {'
            .$matches[0].'$results[] = [$existingQuotaContent, $hasExistingSnapshot]; }'
            .'echo json_encode($results);';
        $result = $this->pmssExecShellCommand('timeout --kill-after=2s 5s '.$this->pmssBuildInlinePhpCommand($script));

        $this->assertSame(0, $result['rc'], 'Snapshot reads must finish without opening the FIFO');
        $this->assertSame(array_merge([[$content, true]], array_fill(0, 6, ['', false])), json_decode($result['output'], true));
        $this->assertSame($content, file_get_contents($directory.'/regular'));
        $this->assertSame('fifo', filetype($directory.'/fifo'));
    }
}
