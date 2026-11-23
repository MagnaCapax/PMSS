<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/quota.php';

/**
 * quotaFix switches quota off, recounts, then switches it on. When the update step's deadline kills
 * it in between, quota must not stay off for every account until the next update (GH #970).
 */
final class QuotaFixEnforcementRestoreTest extends TestCase
{
    public function testRestoreRunsQuotaonAndClearsOnlyStaleCheckFiles(): void
    {
        $mount = realpath($this->pmssMakeTempDir('pmss-quota-restore-mount-'));
        $this->pmssWriteFiles($mount, ['aquota.user.new' => 'half', 'aquota.group.new' => 'half', 'aquota.user' => 'live', 'keep.txt' => 'x']);
        $commands = [];
        $logs = [];

        $result = \pmssQuotaFixEnforcementRestore(function (string $command) use (&$commands): array {
            $commands[] = $command;
            return ['rc' => 0, 'stdout' => "quotaon: ok\n", 'stderr' => ''];
        }, function (string $message) use (&$logs): void {
            $logs[] = $message;
        }, $mount, 0);

        $this->assertTrue($result['ok']);
        $this->assertSame(['quotaon -av'], $commands);
        $this->assertFalse(file_exists($mount.'/aquota.user.new'));
        $this->assertFalse(file_exists($mount.'/aquota.group.new'));
        $this->assertSame('live', file_get_contents($mount.'/aquota.user'), 'the live quota file is never touched');
        $this->assertTrue(file_exists($mount.'/keep.txt'));
        $this->assertStringContainsString('interrupted after quotaoff', $logs[0]);
        $this->assertStringContainsString('Quotas re-enabled', end($logs));
    }

    public function testRestoreReportsAFailedQuotaonWithoutClaimingSuccess(): void
    {
        $mount = realpath($this->pmssMakeTempDir('pmss-quota-restore-fail-'));
        $logs = [];

        $result = \pmssQuotaFixEnforcementRestore(static function (): array {
            return ['rc' => 5, 'stdout' => '', 'stderr' => "Cannot find quota file\n"];
        }, function (string $message) use (&$logs): void {
            $logs[] = $message;
        }, $mount, 0);

        $this->assertFalse($result['ok']);
        $this->assertSame(5, $result['rc']);
        $this->assertStringContainsString('quotaon failed after interruption (rc=5)', end($logs));
        $this->assertStringNotContainsString('Quotas re-enabled', implode("\n", $logs));
    }

    public function testRestoreRefusesAnUnsafeMountPointButStillRunsQuotaon(): void
    {
        $commands = [];
        \pmssQuotaFixEnforcementRestore(function (string $command) use (&$commands): array {
            $commands[] = $command;
            return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
        }, static function (string $message): void {
        }, "/tmp/\nbad", 0);

        $this->assertSame(['quotaon -av'], $commands, 'a refused cleanup path must not keep quota off');
    }

    /** Run the arm helper in a child process; the restore seam prints a marker. */
    private function armedChild(string $body, string $restoreBody = 'echo "RESTORED\n";'): array
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-restore-child-');
        $script = $this->pmssWriteFile($dir.'/child.php', implode("\n", [
            '<?php',
            'require_once '.var_export(dirname(__DIR__, 2).'/quota/restore.php', true).';',
            '$disarm = pmssQuotaFixRestoreArm(static function () { '.$restoreBody.' });',
            $body,
            '',
        ]));

        return $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script));
    }

    public function testArmedRunRestoresOnFatalExit(): void
    {
        $result = $this->armedChild('exit(3);');

        $this->assertSame(3, $result['rc']);
        $this->assertSame(1, substr_count($result['output'], 'RESTORED'));
    }

    public function testDisarmedRunDoesNotRestoreAgain(): void
    {
        $result = $this->armedChild('$disarm(); exit(0);');

        $this->assertSame(0, $result['rc']);
        $this->assertStringNotContainsString('RESTORED', $result['output']);
    }

    public function testTerminationSignalRestoresBeforeTheProcessEnds(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->assertTrue(true, 'without pcntl only the shutdown path exists, covered above');
            return;
        }
        // The deadline kill is SIGTERM; PHP would otherwise die on it without running shutdown functions.
        $result = $this->armedChild('posix_kill(getmypid(), SIGTERM); usleep(500000); echo "NOT_REACHED\n";');

        $this->assertSame(128 + 15, $result['rc']);
        $this->assertSame(1, substr_count($result['output'], 'RESTORED'));
        $this->assertStringNotContainsString('NOT_REACHED', $result['output']);
    }

    public function testQuotaFixArmsBeforeQuotaoffAndDisarmsAfterQuotaon(): void
    {
        $script = $this->pmssReadRepoFile('scripts/util/quotaFix.php');
        $this->assertOrderedStrings([
            '$quotaRestoreDisarm = pmssQuotaFixRestoreArm();',
            "'quotaoff -av'",
            "'[quotaFix] Re-enabling quotas', 'quotaon -av'",
            '$quotaRestoreDisarm();',
        ], $script);
    }
    public function testInterruptionSignalsOtherThanSigtermAlsoRestore(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->assertTrue(true, 'without pcntl only the shutdown path exists');
            return;
        }
        foreach (['SIGINT' => 2, 'SIGHUP' => 1] as $name => $number) {
            $result = $this->armedChild('posix_kill(getmypid(), '.$name.'); usleep(500000); echo "NOT_REACHED\n";');

            $this->assertSame(128 + $number, $result['rc'], $name);
            $this->assertSame(1, substr_count($result['output'], 'RESTORED'), $name);
            $this->assertStringNotContainsString('NOT_REACHED', $result['output'], $name);
        }
    }

    public function testASecondSignalDuringTheRestoreDoesNotCutItShort(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->assertTrue(true, 'without pcntl only the shutdown path exists');
            return;
        }
        // The update step's watchdog and its coreutils backstop can each signal; the restore must finish.
        $result = $this->armedChild(
            'posix_kill(getmypid(), SIGTERM); usleep(500000);',
            'posix_kill(getmypid(), SIGTERM); posix_kill(getmypid(), SIGHUP); usleep(200000); echo "RESTORED\n";'
        );

        $this->assertSame(143, $result['rc']);
        $this->assertSame(1, substr_count($result['output'], 'RESTORED'));
    }

    public function testRestoreLeavesNoCompletionStampSoTheNextUpdateRecounts(): void
    {
        $dir = realpath($this->pmssMakeTempDir('pmss-quota-restore-stamp-'));
        $stamp = $dir.'/quota-recalculation.complete';
        $fstab = $this->pmssWriteFile($dir.'/fstab', "/dev/md2 /home ext4 defaults,usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1 0 2\n");
        $quiet = static function (string $message): void {
        };

        \pmssQuotaFixEnforcementRestore(static function (): array {
            return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
        }, $quiet, $dir, 0);

        $this->assertFalse(file_exists($stamp), 'the restore must not claim a completed recount');
        // Even with quota back on for both types, a missing stamp keeps the next update's recount (ADR 0140).
        $this->assertTrue(\pmssQuotaHomeRecalculationNeeded(static function (): array {
            return ['rc' => 2, 'stdout' => '', 'stderr' => ''];
        }, $quiet, $fstab, $stamp));
    }

    public function testRestoreOnlyTouchesCheckFilesDirectlyUnderTheMount(): void
    {
        $mount = realpath($this->pmssMakeTempDir('pmss-quota-restore-scope-'));
        // Another account's directory and a nested path hold look-alike names; they are not the mount's check files.
        $this->pmssWriteFiles($mount, ['aquota.user.new' => 'x', 'otheraccount/aquota.user.new' => 'theirs', 'a/b/aquota.group.new' => 'nested']);
        $commands = [];

        \pmssQuotaFixEnforcementRestore(function (string $command) use (&$commands): array {
            $commands[] = $command;
            return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
        }, static function (string $message): void {
        }, $mount, 0);

        $this->assertFalse(file_exists($mount.'/aquota.user.new'));
        $this->assertSame('theirs', file_get_contents($mount.'/otheraccount/aquota.user.new'));
        $this->assertSame('nested', file_get_contents($mount.'/a/b/aquota.group.new'));
        $this->assertSame(['quotaon -av'], $commands);
    }

    public function testRestoreRefusesASymlinkedMountPointButStillReEnablesQuota(): void
    {
        $parent = realpath($this->pmssMakeTempDir('pmss-quota-restore-real-'));
        $real = $this->pmssEnsureDir($parent.'/mount');
        $this->pmssWriteFile($real.'/aquota.user.new', 'keep');
        $link = $parent.'/link';
        $this->assertTrue(symlink($real, $link), 'fixture symlink');
        $commands = [];
        $logs = [];

        \pmssQuotaFixEnforcementRestore(function (string $command) use (&$commands): array {
            $commands[] = $command;
            return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
        }, function (string $message) use (&$logs): void {
            $logs[] = $message;
        }, $link, 0);

        $this->assertSame('keep', file_get_contents($real.'/aquota.user.new'), 'nothing is deleted through a link');
        $this->assertSame(['quotaon -av'], $commands);
        $this->assertStringContainsString('refusing quota cleanup outside stable mount point', implode("\n", $logs));
    }

    public function testRestoreIsIdempotentOnARerun(): void
    {
        $mount = realpath($this->pmssMakeTempDir('pmss-quota-restore-rerun-'));
        $this->pmssWriteFile($mount.'/aquota.user.new', 'half');
        $commands = [];
        $runner = function (string $command) use (&$commands): array {
            $commands[] = $command;
            return ['rc' => 0, 'stdout' => '', 'stderr' => ''];
        };
        $quiet = static function (string $message): void {
        };

        $first = \pmssQuotaFixEnforcementRestore($runner, $quiet, $mount, 0);
        $second = \pmssQuotaFixEnforcementRestore($runner, $quiet, $mount, 0);

        $this->assertTrue($first['ok'] && $second['ok']);
        $this->assertSame(['quotaon -av', 'quotaon -av'], $commands);
        $this->assertSame([], glob($mount.'/aquota*new'));
    }

    public function testExistingHostsLoadTheRestoreThroughTheUnchangedQuotaFacade(): void
    {
        // update-step2 and install.sh reach quotaFix through the facade; an older caller must still resolve it.
        $this->assertTrue(function_exists('pmssQuotaFixRestoreArm'));
        $this->assertTrue(function_exists('pmssQuotaFixEnforcementRestore'));
        $this->assertTrue(function_exists('pmssQuotaFixCheckCommands'), 'existing facade functions are still loaded');
        $this->assertStringContainsString("require_once __DIR__.'/../../quota/restore.php';", $this->pmssReadRepoFile('scripts/lib/update/services/quota.php'));
    }
}
