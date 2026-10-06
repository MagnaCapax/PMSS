<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';
require_once dirname(__DIR__, 2).'/user/restart.php';
require_once dirname(__DIR__, 2).'/user/restartLog.php';
require_once dirname(__DIR__, 2).'/mediaStackRecoveryCommand.php';

final class RestartUserTest extends TestCase
{
    /** Write only the proc fields the selector is allowed to inspect. */
    private function process(string $root, int $pid, int $uid, string $comm, string $cmdline = '', string $state = 'S'): void
    {
        $base = $root.'/'.$pid;
        $this->pmssEnsureDir($base);
        $this->pmssWriteFile($base.'/status', "Name:\t{$comm}\nState:\t{$state} (sleeping)\nUid:\t{$uid}\t{$uid}\t{$uid}\t{$uid}\n");
        $this->pmssWriteFile($base.'/comm', $comm."\n");
        $this->pmssWriteFile($base.'/cmdline', str_replace(' ', "\0", $cmdline));
    }

    public function testProcSelectionUsesRealUidAndKeepsTheServiceManager(): void
    {
        $root = $this->pmssMakeTempDir('pmss-restart-proc-');
        $this->process($root, 101, 1001, 'rtorrent main', '/usr/bin/rtorrent');
        $this->process($root, 102, 1001, 'systemd', '/lib/systemd/systemd --user');
        $this->process($root, 103, 1001, '(sd-pam)', '(sd-pam)');
        $this->process($root, 104, 1002, 'rtorrent', '/usr/bin/rtorrent');
        $this->process($root, 105, 1001, 'systemd', '/lib/systemd/systemd');
        $this->process($root, 106, 1001, 'lighttpd', '/usr/sbin/lighttpd', 'Z');
        $this->pmssEnsureDir($root.'/107');
        $this->pmssWriteFile($root.'/107/status', "Uid:\t1001\n");
        $this->process($root, 108, 1002, 'other', '/bin/other');
        $this->pmssWriteFile($root.'/108/status', "State:\tS (sleeping)\nUid:\t1002\t1001\t1001\t1001\n");
        $this->process($root, 109, 1001, 'own', '/bin/own');
        $this->pmssWriteFile($root.'/109/status', "State:\tS (sleeping)\nUid:\t1001\t1002\t1002\t1002\n");

        $this->assertSame([101, 105, 109], \pmssRestartUserProcessPids(1001, $root));
        $this->assertSame([], \pmssRestartUserProcessPids(9999, $root));
        $this->assertSame([], \pmssRestartUserProcessPids(1001, $root.'/missing'));
        $this->assertFalse(\pmssRestartUserServiceRunning(1001, 'lighttpd', $root));
        $this->assertFalse(\pmssRestartUserServiceRunning(1002, 'lighttpd', $root));
    }

    public function testRtorrentWaitAcceptsRenamedComm(): void
    {
        $root = $this->pmssMakeTempDir('pmss-restart-rtorrent-');
        $comm = $this->pmssWriteFile($root.'/comm', "rtorrent main\n");
        $this->pmssWriteExecutableFile($root.'/pgrep', <<<'SH'
#!/bin/sh
if [ "$1" = -u ] && [ "$2" = alice ] && /bin/grep -Eq "$3" "$PMSS_TEST_RESTART_COMM"; then
    printf '201\n'
else
    exit 1
fi
SH
        );
        $this->pmssWithPathPrefixedEnv($root, ['PMSS_TEST_RESTART_COMM' => $comm], function () use ($comm): void {
            $this->assertTrue(\pmssRestartUserRtorrentRunning('alice', 0.02, 0.02));
            $this->pmssWriteFile($comm, "other\n");
            $this->assertFalse(\pmssRestartUserRtorrentRunning('alice', 0.02, 0.02));
        });
    }

    /** Run the production start function with process and launcher probes confined to this test namespace. */
    private function startWithFixture(bool $launcherSuccess, bool $rtorrentRunning, int $lighttpdVisibleAfter): array
    {
        if (!function_exists('PMSS\\Tests\\RestartUserFixture\\pmssRestartUserServicesStart')) {
            $method = new \ReflectionFunction('pmssRestartUserServicesStart');
            $lines = file($method->getFileName());
            $this->assertTrue(is_array($lines));
            $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            eval('namespace PMSS\\Tests\\RestartUserFixture;' . "\n" . $source);
        }
        $GLOBALS['PMSS_TEST_RESTART_START'] = [
            'launcherSuccess' => $launcherSuccess, 'rtorrentRunning' => $rtorrentRunning,
            'lighttpdVisibleAfter' => $lighttpdVisibleAfter, 'lighttpdChecks' => 0,
            'sleeps' => 0, 'launchers' => [],
        ];
        try {
            $home = $this->pmssMakeTempDir('pmss-restart-start-');
            $result = RestartUserFixture\pmssRestartUserServicesStart('alice', $home, 1001);
            return [$result, $GLOBALS['PMSS_TEST_RESTART_START']];
        } finally {
            unset($GLOBALS['PMSS_TEST_RESTART_START']);
        }
    }

    public function testStartUsesRunningPostStateDespiteLauncherFailure(): void
    {
        [$result, $calls] = $this->startWithFixture(false, true, 1);
        $this->assertSame(['started' => ['rtorrent', 'lighttpd'], 'failed' => []], $result);
        $this->assertSame(['startRtorrent', 'startLighttpd'], $calls['launchers']);
        $this->assertSame(0, $calls['sleeps']);
    }

    public function testStartFailsWhenLaunchersSucceedButServicesStayDown(): void
    {
        [$result, $calls] = $this->startWithFixture(true, false, PHP_INT_MAX);
        $this->assertSame(['started' => [], 'failed' => ['rtorrent', 'lighttpd']], $result);
        $this->assertSame(['startRtorrent', 'startLighttpd'], $calls['launchers']);
        $this->assertSame(26, $calls['lighttpdChecks']);
        $this->assertSame(25, $calls['sleeps']);
    }

    public function testStartWaitsForLighttpdToAppear(): void
    {
        [$result, $calls] = $this->startWithFixture(true, true, 3);
        $this->assertSame(['started' => ['rtorrent', 'lighttpd'], 'failed' => []], $result);
        $this->assertSame(3, $calls['lighttpdChecks']);
        $this->assertSame(2, $calls['sleeps']);
    }

    public function testSignalsAreWrappedInAccountShell(): void
    {
        foreach (['TERM', 'KILL'] as $signal) {
            $command = \pmssRestartUserSignalCommandBuild('alice', $signal, [123, 456]);
            $this->assertSame(\pmssBuildUserShellCommand('alice', 'kill -'.$signal.' 123 456'), $command);
            $this->assertStringContainsString("su 'alice' -c", $command);
        }
        $this->assertSame('', \pmssRestartUserSignalCommandBuild('alice', 'TERM', []));
        $this->assertSame('', \pmssRestartUserSignalCommandBuild('alice', 'TERM', [0, -1]));
        $this->assertSame('', \pmssRestartUserSignalCommandBuild('alice', 'HUP', [123]));
        foreach (['scripts/restartUser.php', 'scripts/lib/user/restart.php', 'scripts/lib/user/restartProcesses.php'] as $path) {
            $source = $this->pmssReadRepoFile($path);
            $this->assertStringNotContainsString('pgrep', $source);
            $this->assertStringNotContainsString('pkill', $source);
            $this->assertStringNotContainsString("exec('kill", $source);
        }
    }

    public function testHomeRefusalsCoverSuspensionOwnershipAndSymlink(): void
    {
        $home = $this->pmssMakeTempDir('pmss-restart-home-');
        $uid = (int) fileowner($home);
        $this->assertSame(null, \pmssRestartUserHomeRefusal('alice', $uid, $home));
        $this->pmssEnsureDir($home.'/www-disabled');
        $this->assertSame('account suspended', \pmssRestartUserHomeRefusal('alice', $uid, $home));
        rmdir($home.'/www-disabled');
        $this->assertSame('account home is missing or not owned by the account', \pmssRestartUserHomeRefusal('alice', $uid + 1, $home));
        $link = $home.'-link';
        $this->pmssCreateSymlinkOrSkip($home, $link);
        $this->assertSame('account home is missing or not owned by the account', \pmssRestartUserHomeRefusal('alice', $uid, $link));
        $this->assertSame('account home is missing or not owned by the account', \pmssRestartUserHomeRefusal('alice', $uid, $home.'/missing'));
    }

    public function testRecreateAndRestartLocksRefuseConcurrentRuns(): void
    {
        $user = 'alice';
        $recreatePath = \pmssRuntimeLockPath('pmss-userRecreate-'.$user.'.lock');
        $held = \pmssLockFileAcquire($recreatePath, true);
        $this->assertTrue(is_resource($held));
        try {
            $reason = null;
            $this->assertSame(null, \pmssRestartUserLocksAcquire($user, $reason));
            $this->assertSame('rebuild in progress', $reason);
        } finally {
            fclose($held);
        }
        $reason = null;
        $locks = \pmssRestartUserLocksAcquire($user, $reason);
        $this->assertTrue(is_array($locks));
        try {
            $priorFds = getenv(\PMSS_UPDATE_LOCK_FDS_ENV);
            \pmssRestartUserLocksPrepareChildren($locks);
            $this->assertStringContainsString('>&-', \pmssLockChildClosePrefix());
            $this->assertSame(null, \pmssRestartUserLocksAcquire($user, $reason));
            $this->assertSame('restart already running', $reason);
        } finally {
            $priorFds === false ? putenv(\PMSS_UPDATE_LOCK_FDS_ENV) : putenv(\PMSS_UPDATE_LOCK_FDS_ENV.'='.$priorFds);
            fclose($locks['restart']);
            fclose($locks['recreate']);
        }
    }

    public function testPanelAndRootUseOneUnchangedRecoveryBuilder(): void
    {
        $home = '/srv/pmss-fixture/alice';
        $expected = "cd '/srv/pmss-fixture/alice' && HOME='/srv/pmss-fixture/alice' USER='alice' LOGNAME='alice'"
            ." /bin/bash '/srv/pmss-fixture/alice/install-media-stack.sh' --start-stopped >/dev/null 2>&1"
            ." && printf %s 'pmss-media-stack-started'";
        $this->assertSame($expected, \pmssMediaStackPanelRecoveryCommandBuild($home, 'alice'));
        $panel = $this->pmssReadRepoFile('etc/skel/www/userMediaStackPanel.php');
        $this->assertStringContainsString("require_once __DIR__.'/mediaStackRecoveryCommand.php';", $panel);
        $this->assertStringNotContainsString('function pmssMediaStackPanelRecoveryCommandBuild(', $panel);
        $this->assertStringContainsString("'www/mediaStackRecoveryCommand.php'", $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'));
        $command = \pmssRestartUserMediaStackCommandBuild('alice', $home);
        $this->assertStringContainsString('/usr/bin/timeout --kill-after=10 300 '
            .\pmssBuildUserShellCommand('alice', $expected), $command);
        $this->assertOrderedStrings([
            "pmssRestartUserRootStart('startRtorrent'",
            "if (is_file(\$home.'/.media-stack-status.json'))",
            "pmssRestartUserRootStart('startLighttpd'",
        ], $this->pmssReadRepoFile('scripts/lib/user/restart.php'));
    }

    public function testAuditLogRefusesSymlinkTarget(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-restart-log-');
        $target = $this->pmssWriteFile($dir.'/target', 'unchanged');
        $this->pmssCreateSymlinkOrSkip($target, $dir.'/restartUser.log');
        $this->assertFalse(\pmssRestartUserLogWrite(['outcome' => 'test'], $dir.'/restartUser.log'));
        $this->assertSame('unchanged', file_get_contents($target));
    }

    public function testAuditLogAppendsOneJsonLineToExistingRegularFile(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-restart-log-');
        $path = $this->pmssWriteFile($dir.'/restartUser.log', "");
        $record = ['ts' => '2026-10-06T00:00:00+00:00', 'user' => 'alice', 'outcome' => 'success'];
        $this->assertTrue(\pmssRestartUserLogWrite($record, $path));
        $this->assertSame($record, json_decode(trim((string) file_get_contents($path)), true));
        $this->assertSame(1, count(file($path)));
    }
}

namespace PMSS\Tests\RestartUserFixture;

/** Stub the fixed root launcher while recording that both starts were attempted. */
function pmssRestartUserRootStart(string $launcher, string $user): bool
{
    $GLOBALS['PMSS_TEST_RESTART_START']['launchers'][] = $launcher;
    return $GLOBALS['PMSS_TEST_RESTART_START']['launcherSuccess'];
}

/** Supply the result of the existing bounded rTorrent probe. */
function pmssRestartUserRtorrentRunning(string $user): bool
{
    return $GLOBALS['PMSS_TEST_RESTART_START']['rtorrentRunning'];
}

/** Supply lighttpd visibility after a chosen number of comm checks. */
function pmssRestartUserServiceRunning(int $uid, string $comm): bool
{
    $GLOBALS['PMSS_TEST_RESTART_START']['lighttpdChecks']++;
    return $GLOBALS['PMSS_TEST_RESTART_START']['lighttpdChecks'] >= $GLOBALS['PMSS_TEST_RESTART_START']['lighttpdVisibleAfter'];
}

/** Keep the five-second production bound observable without delaying the suite. */
function usleep(int $microseconds): void
{
    $GLOBALS['PMSS_TEST_RESTART_START']['sleeps']++;
}
