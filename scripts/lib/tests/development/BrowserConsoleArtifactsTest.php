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
    private function repoFile(string $rel): string
    {
        $path = dirname(__DIR__, 4).'/'.$rel;
        $this->assertTrue(is_file($path), "missing artifact: {$rel}");
        return (string) file_get_contents($path);
    }

    public function testInstallerUsesPinnedChecksumPath(): void
    {
        $src = $this->repoFile('scripts/lib/update/apps/ttyd.php');
        // Pinned version + SHA256 + arch gate + supported fetch helper (no ad-hoc curl|bash).
        $this->assertStringContainsString('1.7.7', $src);
        $this->assertMatches('/[a-f0-9]{64}/', $src, 'ttyd installer must pin a SHA256');
        $this->assertStringContainsAllStrings(["pmssPinnedRemoteAmd64OrSkip('ttyd', 'install')",
            'pmssPinnedRemoteArtifactTempFileUse', '/usr/bin/ttyd'], $src);
    }

    public function testLauncherKeepsCustomerTreeSeparation(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
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
        $src = $this->repoFile('etc/skel/www/console.php');
        $cd = strpos($src, "'cd '.escapeshellarg(\$home)");
        $export = strpos($src, "'export HOME='.escapeshellarg(\$home)");
        $cmd = strpos($src, "\$cmd.' </dev/null");

        $this->assertTrue($cd !== false, 'spawn must change to the customer home');
        $this->assertTrue($export !== false, 'spawn must export the customer home');
        $this->assertTrue($cmd !== false, 'spawn must run the ttyd command');
        $this->assertTrue($cd < $export && $export < $cmd, 'export HOME between cd and ttyd');
    }

    public function testLauncherLivenessProbeIsNotAProcessMatch(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
        $this->assertStringNotContainsString('pgrep', $src);
        $this->assertStringContainsString("stream_socket_client('unix://'", $src);
    }

    public function testLauncherSocketProbeDistinguishesStaleFromLive(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
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
        $src = $this->repoFile('etc/skel/www/console.php');
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
        $src = $this->repoFile('etc/skel/www/console.php');
        // Disabled-path behaviour: guard on the binary before spawning.
        $this->assertStringContainsAllStrings(['is_executable', '--once'], $src);
    }

    public function testLauncherReportsSocketSpawnFailure(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
        // A failed detached spawn must not fall through to a guaranteed proxy 503.
        $this->assertStringContainsAllStrings(['console-error.log', 'error_log(', 'http_response_code(503)',
            'Try again'], $src);
    }

    public function testEndedSessionReturnsToLauncher(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
        $this->assertStringContainsAllStrings(['disableReconnect=true', 'querySelectorAll(".xterm > div")',
            'Press ⏎ to Reconnect', 'window.location.reload()'], $src);
    }

    public function testProxyBlockUsesLoopbackSocketUpgrade(): void
    {
        $src = $this->repoFile('etc/seedbox/config/template.lighttpd');
        $this->assertStringContainsAllStrings(['^/user-##username/console/', 'console.sock', '"upgrade" => "enable"'], $src);
    }

    public function testConsoleButtonIsAdditive(): void
    {
        $src = $this->repoFile('etc/skel/www/info.php');
        $this->assertStringContainsString('console.php', $src);
        // Must not have removed the existing stats include.
        $this->assertStringContainsString('stats.php', $src);
    }

    public function testConsoleButtonRequiresLocalAndBackendArtifacts(): void
    {
        $src = $this->repoFile('etc/skel/www/info.php');
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
        $src = $this->repoFile('etc/skel/www/info.php');
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
        $src = $this->repoFile('scripts/lib/update/users/filesystem.php');
        $this->assertStringContainsString("'www/console.php'", $src);
    }

    private function loadLifecycleHelpers(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
        foreach (array('pmssConsoleTtydProcesses', 'pmssConsoleSpawnAllowed') as $function) {
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
                'processes' => static function () use ($state) { return $state; },
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
        $src = $this->repoFile('etc/skel/www/console.php');
        $lock = strpos($src, 'LOCK_EX | LOCK_NB');
        $probe = strpos($src, '$running = pmssConsoleSocketLive($sock)');
        $cleanup = strpos($src, 'if (!pmssConsoleSpawnAllowed(');
        $unlink = strpos($src, '@unlink($sock)');
        $spawn = strpos($src, "@exec('setsid");
        $unlock = strpos($src, 'fclose($consoleLock)');
        foreach (array($lock, $probe, $cleanup, $unlink, $spawn, $unlock) as $offset) {
            $this->assertTrue($offset !== false);
        }
        $this->assertTrue($lock < $probe && $probe < $cleanup && $cleanup < $unlink
            && $unlink < $spawn && $spawn < $unlock);
        $this->assertStringContainsAllStrings(["console.lock', 'ce'", "'exec '.\$cmd"], $src);
    }
}
