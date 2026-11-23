<?php
namespace PMSS\Tests;

/**
 * Hermetic guards for the GH #326 browser console (ADR 0031).
 *
 * Source inspection and local socket fixtures, without spawning ttyd.
 * Asserts the security and provisioning invariants that must not regress:
 * pinned-binary install, loopback-socket proxy, customer-tree separation,
 * no-root / no-request-data-in-command, and disabled-path fail-soft behaviour.
 */
class BrowserConsoleArtifactsTest extends TestCase
{
    public function testInstallerUsesPinnedChecksumPath(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/apps/ttyd.php');
        // Pinned version + SHA256 + arch gate + supported fetch helper (no ad-hoc curl|bash).
        $this->assertStringContainsString('1.7.7', $src);
        $this->assertMatches('/[a-f0-9]{64}/', $src, 'ttyd installer must pin a SHA256');
        $this->assertStringContainsAllStrings(["pmssPinnedRemoteAmd64OrSkip('ttyd', 'install')",
            'pmssPinnedRemoteArtifactTempFileUse', '/usr/bin/ttyd'], $src);
    }

    public function testLauncherKeepsCustomerTreeSeparation(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/console.php');
        // AGENTS.md INVIOLABLE: customer-facing PHP must never require/include from
        // the operator-only /scripts/ tree. console.php is designed self-contained,
        // so assert it carries no include/require statement at all (strongest form).
        $this->assertFalse(
            (bool) preg_match('/^[^\S\r\n]*(require|require_once|include|include_once)\b/m', $src),
            'console.php must contain no include/require statement (customer-tree separation)'
        );
    }

    public function testLauncherExportsHomeForTheSpawnedShell(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('etc/skel/www/console.php', [
            "'cd '.escapeshellarg(\$home)", "'export HOME='.escapeshellarg(\$home)", "\$cmd.' </dev/null",
        ], 'Console spawn is missing: ', 'Export HOME between cd and ttyd: ');
    }

    public function testLauncherLivenessProbeIsNotAProcessMatch(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/console.php');
        $this->assertStringNotContainsString('pgrep', $src);
        $this->assertStringContainsString("stream_socket_client('unix://'", $src);
    }

    public function testLauncherSocketProbeDistinguishesStaleFromLive(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/console.php');
        $this->assertTrue(
            preg_match('/^function pmssConsoleSocketLive\(.*?^\}$/ms', $src, $match) === 1,
            'console.php must define a standalone pmssConsoleSocketLive function'
        );
        if (!function_exists('pmssConsoleSocketLive')) {
            eval($match[0]);
        }

        $dir = $this->pmssMakeTempDir('pmss-console-');
        $sock = $dir.'/socket';
        $this->assertTrue(strlen($sock) < 100, 'UNIX socket path must fit the platform limit');
        $this->assertFalse(\pmssConsoleSocketLive($sock), 'missing socket must be dead');

        $listener = @stream_socket_server('unix://'.$sock);
        $this->assertTrue($listener !== false, 'expected local UNIX socket listener');
        fclose($listener);
        $this->assertTrue(file_exists($sock), 'closed listener must leave a stale socket fixture');
        $this->assertFalse(\pmssConsoleSocketLive($sock), 'stale socket must be dead');

        @unlink($sock);
        $listener = @stream_socket_server('unix://'.$sock);
        $this->assertTrue($listener !== false, 'expected live UNIX socket listener');
        $this->assertTrue(\pmssConsoleSocketLive($sock), 'live listener must accept connections');
        fclose($listener);
        @unlink($sock);
    }

    public function testLauncherIsUnprivilegedAndInjectionSafe(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/console.php');
        // Loopback UNIX socket, origin-checked, and no root.
        $this->assertStringContainsAndOmitsStrings(['console.sock', '--check-origin'], ['sudo', ' su '], $src);
        // Mode is a fixed enum: request data must not build the spawned command.
        $this->assertStringContainsString("'persist'", $src);
        $this->assertFalse(
            (bool) preg_match('/exec\([^;]*\$_(GET|POST|REQUEST)/', $src),
            'console.php must not interpolate request data into an exec command'
        );
    }

    public function testLauncherFailsSoftWhenGatewayAbsent(): void
    {
        // Disabled-path behaviour: guard on the binary before spawning.
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/console.php', ['is_executable', '--once']);
    }

    public function testLauncherReportsSocketSpawnFailure(): void
    {
        // A failed detached spawn must not fall through to a guaranteed proxy 503.
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/console.php', ['console-error.log', 'error_log(', 'http_response_code(503)',
            'Try again']);
    }

    public function testEndedSessionReturnsToLauncher(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/console.php', ['disableReconnect=true', 'querySelectorAll(".xterm > div")',
            'Press ⏎ to Reconnect', 'window.location.reload()']);
    }

    public function testEphemeralLabelSaysClosingTheTabStopsRunningPrograms(): void
    {
        // The ephemeral session ends with the tab (ttyd --once, SIGHUP to the shell): a
        // long job that cleans up only at exit leaves its scratch behind and counts against quota.
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/console.php', [
            'closing or reloading the tab stops running programs', 'long jobs', 'Persistent (tmux)',
        ]);
    }

    public function testProxyBlockUsesLoopbackSocketUpgrade(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/template.lighttpd', ['^/user-##username/console/', 'console.sock', '"upgrade" => "enable"']);
    }

    public function testConsoleButtonIsAdditive(): void
    {
        // Must not have removed the existing stats include.
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/www/info.php', ['console.php', 'stats.php']);
    }

    public function testConsoleButtonRequiresLocalAndBackendArtifacts(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/info.php');
        // guiv may heal info.php before console.php reaches the same user tree;
        // hide the buttons unless both the launcher and system-wide backend exist.
        $this->assertMatches(
            '/is_file\(__DIR__\s*\.\s*[\'"]\/console\.php[\'"]\)\s*&&\s*is_file\([\'"]\/usr\/bin\/ttyd[\'"]\)/',
            $src,
            'info.php must hide console buttons when the launcher or ttyd backend is absent'
        );
    }

    public function testConsoleBlockDoesNotReusePortfolioFlexContainer(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/info.php');
        // The info page already has an outer .portfoliobox flex row for stats.
        // The console block must stay a plain block so its heading/body/buttons stack.
        $this->assertStringContainsString('id="pmss-console"', $src);
        $this->assertFalse(
            (bool) preg_match('/<div\b(?=[^>]*\bid="pmss-console")(?=[^>]*\bclass="[^"]*\bportfoliobox\b[^"]*")/i', $src),
            'info.php console block must not reuse .portfoliobox flex layout'
        );
    }

    public function testConsolePhpRegisteredForExistingUserDistribution(): void
    {
        // Regression guard (2026-07-11): a NEW skel www file reaches EXISTING users
        // only if it is listed in pmssUserApplySkeletonFiles()'s distribution
        // allowlist. Without this, the info.php "Open console" button 404s for every
        // current customer (caught on first fleet deploy to arkstream).
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/users/filesystem.php', "'www/console.php'");
    }

    private function loadLifecycleHelpers(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/console.php');
        foreach (array('pmssConsoleTtydProcesses', 'pmssConsoleSpawnAllowed', 'pmssConsoleShellCommand',
            'pmssConsoleSessionEnded', 'pmssConsolePage') as $function) {
            $this->assertTrue(preg_match('/^function '.$function.'\(.*?^\}$/ms', $src, $match) === 1);
            if (!function_exists($function)) eval($match[0]);
        }
    }

    public function testConsoleProcessIdentityAndIdleState(): void
    {
        $this->loadLifecycleHelpers();
        $root = $this->pmssMakeTempDir('pmss-console-proc-');
        $sock = '/fixture/console.sock';
        $command = "/usr/bin/ttyd\0-W\0--once\0-i\0".$sock."\0bash\0";
        // All process observations are fixture files; no host process is read or signalled.
        $cases = array(
            101 => array(12345, 12345, 'ttyd', '/usr/bin/ttyd', $command, '', true),
            102 => array(12345, 12345, 'ttyd', '/usr/bin/ttyd', $command, '999 ', false),
            103 => array(12345, 12345, 'ttyd', '/usr/bin/ttyd', $command, null, false),
            104 => array(12345, 12345, 'ttyd', '/usr/bin/ttyd (deleted)', $command, '', true),
            105 => array(12345, 12345, 'ttyd', '/other/ttyd', $command, '', null),
            106 => array(12345, 12345, 'ttyd', '/usr/bin/ttyd', str_replace($sock, $sock.'.other', $command), '', null),
            107 => array(12345, 12345, 'ttyd', '/usr/bin/ttyd', str_replace("--once\0", '', $command), '', null),
            108 => array(54321, 54321, 'ttyd', '/usr/bin/ttyd', $command, '', 'absent'),
            109 => array(12345, 0, 'ttyd', '/usr/bin/ttyd', $command, '', 'absent'),
            110 => array(12345, 12345, 'sh', '/bin/sh', $command, '', 'absent'),
            111 => array(12345, 12345, 'ttyd', null, $command, '', false),
            112 => array(12345, 12345, 'ttyd', '/usr/bin/ttyd', null, '', false),
        );
        $expected = array();
        foreach ($cases as $pid => $case) {
            list($real, $effective, $comm, $exe, $cmdline, $children, $state) = $case;
            $dir = $root.'/'.$pid;
            mkdir($dir.'/task/'.$pid, 0700, true);
            $files = ['status' => "Uid:\t$real\t$effective\t$effective\t$effective\n", 'comm' => $comm."\n"];
            if ($cmdline !== null) $files['cmdline'] = $cmdline;
            if ($children !== null) $files['task/'.$pid.'/children'] = $children;
            $this->pmssWriteFiles($dir, $files, 0700);
            if ($exe !== null) $this->pmssWriteSymlinks($dir, ['exe' => $exe], 0700);
            if ($state !== 'absent') $expected[$pid] = $state;
        }
        $this->assertSame($expected, \pmssConsoleTtydProcesses(12345, '/usr/bin/ttyd', $sock, $root));
        $this->assertSame(array(101 => true), \pmssConsoleTtydProcesses(12345, '/usr/bin/ttyd', $sock, $root, 101));
        foreach (array(null, 0, -1, '12345') as $uid) {
            $this->assertSame(null, \pmssConsoleTtydProcesses($uid, '/usr/bin/ttyd', $sock, $root));
        }
    }

    public function testConsoleCleanupWaitsForExitBeforeAllowingSpawn(): void
    {
        $this->loadLifecycleHelpers();
        $state = array(101 => true, 102 => null);
        $signals = array();
        $pauses = 0;
        $allowed = \pmssConsoleSpawnAllowed(12345, '/usr/bin/ttyd', '/fixture/console.sock', array(
            'processes' => static function () use (&$state) { return $state; },
            'signal' => static function ($pid) use (&$signals) { $signals[] = $pid; return true; },
            'pause' => static function () use (&$state, &$pauses) { unset($state[101]); $pauses++; },
        ));
        $this->assertTrue($allowed);
        $this->assertSame(array(101), $signals);
        $this->assertSame(1, $pauses, 'successful TERM alone is not proof that ttyd has exited');
    }

    public function testConsoleSpawnLimitAndCleanupFailures(): void
    {
        $this->loadLifecycleHelpers();
        $cases = array(
            array(array(), true, true, 0),
            array(array(101 => null, 102 => null), true, true, 0),
            array(array(101 => null, 102 => null, 103 => null), true, false, 0),
            array(array(101 => false), true, false, 0),
            array(array(101 => true), false, false, 1),
            array(array(101 => true), true, false, 1), // TERM accepted, process still present.
            array(null, true, false, 0),
        );
        foreach ($cases as $case) {
            list($state, $signalResult, $expected, $signalCount) = $case;
            $signals = array();
            $actual = \pmssConsoleSpawnAllowed(12345, '/usr/bin/ttyd', '/fixture/console.sock', array(
                'processes' => self::pmssMakeCallbacks(['processes' => $state])['processes'],
                'signal' => static function ($pid) use (&$signals, $signalResult) {
                    $signals[] = $pid;
                    return $signalResult;
                },
                'pause' => static function () {},
            ));
            $this->assertSame($expected, $actual);
            $this->assertSame($signalCount, count($signals));
        }
    }

    public function testConsoleRechecksIdleStateBeforeSignalling(): void
    {
        $this->loadLifecycleHelpers();
        foreach (array(array(101 => false), array(), null) as $changed) {
            $reads = 0;
            $signals = array();
            $allowed = \pmssConsoleSpawnAllowed(12345, '/usr/bin/ttyd', '/fixture/console.sock', array(
                'processes' => static function () use (&$reads, $changed) {
                    return ++$reads === 1 ? array(101 => true) : $changed;
                },
                'signal' => static function ($pid) use (&$signals) { $signals[] = $pid; return true; },
                'pause' => static function () {},
            ));
            $this->assertSame($changed === array(), $allowed);
            $this->assertSame(array(), $signals);
        }
    }

    public function testConsoleSerializesProbeCleanupAndSpawn(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/console.php');
        $this->assertOrderedStrings([
            'LOCK_EX | LOCK_NB', '$running = pmssConsoleSocketLive($sock)', 'if (!pmssConsoleSpawnAllowed(',
            '@unlink($sock)', "@exec('setsid", 'fclose($consoleLock)',
        ], $src);
        $this->assertStringContainsAllStrings(["console.lock', 'ce'", "'exec '.\$cmd"], $src);
    }

    /** Run the launcher's wrapped command with a stand-in shell; optionally SIGHUP the wrapper like ttyd does.
     * The leading exec makes the wrapper itself the signalled process, not an outer shell. */
    private function runConsoleWrapper(string $shellCmd, string $marker, bool $hangup): void
    {
        $process = proc_open('exec '.\pmssConsoleShellCommand($shellCmd, $marker), array(array('file', '/dev/null', 'r'),
            array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pipes);
        $this->assertTrue(is_resource($process), 'wrapper must start');
        if ($hangup) {
            usleep(300000);
            proc_terminate($process, 1);
        }
        proc_close($process);
    }

    public function testConsoleMarkerIsWrittenOnlyWhenTheShellItselfEnds(): void
    {
        $this->loadLifecycleHelpers();
        $dir = $this->pmssMakeTempDir('pmss-console-end-');
        // The marker path has a space and a quote: it must survive both shell quoting layers.
        $marker = $dir."/it's here/console.ended";
        mkdir(dirname($marker), 0700, true);
        // A shell that returns with status 0, and one that returns with a failing status.
        foreach (array("sh -c 'exit 0'", "sh -c 'exit 3'") as $shell) {
            $this->runConsoleWrapper($shell, $marker, false);
            $this->assertTrue(is_file($marker), 'exit ('.$shell.') must leave the session-ended marker');
            unlink($marker);
        }
        // A dropped connection: ttyd signals the wrapper with SIGHUP while the shell still runs.
        $this->runConsoleWrapper('sleep 2', $marker, true);
        $this->assertFalse(is_file($marker), 'a dropped connection must not leave the session-ended marker');
    }

    public function testConsoleEndedSessionIsReportedOnce(): void
    {
        $this->loadLifecycleHelpers();
        $marker = $this->pmssMakeTempDir('pmss-console-once-').'/console.ended';
        $this->assertFalse(\pmssConsoleSessionEnded($marker), 'no marker means the session did not end on purpose');
        touch($marker);
        $this->assertTrue(\pmssConsoleSessionEnded($marker));
        $this->assertFalse(is_file($marker), 'the marker is consumed so the next load starts a session');
        $this->assertFalse(\pmssConsoleSessionEnded($marker));
    }

    public function testConsolePageShowsEndedNoticeInsteadOfRelaunching(): void
    {
        $this->loadLifecycleHelpers();
        $ended = \pmssConsolePage(false, true);
        $this->assertStringContainsAllStrings(['Session ended', 'Start a new session', 'href="console.php"'], $ended);
        $this->assertStringNotContainsString('window.location.reload()', $ended);
        $this->assertStringNotContainsString('<iframe', $ended);
        $this->assertStringContainsString('href="console.php?mode=persist"', \pmssConsolePage(true, true));
        $live = \pmssConsolePage(false, false);
        $this->assertStringContainsAllStrings(['<iframe id="terminal"', 'Press ⏎ to Reconnect', 'window.location.reload()'], $live);
    }

    public function testConsoleLauncherChecksEndMarkerBeforeSpawning(): void
    {
        $src = $this->pmssReadRepoFile('etc/skel/www/console.php');
        $this->assertOrderedStrings(['pmssConsoleSessionEnded($endMarker)', 'LOCK_EX | LOCK_NB',
            'pmssConsoleShellCommand($shellCmd, $endMarker)'], $src);
    }
}
