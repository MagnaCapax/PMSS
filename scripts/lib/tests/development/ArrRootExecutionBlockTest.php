<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';
require_once dirname(__DIR__, 2).'/update/arrRootExecutionBlock.php';
require_once dirname(__DIR__, 2).'/arrRootGuard.php';

/**
 * Root must not be able to START an *ARR application, and a leftover root config must be kept
 * rather than deleted (an absent config IS the first-run condition that regenerates
 * AuthenticationMethod=None / BindAddress=*). Hermetic: every path is a temp directory.
 */
class ArrRootExecutionBlockTest extends TestCase
{
    /** Collect log lines while applying the block over a temp config root. */
    private function block(string $configRoot, ?string $timestamp = null): array
    {
        $messages = [];
        \pmssEnsureArrRootExecutionBlocked(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }, $configRoot, $timestamp);
        return $messages;
    }

    public function testOccupiesEveryAppDataPathWithARegularFile(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);

        $this->block($configRoot);

        foreach (array_keys(\PMSS_ARR_APP_BRANCHES) as $app) {
            $path = $configRoot.'/'.$app;
            // A DIRECTORY here is what lets the app start; only a plain file blocks it.
            $this->assertTrue(is_file($path), $app.': data path must be occupied by a regular file');
            $this->assertFalse(is_dir($path), $app.': data path must not be a directory');
            $this->assertStringContainsString('root must never run '.$app, (string) @file_get_contents($path));
            $this->assertSame('0444', substr(sprintf('%o', fileperms($path)), -4));
        }
    }

    public function testKeepsALeftoverRootConfigInsteadOfDeletingIt(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        @mkdir($configRoot.'/Sonarr', 0700, true);
        @file_put_contents($configRoot.'/Sonarr/config.xml', '<Config><ApiKey>keepme</ApiKey></Config>');
        @file_put_contents($configRoot.'/Sonarr/sonarr.db', 'database');

        $messages = $this->block($configRoot, '20260730120000');

        $backup = $configRoot.'/Sonarr'.\PMSS_ARR_ROOT_BLOCK_SUFFIX.'20260730120000';
        $this->assertTrue(is_dir($backup), 'the leftover config directory must be moved aside, not removed');
        $this->assertSame('<Config><ApiKey>keepme</ApiKey></Config>', (string) @file_get_contents($backup.'/config.xml'));
        $this->assertSame('database', (string) @file_get_contents($backup.'/sonarr.db'));
        $this->assertTrue(is_file($configRoot.'/Sonarr'), 'the freed path must then be occupied by the block file');
        $this->assertStringContainsString('never deleted', implode("\n", $messages));
    }

    public function testLeavesTheDirectoryAloneRatherThanClobberingAnExistingBackup(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        @mkdir($configRoot.'/Lidarr', 0700, true);
        @file_put_contents($configRoot.'/Lidarr/config.xml', 'current');
        @mkdir($configRoot.'/Lidarr'.\PMSS_ARR_ROOT_BLOCK_SUFFIX.'20260730120000', 0700, true);
        @file_put_contents($configRoot.'/Lidarr'.\PMSS_ARR_ROOT_BLOCK_SUFFIX.'20260730120000/config.xml', 'earlier');

        $messages = $this->block($configRoot, '20260730120000');

        $this->assertSame('earlier', (string) @file_get_contents($configRoot.'/Lidarr'.\PMSS_ARR_ROOT_BLOCK_SUFFIX.'20260730120000/config.xml'));
        $this->assertSame('current', (string) @file_get_contents($configRoot.'/Lidarr/config.xml'));
        $this->assertStringContainsString('already exists', implode("\n", $messages));
    }

    public function testRefusesToFollowSymlinkedRootConfigPaths(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        $outside = $this->pmssMakeTempDir('pmss-arr-outside-', 0700);
        @file_put_contents($outside.'/config.xml', 'original');
        $this->pmssCreateSymlinkOrSkip($outside, $configRoot.'/Prowlarr');

        $messages = $this->block($configRoot);

        $this->assertSame('original', (string) @file_get_contents($outside.'/config.xml'));
        $this->assertStringContainsString('symlinked', implode("\n", $messages));
    }

    public function testIsIdempotentAcrossRepeatedUpdates(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);

        $this->block($configRoot, '20260730120000');
        $first = (string) @file_get_contents($configRoot.'/Readarr');
        $this->block($configRoot, '20260730130000');

        $this->assertSame($first, (string) @file_get_contents($configRoot.'/Readarr'));
        // A second run must not manufacture a backup directory out of the block file it just wrote.
        $this->assertSame([], glob($configRoot.'/Readarr'.\PMSS_ARR_ROOT_BLOCK_SUFFIX.'*') ?: []);
    }

    public function testGuardMatchesInstalledAppPathsAndAnchorsThePrefix(): void
    {
        $prefixes = \pmssArrRootGuardInstallPrefixes('/opt');

        $this->assertSame('Radarr', \pmssArrRootGuardAppForExe('/opt/Radarr/Radarr', $prefixes));
        $this->assertSame('Sonarr', \pmssArrRootGuardAppForExe('/opt/Sonarr/bin/Sonarr', $prefixes));
        // Anchoring: a look-alike install directory must not resolve to a managed app.
        $this->assertSame(null, \pmssArrRootGuardAppForExe('/opt/RadarrEvil/Radarr', $prefixes));
        $this->assertSame(null, \pmssArrRootGuardAppForExe('/opt/Radarr2/Radarr', $prefixes));
        // A customer's own copy lives under their home and must never be selected.
        $this->assertSame(null, \pmssArrRootGuardAppForExe('/home/tomate/.bin/Sonarr/Sonarr', $prefixes));
        $this->assertSame(null, \pmssArrRootGuardAppForExe('/usr/bin/bash', $prefixes));

        $allPrefixes = \pmssRootGuardInstallPrefixes('/opt');
        $this->assertSame('Whisparr', \pmssRootGuardAppForExe('/opt/Whisparr/Whisparr', $allPrefixes));
        $this->assertSame('qBittorrent', \pmssRootGuardAppForExe('/usr/bin/qbittorrent-nox', $allPrefixes));
        $this->assertSame('Deluge', \pmssRootGuardAppForExe('/usr/local/bin/deluge-web', $allPrefixes));
        $this->assertSame('rTorrent', \pmssRootGuardAppForExe('/usr/local/bin/rtorrent', $allPrefixes));
        $this->assertSame(null, \pmssRootGuardAppForExe('/usr/bin/qbittorrent-nox-helper', $allPrefixes));
        $this->assertFalse(\pmssRootGuardExeIsStandardPath('/tmp/root-owned-app'));
        $this->assertTrue(\pmssRootGuardExeIsStandardPath('/opt/RadarrEvil/Radarr'));
    }

    public function testGuardScanSelectsOnlyRootOwnedProcessesOfInstalledApps(): void
    {
        $procRoot = $this->pmssMakeTempDir('pmss-arr-proc-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
        @mkdir($installRoot.'/Radarr', 0755, true);
        @touch($installRoot.'/Radarr/Radarr');

        // uid 0 running the managed install: must be selected.
        $this->pmssWriteFakeProcess($procRoot, 101, $installRoot.'/Radarr/Radarr', 0);
        // Same binary, but a customer uid: must NOT be selected.
        $this->pmssWriteFakeProcess($procRoot, 102, $installRoot.'/Radarr/Radarr', 1046);
        // uid 0 running something else entirely: must NOT be selected.
        $this->pmssWriteFakeProcess($procRoot, 103, '/usr/bin/bash', 0);
        // Unknown root software outside standard paths: alert-only, never killed.
        $unknownExe = $this->pmssMakeTempPath('pmss-root-unknown-exe-');
        @file_put_contents($unknownExe, 'binary');
        $this->pmssWriteFakeProcess($procRoot, 104, $unknownExe, 0);
        // A look-alike under /opt is neither a known app nor an alert-only unknown path.
        $this->pmssWriteFakeProcess($procRoot, 105, '/opt/RadarrEvil/Radarr', 0);

        $found = \pmssRootGuardScan($procRoot, $installRoot);

        $this->assertSame([101, 104], array_keys($found));
        $this->assertSame('Radarr', $found[101]['app']);
        $this->assertSame('kill', $found[101]['action']);
        $this->assertSame(null, $found[104]['app']);
        $this->assertSame('alert', $found[104]['action']);
    }

    public function testGuardSelectsNonRootArrOnlyOnItsDefaultListeningPort(): void
    {
        $procRoot = $this->pmssMakeTempDir('pmss-arr-proc-', 0700);
        $procNetRoot = $this->pmssMakeTempDir('pmss-arr-net-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
        @mkdir($installRoot.'/Radarr', 0755, true);
        @touch($installRoot.'/Radarr/Radarr');
        $this->pmssWriteFakeTcpTable($procNetRoot, [301 => 7878, 302 => 7879]);

        $this->pmssWriteFakeProcess($procRoot, 301, $installRoot.'/Radarr/Radarr', 1046, [301]);
        $this->pmssWriteFakeProcess($procRoot, 302, $installRoot.'/Radarr/Radarr', 1046, [302]);
        $this->pmssWriteFakeProcess($procRoot, 303, '/home/customer/.bin/Radarr/Radarr', 1046, [301]);
        $this->pmssWriteFakeProcess($procRoot, 304, $installRoot.'/Radarr/Radarr', 0);

        $found = \pmssRootGuardScan($procRoot, $installRoot, $procNetRoot);

        $this->assertSame([301, 304], array_keys($found));
        $this->assertSame(1046, $found[301]['uid']);
        $this->assertSame('default_port', $found[301]['predicate']);
        $this->assertSame('uid0', $found[304]['predicate']);
    }

    public function testGuardKeepsTheFiveServarrDefaultPortsInOneCatalog(): void
    {
        $this->assertSame([
            'Lidarr' => 8686,
            'Prowlarr' => 9696,
            'Radarr' => 7878,
            'Readarr' => 8787,
            'Sonarr' => 8989,
        ], \PMSS_ROOT_GUARD_ARR_DEFAULT_PORTS);
    }

    public function testAuditAlertsWithoutSendingSignalsInTheTestFixture(): void
    {
        $procRoot = $this->pmssMakeTempDir('pmss-arr-proc-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
        @mkdir($installRoot.'/Radarr', 0755, true);
        @touch($installRoot.'/Radarr/Radarr');
        $unknownExe = $this->pmssMakeTempPath('pmss-root-unknown-exe-');
        @file_put_contents($unknownExe, 'binary');
        $this->pmssWriteFakeProcess($procRoot, 201, $installRoot.'/Radarr/Radarr', 0);
        $this->pmssWriteFakeProcess($procRoot, 202, $unknownExe, 0);
        $this->pmssWriteFakeProcess($procRoot, 203, $installRoot.'/Radarr/Radarr', 0);

        $messages = array();
        $signals = array();
        $findings = \pmssRootGuardAuditAndKill(
            static function (string $message) use (&$messages): void { $messages[] = $message; },
            $procRoot,
            $installRoot,
            static function (int $pid, int $signal) use (&$signals): bool {
                $signals[] = array($pid, $signal);
                return $pid === 201 && $signal === 9;
            }
        );

        $this->assertSame(3, $findings);
        $this->assertSame([[201, 9], [203, 9]], $signals);
        $this->assertStringContainsString('action=killed pid=201', implode("\n", $messages));
        $this->assertStringContainsString('action=observed_unknown_root_process pid=202', implode("\n", $messages));
        $this->assertStringContainsString('action=kill_failed pid=203', implode("\n", $messages));
    }

    /** Build a /proc-shaped entry: exe symlink plus the Uid line the scanner parses. */
    private function pmssWriteFakeProcess(
        string $procRoot,
        int $pid,
        string $exeTarget,
        int $uid,
        array $socketInodes = []
    ): void
    {
        $dir = $procRoot.'/'.$pid;
        @mkdir($dir, 0700, true);
        @file_put_contents($dir.'/status', "Name:\ttest\nUid:\t".$uid."\t".$uid."\t".$uid."\t".$uid."\n");
        $this->pmssCreateSymlinkOrSkip($exeTarget, $dir.'/exe');
        @mkdir($dir.'/fd', 0700, true);
        foreach ($socketInodes as $inode) {
            $this->pmssCreateSymlinkOrSkip('socket:['.$inode.']', $dir.'/fd/'.$inode);
        }
    }

    /** Write the minimum /proc/net/tcp fixture needed by the inode matcher. */
    private function pmssWriteFakeTcpTable(string $procNetRoot, array $portsByInode): void
    {
        $lines = ["  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode"];
        foreach ($portsByInode as $inode => $port) {
            $lines[] = sprintf(
                '  %d: 0100007F:%04X 00000000:0000 0A 00000000:0000 00:00000000 00000000 0 0 %d 1 0000000000000000 100 0 0 10 0',
                $inode,
                $port,
                $inode
            );
        }
        @file_put_contents($procNetRoot.'/tcp', implode("\n", $lines)."\n");
    }
}
