<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/terminationCleanup.php';

final class TerminateUserContractTest extends TestCase
{
    public function testPublicCertificateLineageDeletionIsBoundToTheRetiredAccount(): void
    {
        $this->assertSame(null, \pmssTerminateUserCertLineageDeleteCommand('../fixtur01.node.example.com'));
        $this->assertSame(null, \pmssTerminateUserCertLineageDeleteCommand('localhost'));
        $this->assertSame("certbot delete --non-interactive --cert-name 'fixtur01.node.example.com'",
            \pmssTerminateUserCertLineageDeleteCommand('fixtur01.node.example.com'));
        $source = $this->pmssReadRepoFile('scripts/terminateUser.php');
        $this->assertTrue(strpos($source, "'expire_account_before_kill'") < strpos($source, "'kill_processes_initial'"));
        $this->assertTrue(strpos($source, 'pmssTerminateUserCertLineageDeleteCommand') > strpos($source, 'kill_processes_final'));
        $this->assertStringContainsString('pmssUserAccountLookup($username) === null', $source);
        $this->assertStringContainsString('pmssNginxUserMcxHostname($billingServiceId)', $source);
    }
    public function testTerminateUserConfirmationLoopHandlesEof(): void
    {
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', ['required' => ['confirmation input unavailable (EOF)', 'Unable to read confirmation input (EOF)']]);
    }

    public function testTerminateUserReleasesEveryToggleAppPortAndCrashState(): void
    {
        // Every port service the toggle-app watchdog reserves must be released on termination.
        $catalog = require $this->pmssRepoPath('etc/skel/www/toggleAppCatalog.php');
        $services = array();
        foreach ($catalog as $app => $row) $services = array_merge($services, array($app), $row['extraPortServices']);
        $this->assertSame(array('slskd', 'slskd-peer', 'copyparty', 'qui', 'scryer'), $services);
        $this->pmssAssertRepoFileContainsString('scripts/terminateUser.php', 'array_merge(array($toggleApp), $row[\'extraPortServices\'])');
        $this->pmssAssertRepoFileContainsString('scripts/terminateUser.php', "escapeshellarg(\$service)");
        $this->pmssAssertRepoFileContainsString('scripts/terminateUser.php', '/var/lib/pmss/toggle-apps/{$username}-{$toggleApp}.json');
    }

    public function testTerminateUserInvokesSystemdRevertOnSlice(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/terminateUser.php',
            'systemctl revert',
            'terminateUser.php should revert user slice properties'
        );
    }

    public function testTerminateUserClearsCrontabBeforeUserdel(): void
    {
        $this->assertSame(['crontab_remove', 'crontab_spool_remove', 'remove_tmux_socket', 'remove_screen_socket', 'userdel_initial'],
            array_column(\pmssTerminateUserInitialRemovalSteps('user1234', 1234), 0));
    }

    public function testTerminateUserSystemdAndTmuxCleanupOrder(): void
    {
        $source = $this->pmssReadRepoExecutableSource('scripts/terminateUser.php');
        $this->assertFalse((bool) preg_match('/if\s*\(\s*false\s*\)/', $source), 'cleanup steps must be reachable');
        $this->assertOrderedStrings([
            "'disable_linger'",
            'pmssTerminateUserInitialRemovalSteps($username, $uid)',
            'pmssTerminateUserPurgeDirectorySteps($label, $path)',
            'pmssTerminateUserFinalRemovalSteps($username, $uid, $killUserCommand)',
            'pmssTerminateUserClearPreinstallState($username, $dryRun, pmssUserAccountLookup($username) !== null)',
            'pmssTerminateUserCompletion($username, $purgePaths, $purgeResults, $dryRun)',
        ], $source);
        $lingerStep = substr($source, strpos($source, "'disable_linger'"), 280);
        $this->assertStringContainsString('timeout --kill-after=5s 45s loginctl disable-linger', $lingerStep);
        $this->assertStringNotContainsString('|| true', $lingerStep);
        $initial = array_column(\pmssTerminateUserInitialRemovalSteps('user1234', 1234), 0);
        $final = array_column(\pmssTerminateUserFinalRemovalSteps('user1234', 1234, 'killall -9 -u user1234'), 0);
        $this->assertSame(['remove_tmux_socket', 'remove_screen_socket', 'userdel_initial'], array_slice($initial, -3));
        $this->assertSame(['kill_processes_final', 'reset_failed_user_manager', 'userdel_groupdel_final'], array_slice($final, 0, 3));
    }

    /** Execute the generated guard through a runuser stub, including inner rm failures. */
    public function testAccountSocketCleanupUsesNoPamRunnerAndPreservesExitCodes(): void
    {
        $root = $this->pmssMakeTempDir('pmss-terminate-shell-');
        $bin = $root.'/bin';
        mkdir($bin);
        $this->pmssWriteExecutableFile($bin.'/runuser', <<<'SH'
#!/bin/sh
[ "$1" = --pty ] && shift
[ "$1" = -u ] && [ "$2" = user1234 ] && [ "$3" = -- ] || exit 97
shift 3
[ "$1" = sh ] && [ "$2" = -c ] || exit 98
shift 2
[ -t 0 ] && exit 99
exec sh -c "$1"
SH
        );
        $target = $root.'/socket';
        $command = \pmssTerminateUserAccountSocketRemoveCommand('user1234', $target);
        $this->assertStringContainsString('env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin ', $command);
        $stubbed = str_replace('PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'PATH='.escapeshellarg($bin.':/usr/sbin:/usr/bin:/sbin:/bin'), $command);
        file_put_contents($target, 'socket');
        $this->assertSame(0, $this->pmssExecShellCommand($stubbed, [], '')['rc']);
        $this->assertFalse(file_exists($target));
        $this->assertSame(0, $this->pmssExecShellCommand($stubbed, [], '')['rc'], 'absent path is an idempotent no-op');
        mkdir($target);
        file_put_contents($target.'/child', 'keep');
        // A failing rm must surface through runuser and the outer timeout.
        $this->pmssWriteExecutableFile($bin.'/rm', "#!/bin/sh\nexit 1\n");
        $this->assertSame(1, $this->pmssExecShellCommand($stubbed, [], '')['rc'], 'inner command failure must not be masked');
        $this->assertTrue(is_file($target.'/child'));
    }

    public function testNoPamCommandCompletesFromInteractiveTerminalWithoutInheritedEnvironment(): void
    {
        $root = $this->pmssMakeTempDir('pmss-terminate-pty-');
        $stub = $root.'/runuser';
        $effect = $root.'/effect';
        $this->pmssWriteExecutableFile($stub, "#!/bin/sh\n[ \"\$1\" = --pty ] && shift\n[ \"\$1\" = -u ] && [ \"\$2\" = user1234 ] && [ \"\$3\" = -- ] || exit 97\nshift 3\n[ \"\$1\" = sh ] && [ \"\$2\" = -c ] || exit 98\nshift 2\n[ -t 0 ] && exit 99\nexec sh -c \"\$1\"\n");
        $built = \pmssBuildUserShellNoPamCommand('user1234', '[ -z "$PMSS_TEST_SECRET" ] && touch '.escapeshellarg($effect));
        $this->assertSame(0, strpos($built, 'env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin '));
        $command = str_replace('PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'PATH='.escapeshellarg($root.':/usr/sbin:/usr/bin:/sbin:/bin'), $built);
        $result = $this->pmssExecShellCommand('PMSS_TEST_SECRET=leak timeout --kill-after=1s 5s script -qec '.escapeshellarg($command).' /dev/null', [], '');
        $this->assertSame(0, $result['rc'], 'pseudo-terminal command must complete before timeout');
        $this->assertTrue(is_file($effect), 'inner command must run with a clean environment');
    }

    /** Probe each util-linux capability in a fresh PHP process so detection runs once. */
    public function testNoPamCommandSupportsRunuserWithAndWithoutPty(): void
    {
        $root = $this->pmssMakeTempDir('pmss-runuser-capability-');
        $bin = $root.'/bin';
        mkdir($bin);
        $this->pmssWriteExecutableFile($bin.'/runuser', <<<'SH'
#!/bin/sh
if [ "$1" = --help ]; then
    [ "$PMSS_TEST_PTY" = 1 ] && echo '  --pty'
    exit 0
fi
[ "$1" = --pty ] && shift
[ "$1" = -u ] && [ "$2" = user1234 ] && [ "$3" = -- ] || exit 97
shift 3
[ "$1" = sh ] && [ "$2" = -c ] || exit 98
shift 2
[ -t 0 ] && exit 99
exec sh -c "$1"
SH
        );
        $builder = $root.'/build.php';
        file_put_contents($builder, '<?php require '.var_export($this->pmssRepoPath('scripts/lib/runtime/environment.php'), true)
            .'; echo pmssBuildUserShellNoPamCommand("user1234", "touch ".escapeshellarg($argv[1]));');
        foreach ([true, false] as $pty) {
            $effect = $root.'/effect-'.($pty ? 'pty' : 'setsid');
            $env = 'PATH='.escapeshellarg($bin.':'.getenv('PATH')).' PMSS_TEST_PTY='.($pty ? '1' : '0');
            $result = $this->pmssExecShellCommand($env.' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($builder).' '.escapeshellarg($effect), [], '');
            $this->assertSame(0, $result['rc']);
            $built = $result['output'];
            $this->assertStringContainsString($pty ? 'runuser --pty -u' : 'setsid runuser -u', $built);
            $command = str_replace('PATH=/usr/sbin:/usr/bin:/sbin:/bin',
                'PATH='.escapeshellarg($bin.':/usr/sbin:/usr/bin:/sbin:/bin'), $built);
            $this->assertSame(0, $this->pmssExecShellCommand($command, [], '')['rc']);
            $this->assertTrue(is_file($effect));
        }
    }

    /** The manager must leave every active transition before its failed state is reset. */
    public function testResetFailedPollsManagerAndReturnsResetFailure(): void
    {
        $root = $this->pmssMakeTempDir('pmss-terminate-manager-');
        $bin = $root.'/bin';
        mkdir($bin);
        $log = $root.'/calls';
        $state = $root.'/state';
        file_put_contents($state, '0');
        file_put_contents($bin.'/timeout', "#!/bin/sh\nshift 2\n\"\$@\"\n");
        file_put_contents($bin.'/sleep', "#!/bin/sh\nexit 0\n");
        file_put_contents($bin.'/systemctl', <<<'SH'
#!/bin/sh
printf '%s\n' "$1" >> "$PMSS_TEST_LOG"
case "$1" in
  is-active)
    n=$(cat "$PMSS_TEST_STATE"); n=$((n + 1)); printf '%s' "$n" > "$PMSS_TEST_STATE"
    if [ "${PMSS_TEST_ALWAYS_ACTIVE:-0}" = 1 ]; then echo deactivating
    elif [ "$n" -eq 1 ]; then echo activating
    elif [ "$n" -eq 2 ]; then echo reloading
    else echo inactive; fi
    exit 3 ;;
  is-failed) exit 0 ;;
  reset-failed) exit "${PMSS_TEST_RESET_RC:-0}" ;;
esac
exit 1
SH
        );
        foreach (['timeout', 'sleep', 'systemctl'] as $stub) chmod($bin.'/'.$stub, 0755);
        $env = 'PATH='.escapeshellarg($bin.':'.getenv('PATH')).' PMSS_TEST_LOG='.escapeshellarg($log)
            .' PMSS_TEST_STATE='.escapeshellarg($state).' PMSS_TEST_RESET_RC=9';
        $result = $this->pmssExecShellCommand($env.' sh -c '.escapeshellarg(\pmssTerminateUserResetFailedCommand(1234)), [], '');
        $this->assertSame(9, $result['rc']);
        $this->assertSame(['is-active', 'is-active', 'is-active', 'is-failed', 'reset-failed'], file($log, FILE_IGNORE_NEW_LINES));
        file_put_contents($state, '0');
        file_put_contents($log, '');
        $result = $this->pmssExecShellCommand($env.' PMSS_TEST_ALWAYS_ACTIVE=1 sh -c '.escapeshellarg(\pmssTerminateUserResetFailedCommand(1234)), [], '');
        $this->assertSame(1, $result['rc']);
        $this->assertSame(array_fill(0, 10, 'is-active'), file($log, FILE_IGNORE_NEW_LINES));
    }

    public function testPreinstallMarkerIsKeptWhenPasswdEntryRemains(): void
    {
        $state = $this->pmssMakeTempDir('pmss-terminate-marker-');
        file_put_contents($state.'/user1234', 'preinstalled');
        $events = [];
        $log = static function (...$fields) use (&$events): void { $events[] = $fields; };
        $this->assertSame('SKIP', \pmssTerminateUserClearPreinstallState('user1234', false, true, $state, $log));
        $this->assertSame([['terminate', 'clear_pikkumies_preinstall_state', 'user1234', 'SKIP', 'Passwd entry remains; marker retained']], $events);
        $this->assertSame('preinstalled', file_get_contents($state.'/user1234'));
    }

    public function testFinalRemovalRejectsInvalidUsernames(): void
    {
        foreach (['', '../root', 'bad user', "bad\0user", '-root'] as $username) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($username): void {
                \pmssTerminateUserFinalRemovalSteps($username, 1234, 'true');
            });
        }
    }

    /**
     * Home and both recreate backups are purged synchronously through one shared helper,
     * after the account is removed and after every bounded cleanup step has run.
     * Refs #729 (revert of the async rename-aside reclaim), #908 (purge ordering).
     *
     * The protected property is SYNCHRONOUS-NOT-QUEUED, held by the forbidden list below:
     * backgrounding or renaming the home aside would let terminateUser.php return while
     * deletion is unfinished. addUser.php refuses an orphaned home; callers must
     * still receive the final termination result before provisioning again.
     *
     * The purge's POSITION is a separate concern and deliberately moved (#908). It is the
     * only unbounded step; when it overran the caller's timeout it took the entire cleanup
     * tail with it, stranding a live nginx route and 12 port reservations. Sequencing the
     * bounded work first means a timeout costs only the resumable purge. Running it later
     * in the same blocking run reintroduces none of #729 -- nothing is queued or renamed.
     */
    public function testTerminateUserPurgesHomeAndBackupsSynchronously(): void
    {
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', [
            'forbidden' => [
                'pmssTerminateUserMoveHomeForReclaim',
                'pmssTerminateUserMoveBackupForReclaim',
                'queue_home_reclaim',
                'queue_user_backup_reclaim',
                '.terminating-',
            ],
            'ordered' => [[
                'pmssTerminateUserInitialRemovalSteps($username, $uid)',
                'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
                "'release_lighttpd_port'",
                "'remove_nginx_user_file'",
                "'home' => \"/home/{\$username}\"",
                "'user_backup' => \"/home/backup-{\$username}\"",
                "'user_backup_superseded' => \"/home/backup-{\$username}.superseded\"",
                'pmssTerminateUserPurgeDirectorySteps($label, $path)',
                'pmssTerminateUserCompletion($username, $purgePaths, $purgeResults, $dryRun)',
                "echo \"\\n\".\$completion['line'].\"\\n\";",
                "exit(\$completion['rc']);",
            ]],
        ]);
    }

    /** The leftover proof replaces only the account guards and the initial account phase. */
    public function testResumeProofPrecedesGuardsAndSkipsOneAccountPhase(): void
    {
        $source = $this->pmssReadRepoExecutableSource('scripts/terminateUser.php');
        $this->assertOrderedStrings([
            '$username = pmssRequireCliUsername(',
            'pmssTerminateUserResumeUid($username, $resumePaths)',
            '$knownUsers =',
            "if (\$continue == 'N')",
            "'kill_processes_resume'",
            'if (!$resume) {',
            "'expire_account_before_kill'",
            "'kill_processes_initial'",
            "'kill_processes_retry'",
            'pmssTerminateUserReleaseRtorrentPortReservations(',
            "'disable_linger'",
            'pmssTerminateUserInitialRemovalSteps($username, $uid)',
            'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
        ], $source);
        $this->assertSame(1, substr_count($source, 'if (!$resume) {'));
        $this->assertSame(1, substr_count($source, 'pkill -9 -U '));
        $this->assertStringContainsString("\$killUserCommand = 'killall -9 -u '", $source);
        $this->assertStringContainsString("'resume' => \$resume", $source);
        $this->assertStringContainsString("'uid' => \$uid", $source);
        $this->assertStringContainsString('if (!$resume && !in_array($username, $knownUsers, true))', $source);
        $this->assertStringContainsString('if (!$resume && !is_dir(', $source);
        $this->assertStringContainsString('if (!$resume && $accountInfo === null)', $source);
        $this->assertMatches('/InitialRemovalSteps\(\$username, \$uid\), \$dryRun\);\s*\}/', $source);
        $this->assertStringNotContainsString('.terminating-', $source);
        $this->assertStringNotContainsString('pmssTerminateUserMoveHomeForReclaim', $source);
    }

    /** Run the actual refusal block with NSS snapshots, then exercise its shell guards on temp trees. */
    public function testReassignedUidSealsOnlyPinnedTopDirectoriesAndReportsFailures(): void
    {
        $source = $this->pmssReadRepoExecutableSource('scripts/terminateUser.php');
        $start = strpos($source, '$resume = false;');
        $block = substr($source, $start, strpos($source, '$knownUsers =', $start) - $start);
        $root = $this->pmssMakeTempDir('pmss-resume-seal-');
        $this->pmssEnsureDir($root.'/bin');
        $this->pmssWriteExecutableFile($root.'/bin/chown', "#!/bin/sh\n[ \"\$*\" = 'root:root -- .' ] || exit 97\nexit \"\${SEAL_RC:-0}\"\n");
        $paths = ['/home/user1234', '/home/backup-user1234', '/home/backup-user1234.superseded'];
        foreach ($paths as $index => $path) {
            mkdir($root.'/tree'.$index, 0755);
            file_put_contents($root.'/tree'.$index.'/keep', 'private');
        }
        $script = 'namespace ResumeSealFixture; $root = '.var_export($root, true).';';
        $script .= <<<'PHP'
$username = 'user1234'; $dryRun = true; $events = [];
function pmssUserAccountLookup($name) { return null; }
function pmssTerminateUserResumeUid($name, $paths) { return ['uid' => null, 'reason' => 'uid_reassigned:liveuser']; }
function pmssTerminateUserResumeRunning($name) { return false; }
function lstat($path) {
    $index = array_search($path, ['/home/user1234', '/home/backup-user1234', '/home/backup-user1234.superseded'], true);
    $info = \lstat($GLOBALS['root'].'/tree'.$index); $info['uid'] = 1000; return $info;
}
function stat($path) { return \stat($GLOBALS['root']); }
function realpath($path) { return $path; }
function posix_getpwuid($uid) { return ['name' => 'liveuser']; }
function pmssUserLifecycleStep(...$args) { $GLOBALS['events'][] = $args; return count($GLOBALS['events']) === 2 ? 9 : 0; }
function pmssUserLifecycleContextLogStatusMessage(...$args) { $GLOBALS['events'][] = $args; }
register_shutdown_function(static function () { echo json_encode($GLOBALS['events']); });
PHP;
        $result = $this->pmssExecShellCommandWithTempStderr(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script.$block));
        $this->assertSame(1, $result['result']['rc']);
        $this->assertStringContainsString('UID 1000 now belongs to liveuser', file_get_contents($result['stderrPath']));
        $this->assertStringContainsString('seal failed', file_get_contents($result['stderrPath']));
        $events = json_decode($result['result']['output'], true);
        $this->assertSame(4, count($events));
        $this->assertSame('resume_refused', $events[3][1]);
        foreach ($paths as $index => $path) {
            $this->assertSame('seal_reassigned_uid_leftover', $events[$index][2]);
            $this->assertTrue($events[$index][4], 'dry run must be passed to the step runner');
            // Replace only the approved path literal; the recorded inode pin stays unchanged.
            $command = str_replace($path, $root.'/tree'.$index, $events[$index][3]);
            $env = ['PATH' => $root.'/bin:'.getenv('PATH')];
            $this->assertSame(0, $this->pmssExecShellCommand($command, $env)['rc']);
            clearstatcache();
            $this->assertSame(0700, fileperms($root.'/tree'.$index) & 0777);
            $this->assertSame('private', file_get_contents($root.'/tree'.$index.'/keep'));
            $env['SEAL_RC'] = '9';
            $this->assertSame(9, $this->pmssExecShellCommand($command, $env)['rc']);
            rename($root.'/tree'.$index, $root.'/saved'.$index);
            symlink($root.'/saved'.$index, $root.'/tree'.$index);
            $this->assertTrue($this->pmssExecShellCommand($command, $env)['rc'] !== 0);
            unlink($root.'/tree'.$index);
            mkdir($root.'/tree'.$index);
            $this->assertTrue($this->pmssExecShellCommand($command, $env)['rc'] !== 0, 'replacement inode must refuse');
        }
    }

    /**
     * The purge sequence is remove first, inode-safe flag clearing on residue, then remove
     * the leftovers (Refs #606 — a full recursive chattr before the removal delays
     * inode recovery on large accounts). Covering the tree recursively means no named
     * path list exists to drift from what the writers actually mark immutable.
     */
    public function testPurgeDirectoryStepsClearImmutableOnResidueOnly(): void
    {
        $steps = \pmssTerminateUserPurgeDirectorySteps('home', '/home/user1234');

        $this->assertSame(
            ['remove_home_initial', 'clear_immutable_home', 'remove_home_leftovers'],
            array_column($steps, 0),
            'purge must be remove -> clear-immutable -> remove-leftovers'
        );
        $this->assertStringNotContainsString('immutableClear.pl', $steps[0][1], 'first removal must not walk the tree');
        $this->assertStringContainsString('find -P . -depth', $steps[1][1]);
        $this->assertStringContainsString('immutableClear.pl', $steps[1][1]);
        $this->assertStringContainsString("'/home/user1234'", $steps[1][1], 'path must be shell-escaped');
        $this->assertSame($steps[0][1], $steps[2][1], 'leftover removal repeats the initial removal');

        foreach ($steps as $step) {
            $this->assertSame(0, strpos($step[1], 'if [ -d '), 'every step must no-op when the directory is gone');
            $this->assertStringContainsString('cd -P --', $step[1]);
            $this->assertStringContainsString('"$pin"', $step[1]);
        }
    }

    public function testPinnedPurgeRemovesCompleteRealTreeWithoutFollowingInnerLink(): void
    {
        $base = $this->pmssMakeTempDir('pmss-purge-real-');
        $home = $base.'/home';
        $outside = $base.'/outside';
        $this->pmssEnsureDir($home.'/nested');
        $this->pmssEnsureDir($outside);
        file_put_contents($home.'/nested/data', "customer\0bytes");
        file_put_contents($home.'/.hidden', 'hidden');
        file_put_contents($outside.'/sentinel', 'outside');
        symlink($outside, $home.'/nested/outside-link');
        foreach ([false, true, false] as $clear) {
            $this->assertSame(0, $this->pmssExecShellCommand(\pmssPinnedTreePurgeCommand($home, $clear), [], '')['rc']);
        }
        $this->assertFalse(file_exists($home), 'the entire real tenant tree must be removed');
        $this->assertSame('outside', file_get_contents($outside.'/sentinel'));
    }

    public function testPinnedPurgeRefusesLinkedRootAndPreservesTenantData(): void
    {
        $base = $this->pmssMakeTempDir('pmss-purge-linked-');
        $home = $base.'/home';
        $outside = $base.'/outside';
        $this->pmssEnsureDir($home);
        $this->pmssEnsureDir($outside);
        file_put_contents($home.'/customer', 'tenant');
        file_put_contents($outside.'/sentinel', 'outside');
        rename($home, $base.'/saved-home');
        symlink($outside, $home);
        $this->assertSame(0, $this->pmssExecShellCommand(\pmssPinnedTreePurgeCommand($home, false), [], '')['rc']);
        $this->assertSame('tenant', file_get_contents($base.'/saved-home/customer'));
        $this->assertSame('outside', file_get_contents($outside.'/sentinel'));
    }

    public function testPurgeDirectoryStepsRejectPathsOutsideManagedHomes(): void
    {
        $backup = \pmssTerminateUserPurgeDirectorySteps('user_backup', '/home/backup-user1234');
        $this->assertSame('remove_user_backup_initial', $backup[0][0]);
        $superseded = \pmssTerminateUserPurgeDirectorySteps('user_backup_superseded', '/home/backup-user1234.superseded');
        $this->assertSame('remove_user_backup_superseded_initial', $superseded[0][0]);
        $this->assertStringContainsString("'/home/backup-user1234.superseded'", $superseded[0][1]);

        foreach ([
            ['home', ''],
            ['home', '/home/'],
            ['home', '/'],
            ['home', '/home/../etc'],
            ['home', '/home/user1234/other'],
            ['home', "/home/user1234\0other"],
            ['home', '/home/backup-user1234'],
            ['user_backup', '/home/user1234'],
            ['user_backup_superseded', '/home/backup-user1234'],
            ['user_backup_superseded', '/home/backup-user1234.superseded/other'],
            ['user_backup_superseded', '/home/backup-user1234.superseded-extra'],
            ['unexpected', '/home/user1234'],
        ] as $case) {
            try {
                \pmssTerminateUserPurgeDirectorySteps($case[0], $case[1]);
                $this->assertTrue(false, 'unsafe purge path should be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Refusing unsafe user purge path', $e->getMessage());
            }
        }
    }

    public function testTerminationCompletionRequiresSuccessfulPurgesAndAbsentHomes(): void
    {
        $root = $this->pmssMakeTempDir('pmss-termination-result-');
        $paths = array(
            'home' => $root.'/user',
            'user_backup' => $root.'/backup-user',
            'user_backup_superseded' => $root.'/backup-user.superseded',
        );
        $results = array();
        foreach (array_keys($paths) as $label) {
            foreach (array('initial', 'leftovers') as $phase) $results['remove_'.$label.'_'.$phase] = 0;
            $results['clear_immutable_'.$label] = 0;
        }
        $username = 'pmssmissing12345';

        $complete = \pmssTerminateUserCompletion($username, $paths, $results, false);
        $this->assertSame(array('line' => '## Done. User termination verified.', 'rc' => 0, 'status' => 'OK'), $complete);

        // Immutable stats files make the first removal fail; the leftover pass decides.
        $immutableResidue = $results;
        $immutableResidue['remove_home_initial'] = 1;
        $this->assertSame($complete, \pmssTerminateUserCompletion($username, $paths, $immutableResidue, false));
        $failed = $results;
        $failed['remove_user_backup_leftovers'] = 1;
        $this->assertSame('## Incomplete: remove_user_backup_leftovers', \pmssTerminateUserCompletion($username, $paths, $failed, false)['line']);
        $this->assertSame(1, \pmssTerminateUserCompletion($username, $paths, $failed, false)['rc']);
        $failed['remove_user_backup_initial'] = 1;
        $failed['clear_immutable_home'] = 1;
        $this->assertSame('## Incomplete: clear_immutable_home', \pmssTerminateUserCompletion($username, $paths, $failed, false)['line']);
        $missing = $results;
        unset($missing['remove_user_backup_initial']);
        $this->assertSame('## Incomplete: remove_user_backup_initial', \pmssTerminateUserCompletion($username, $paths, $missing, false)['line'], 'a missing purge step is incomplete');

        $this->pmssEnsureDir($paths['home']);
        $this->assertSame('## Incomplete: home_still_present', \pmssTerminateUserCompletion($username, $paths, $results, false)['line']);
        $this->assertSame(1, \pmssTerminateUserCompletion($username, $paths, $results, false)['rc']);
        rmdir($paths['home']);
        $this->pmssEnsureDir($paths['user_backup_superseded']);
        $this->assertSame('## Incomplete: user_backup_superseded_still_present', \pmssTerminateUserCompletion($username, $paths, $results, false)['line']);
        rmdir($paths['user_backup_superseded']);
        $this->assertSame('## Incomplete: account_still_present', \pmssTerminateUserCompletion('root', $paths, $results, false)['line']);
        $lingerRoot = $root.'/linger';
        $this->pmssEnsureDir($lingerRoot);
        file_put_contents($lingerRoot.'/'.$username, 'enabled');
        $this->assertSame('## Incomplete: linger_still_present',
            \pmssTerminateUserCompletion($username, $paths, $results, false, $lingerRoot)['line']);
        unlink($lingerRoot.'/'.$username);
        $this->assertSame($complete, \pmssTerminateUserCompletion($username, $paths, $results, false, $lingerRoot));
        $dryRun = \pmssTerminateUserCompletion($username, $paths, $results, true);
        $this->assertSame('## Done. User termination flow completed (dry run).', $dryRun['line']);
        $this->assertSame(0, $dryRun['rc']);
    }

    public function testSupersededBackupIsRemovedByPinnedPurge(): void
    {
        $root = $this->pmssMakeTempDir('pmss-termination-superseded-');
        $archive = $root.'/backup-user.superseded';
        $this->pmssWriteFile($archive.'/customer-data', 'fixture');
        foreach (array(false, true, false) as $clear) {
            $this->assertSame(0, $this->pmssExecShellCommand(\pmssPinnedTreePurgeCommand($archive, $clear), [], '')['rc']);
        }
        $this->assertFalse(file_exists($archive));
    }

    public function testTerminateUserHomeInvariantIsExact(): void
    {
        // The exact-home invariant lives in terminateUser.php itself; the reclaim-side
        // duplicate went with the async rename-aside helpers (Refs #729).
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', ['required' => [
            '$realHome !== $expectedHome',
            'Prefix checks are too loose',
            'Refusing to operate on unexpected home path',
        ]]);
    }

    public function testTerminateUserHandlesUnreadableRtorrentConfig(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/userConfig.php' => ['required' => [
                "'rtorrentPort' => (int) (\$configuration['config']['scgiPort'] ?? 0)",
                'failed to persist rTorrent ports',
            ]],
            'scripts/terminateUser.php' => ['required' => [
                'pmssTerminateUserReleaseRtorrentPortReservations($username',
            ]],
            'scripts/lib/user/termination/ports.php' => ['required' => [
                'function pmssTerminateUserReleaseRtorrentPortReservations',
                'function pmssTerminateUserRtorrentPortRecord',
                'pmssTerminateUserRtorrentPorts',
                '$configLines = @file($portFile',
                'pmssNetworkPortParseDigits',
                'cleanup_ports_config_read',
                'cleanup_ports_config_invalid',
                'rtorrentDhtPort',
                'rtorrentListenPort',
            ]],
        ]);
    }

    public function testTerminateUserFinalCleanupDoesNotDeleteActiveHomeName(): void
    {
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', [
                'required' => ["'remove_nginx_user'"],
                'forbidden' => ['escapeshellarg("/home/{$username}")'],
            ]);
    }

    public function testTerminateUserRemovesNginxSubdomainRouteFiles(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/termination/filesystem.php' => ['required' => [
                'function pmssTerminateUserRemoveNginxRouteFiles',
                'function pmssTerminateUserNginxRouteFileSpecs',
                'remove_nginx_route_file',
                'remove_nginx_route_file_hash',
                '"/etc/nginx/conf.d/pmss-user-{$username}.conf"',
                '"/etc/nginx/conf.d/pmss-user-{$username}-hash.conf"',
            ]],
            'scripts/terminateUser.php' => ['ordered' => [[
                'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
                'pmssUserLifecycleRefreshNginxConfig(',
            ]]],
        ]);
    }

    public function testTerminateUserNginxRoutePlanIsStable(): void
    {
        $this->assertSame(
            array(
                array('phase' => 'remove_nginx_route_file', 'path' => '/etc/nginx/conf.d/pmss-user-user1234.conf'),
                array('phase' => 'remove_nginx_route_file_hash', 'path' => '/etc/nginx/conf.d/pmss-user-user1234-hash.conf'),
            ),
            \pmssTerminateUserNginxRouteFileSpecs('user1234')
        );
    }

    public function testTerminateUserDryRunGuardsDirectCleanupMutations(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/termination/filesystem.php' => [
                'required' => [
                    'function pmssTerminateUserUnlinkPath',
                    'function pmssTerminateUserRemoveEmptyDir',
                    "'SKIP'",
                    'Dry run; file not removed',
                    'Dry run; directory not removed',
                ],
                'forbidden' => [
                    '@unlink("/etc/nginx/users/{$username}")',
                    'unlink($filePath)',
                    'rmdir($portsBase)',
                ],
            ],
            'scripts/terminateUser.php' => [
                'required' => [
                    "pmssTerminateUserUnlinkPath(\$username, 'remove_nginx_user_file'",
                    'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
                    '} elseif ($dryRun) {',
                    "'status'  => 'SKIP'",
                ],
                'ordered' => [[
                    '} elseif ($dryRun) {',
                    '$db->removeUser($username);',
                ]],
            ],
        ]);
    }

    public function testTerminationCleanupHelpersPreserveDryRunAndRemovalContracts(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-terminate-cleanup-');
        $file = $dir.'/route.conf';
        $emptyDir = $dir.'/empty';
        $backupDir = $dir.'/backup-user1234';
        $this->pmssWriteFile($file, 'managed');
        $this->pmssEnsureDir($emptyDir);
        $this->pmssEnsureDir($backupDir);

        $this->assertTrue(\pmssTerminateUserUnlinkPath('user1234', 'remove_file', $file, true));
        $this->assertTrue(is_file($file), 'dry-run unlink must not remove file');
        $this->assertTrue(\pmssTerminateUserUnlinkPath('user1234', 'remove_file', $file, false));
        $this->assertFalse(file_exists($file), 'non-dry unlink should remove file');

        $this->assertTrue(\pmssTerminateUserRemoveEmptyDir('user1234', 'remove_dir', $emptyDir, true));
        $this->assertTrue(is_dir($emptyDir), 'dry-run rmdir must not remove directory');
        $this->assertTrue(\pmssTerminateUserRemoveEmptyDir('user1234', 'remove_dir', $emptyDir, false));
        $this->assertFalse(is_dir($emptyDir), 'non-dry rmdir should remove empty directory');
        $this->assertTrue(is_dir($backupDir), 'recreate backup dir is removed by the terminate steps, not a helper');
    }

    public function testTerminationCleanupHelpersRejectUnsafePathInputs(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-terminate-unsafe-cleanup-');
        $file = $dir.'/route.conf';
        $emptyDir = $dir.'/empty';
        $this->pmssWriteFile($file, 'managed');
        $this->pmssEnsureDir($emptyDir);

        $this->assertFalse(\pmssTerminateUserUnlinkPath('user1234', 'remove_file', "bad\0path", false));
        $this->assertTrue(is_file($file), 'unsafe unlink input must not affect nearby files');
        $this->assertFalse(\pmssTerminateUserRemoveEmptyDir('user1234', 'remove_dir', "bad\0path", false));
        $this->assertTrue(is_dir($emptyDir), 'unsafe rmdir input must not affect nearby dirs');
    }

    public function testEmptyDirectoryCleanupReportsScanFailureWithoutRemoval(): void
    {
        $this->assertTrue(stream_wrapper_register('pmssterminatedir', TerminateDirectoryScanFailureStream::class));
        try {
            foreach ([false, true] as $dryRun) {
                TerminateDirectoryScanFailureStream::$scans = 0;
                TerminateDirectoryScanFailureStream::$removals = 0;
                $this->assertFalse(\pmssTerminateUserRemoveEmptyDir('user1234', 'remove_dir', 'pmssterminatedir://directory', $dryRun));
                $this->assertSame(1, TerminateDirectoryScanFailureStream::$scans);
                $this->assertSame(0, TerminateDirectoryScanFailureStream::$removals, 'A failed scan must never reach rmdir');
            }
        } finally {
            stream_wrapper_unregister('pmssterminatedir');
        }
    }

    public function testEmptyDirectoryCleanupKeepsMissingAndNonEmptyPathResults(): void
    {
        $root = $this->pmssMakeTempDir('pmss-terminate-scan-');
        $file = $root.'/retained';
        $this->pmssWriteFile($file, 'preserved');
        foreach ([false, true] as $dryRun) {
            foreach ([$root.'/absent', $file, $root] as $path) {
                $this->assertTrue(\pmssTerminateUserRemoveEmptyDir('user1234', 'remove_dir', $path, $dryRun));
            }
            $this->assertSame('preserved', file_get_contents($file));
        }
    }

    public function testTerminateUserRtorrentPortCleanupPreservesDryRunAndRemovesFiles(): void
    {
        $base = $this->pmssMakeTempDir('pmss-terminate-ports-');
        $store = new \UserConfigStore($this->pmssMakeTempDir('pmss-terminate-store-').'/seedbox/config');
        $this->assertTrue($store->set('user1234', [
            'ramMiB' => 512,
            'rtorrentPort' => '5050',
            'rtorrentDhtPort' => '5051',
            'rtorrentListenPort' => '5052',
            'quota' => 10,
            'quotaBurst' => 12,
        ]));
        $config = $this->pmssMakeTempFile('pmss-terminate-ports-config-');
        $this->pmssWriteFile($config, "# ignored\nscgi_port = 127.0.0.1:6050\ndht.port.set = 0\nnetwork.port_range.set = 20000-60000\n");
        $this->pmssWriteFile($base.'/scgi/5050', 'user1234');
        $this->pmssWriteFile($base.'/scgi/6050', 'legacy');
        $this->pmssWriteFile($base.'/dht/5051', 'user1234');
        $this->pmssWriteFile($base.'/dht/0', 'invalid');
        $this->pmssWriteFile($base.'/listen/5052', 'user1234');
        $this->pmssWriteFile($base.'/listen/20000', 'static-template');

        $this->assertTrue(\pmssTerminateUserReleaseRtorrentPortReservations('user1234', $config, true, $base, $store));
        $this->assertSame(array(true, true, true), array(is_file($base.'/scgi/5050'), is_file($base.'/dht/5051'), is_file($base.'/listen/5052')));

        $this->assertTrue(\pmssTerminateUserReleaseRtorrentPortReservations('user1234', $config, false, $base, $store));
        $this->assertSame(
            array(false, true, false, true, false, true, true, true),
            array(
                file_exists($base.'/scgi/5050'),
                is_file($base.'/scgi/6050'),
                file_exists($base.'/dht/5051'),
                is_file($base.'/dht/0'),
                file_exists($base.'/listen/5052'),
                is_file($base.'/listen/20000'),
                is_dir($base.'/dht'),
                is_dir($base.'/listen')
            )
        );
    }

    public function testTerminateUserRtorrentPortCleanupKeepsLegacyFallback(): void
    {
        $base = $this->pmssMakeTempDir('pmss-terminate-legacy-ports-');
        $store = new \UserConfigStore($this->pmssMakeTempDir('pmss-terminate-empty-store-').'/seedbox/config');
        $config = $this->pmssMakeTempFile('pmss-terminate-legacy-ports-config-');
        $this->pmssWriteFile($config, "scgi_port = localhost:6060\ndht_port = 6061\nport_range = 6062-6062\nnetwork.port_range.set = 20000-60000\n");
        $this->pmssWriteFile($base.'/scgi/6060', 'legacy');
        $this->pmssWriteFile($base.'/dht/6061', 'legacy');
        $this->pmssWriteFile($base.'/listen/6062', 'legacy');
        $this->pmssWriteFile($base.'/listen/20000', 'static-template');

        $this->assertTrue(\pmssTerminateUserReleaseRtorrentPortReservations('user1234', $config, false, $base, $store));
        $this->assertSame(
            array(false, false, false, true, true),
            array(
                file_exists($base.'/scgi/6060'),
                file_exists($base.'/dht/6061'),
                file_exists($base.'/listen/6062'),
                is_file($base.'/listen/20000'),
                is_dir($base.'/listen')
            )
        );
    }
}

/** Simulate an existing directory whose enumeration fails, independent of UID. */
class TerminateDirectoryScanFailureStream
{
    public $context;
    public static $scans = 0;
    public static $removals = 0;

    public function url_stat($path, $flags): array
    {
        return ['mode' => 0040700];
    }

    public function dir_opendir($path, $options): bool
    {
        self::$scans++;
        return false;
    }

    public function rmdir($path, $options): bool
    {
        self::$removals++;
        return true;
    }
}
