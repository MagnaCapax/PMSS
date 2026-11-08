<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/identity.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/scriptsIncAppProcess.php';

/** Regressions for #984: rootless Docker shares the account UID and process name. */
final class QbittorrentNativeIsolationTest extends TestCase
{
    /** Replace proc observations so neither process enumeration nor signals reach the host. */
    private function identityOps(array $executables, array $namespaces = []): array
    {
        return self::pmssMakeCallbacks(['account' => ['uid' => 1000], 'pids' => array_keys($executables)]) + [
            'namespace' => static function (int $pid) use ($namespaces) {
                return array_key_exists($pid, $namespaces) ? $namespaces[$pid] : 'mnt:[host]';
            },
            'read' => static function (string $path): string {
                return substr($path, -5) === '/comm' ? "qbittorrent-nox\n" : "Uid:\t1000 1000 1000 1000\n";
            },
            'exe' => static function (int $pid) use ($executables) { return $executables[$pid]; },
        ];
    }

    public function testNativePidsKeepSystemAndReplacedBinariesButRejectContainerAndUnreadableExe(): void
    {
        $ops = $this->identityOps([
            101 => '/usr/bin/qbittorrent-nox',
            102 => '/usr/local/bin/qbittorrent-nox',
            103 => '/opt/qBittorrent/5.1.0/qbittorrent-nox',
            104 => '/usr/bin/qbittorrent-nox (deleted)',
            105 => '/usr/local/bin/qbittorrent-nox (deleted)',
            106 => '/app/qbittorrent-nox',
            107 => false,
            108 => '/usr/bin/qbittorrent-nox-helper',
        ]);
        // Even with an identical UID and namespace, the guest executable is never native.
        $this->assertSame([101, 102, 103, 104, 105], \pmssTorrentClientNativeStopPids('fixtur01', 'qbittorrent', $ops));
    }

    public function testNativeStopRetainsEachRequestedSignalWithoutTouchingContainer(): void
    {
        foreach ([9, 15] as $signal) {
            $signals = [];
            $ops = $this->identityOps([101 => '/usr/bin/qbittorrent-nox', 102 => '/app/qbittorrent-nox']);
            $ops['signal'] = static function (int $pid, int $value) use (&$signals): bool {
                $signals[] = [$pid, $value];
                return true;
            };
            $this->assertSame(1, \pmssTorrentClientNativeStop('fixtur01', 'qbittorrent', $signal, $ops));
            $this->assertSame([[101, $signal]], $signals);
        }
    }

    public function testDockerOnlyDoesNotSatisfyWatchdogLiveness(): void
    {
        foreach ([[], [101 => '/usr/bin/qbittorrent-nox']] as $native) {
            $ops = $this->identityOps($native + [102 => '/app/qbittorrent-nox'], [102 => 'mnt:[container]']);
            $this->assertSame(array_keys($native), \pmssTorrentClientLauncherLivenessPids('fixtur01', 'qbittorrent', $ops));
        }
        // Namespace read failures must not turn an unidentifiable guest into a live native daemon.
        foreach ([false, ''] as $namespace) {
            $ops = $this->identityOps([102 => '/app/qbittorrent-nox'], [102 => $namespace]);
            $this->assertSame([], \pmssTorrentClientLauncherLivenessPids('fixtur01', 'qbittorrent', $ops));
        }
    }

    public function testPanelSignalsOnlyNativeBinariesIncludingDeletedProcSuffix(): void
    {
        $root = $this->pmssMakeTempDir('pmss-qbt-isolation-');
        $native = $this->pmssWriteFile($root.'/usr/bin/qbittorrent-nox', 'native');
        $local = $this->pmssWriteFile($root.'/usr/local/bin/qbittorrent-nox', 'native');
        $container = $this->pmssWriteFile($root.'/app/qbittorrent-nox', 'container');
        $proc = $root.'/proc';
        // Root-run CI gives only the synthetic proc entries a non-root fixture owner.
        $uid = posix_geteuid() === 0 ? 1000 : posix_geteuid();
        $this->pmssWriteSymlinks($proc.'/self', ['ns/mnt' => 'mnt:[host]']);
        foreach ([101 => $native, 102 => $local, 103 => $native.' (deleted)',
            104 => $local.' (deleted)', 105 => $container, 106 => $native, 107 => null] as $pid => $exe) {
            $this->pmssWriteSymlinks($proc.'/'.$pid, ['ns/mnt' => $pid === 106 ? 'mnt:[container]' : 'mnt:[host]']);
            // An absent exe represents an unreadable or exited process and must fail closed.
            if ($exe !== null) $this->pmssWriteSymlinks($proc.'/'.$pid, ['exe' => $exe]);
            if (posix_geteuid() === 0) $this->assertTrue(chown($proc.'/'.$pid, $uid));
        }
        clearstatcache();
        $this->assertSame([101, 102, 103, 104], \pmssCustomerNativePidsRead([$native, $local], $proc, $uid));
        foreach ([9, 15] as $signal) {
            $signals = [];
            $sender = static function (int $pid, int $value) use (&$signals): bool {
                $signals[] = [$pid, $value];
                return true;
            };
            $this->assertSame(4, \pmssCustomerNativeSignal([$native, $local], $signal, $proc, $uid, $sender));
            $this->assertSame([[101, $signal], [102, $signal], [103, $signal], [104, $signal]], $signals);
        }
    }

    public function testStopSitesUseScopedIdentityWhileSuspensionKeepsAllClientStop(): void
    {
        // These entry points have no process-injection seam; check their executable delegation only.
        $sites = [
            'scripts/changePw.php' => "pmssTorrentClientNativeStop(\$username, 'qbittorrent', 9);",
            'scripts/lib/user/torrentPort.php' => "pmssTorrentClientNativeStop(\$user, 'qbittorrent', 9);",
            'scripts/lib/user/qbittorrentThrottleWatchdog.php' => "pmssTorrentClientNativeStop(\$user, 'qbittorrent', 15);",
            'etc/skel/www/qbittorrent.php' => "pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['qBittorrent']['binaries']);",
        ];
        foreach ($sites as $path => $call) {
            $this->assertStringContainsAndOmitsStrings([$call], ['killall', 'pkill'], $this->pmssReadRepoExecutableSource($path), $path);
        }
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/watchdog.php',
            'pmssUserWatchdogTerminateProcesses($username, $processNames, 9);');
        $this->pmssAssertRepoFileContainsString('scripts/lib/user/qbittorrentWatchdog.php',
            "pmssTorrentClientLauncherLivenessPids(\$user, 'qbittorrent') !== [];");
    }
}
