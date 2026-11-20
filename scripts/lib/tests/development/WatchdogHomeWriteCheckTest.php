<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

define('PMSS_HOME_WRITE_LIBRARY', true);
require_once dirname(__DIR__, 4).'/etc/seedbox/config/template.watchdog.home-write-check.php';

/** ADR 0301: the watchdog reboots for a stuck /home write path, never for load. */
class WatchdogHomeWriteCheckTest extends TestCase
{
    private const LOGIC = 'etc/seedbox/config/template.watchdog.home-write-check.php';
    private const SHIM = 'etc/seedbox/config/template.watchdog.home-write-check.sh';
    private const HOME_MOUNTS = "/dev/md1 / ext4 rw,relatime 0 0\n/dev/md2 /home ext4 rw,noatime,commit=60 0 0\n";
    // Two real-shaped rows: major minor name reads merged sectors ms writes ...
    private const DISKSTATS = " 259 0 nvme0n1 933751 307654 14377812 113348 795037 2829505 35626871 849833 0 103108 974468\n"
        ."   9 2 md2 2591158 0 966324466 256471364 1107279 0 874236848 3371487188 1104 5688708 3627958552\n"
        ."   9 20 md20 1 0 8 1 55 0 8 1 0 1 2\n";

    public function testConfigHasNoLoadThresholdAndKeepsTheHeartbeat(): void
    {
        $conf = $this->pmssAssertRepoFileContainsAndOmitsStrings('etc/seedbox/config/template.watchdog.conf', [
            'watchdog-device = /dev/watchdog',
            'watchdog-timeout = 60',
            'interval = 10',
            'retry-timeout = 3600',
            'min-memory = 1',
            'test-directory = /etc/watchdog.d',
            "\nrealtime = yes\n",
        ], ['max-load', 'priority =']);
        // Comments may explain the removal, but no active directive may mention load.
        foreach (explode("\n", $conf) as $line) {
            $this->assertFalse(preg_match('/^\s*max-load/', $line) === 1, 'No load directive may remain: '.$line);
        }
    }

    public function testLogicNeverNamesAPathUnderHome(): void
    {
        $source = $this->pmssReadRepoFile(self::LOGIC);
        $this->assertStringNotContainsString('/home/', $source, 'The test must not read or write below /home.');
        // The only uses of the string are the exact mountpoint comparison and prose.
        $this->assertStringContainsString("\$fields[1] === '/home'", $source);
    }

    public function testMountSourcePrefersTheLastHomeEntryAndFallsBackToRoot(): void
    {
        $this->assertSame('/dev/md2', pmssHomeWriteMountSource(self::HOME_MOUNTS));
        $this->assertSame('/dev/md1', pmssHomeWriteMountSource("/dev/md1 / ext4 rw 0 0\n"));
        // A later mount shadows an earlier one at the same mountpoint.
        $this->assertSame('/dev/md3', pmssHomeWriteMountSource(self::HOME_MOUNTS."/dev/md3 /home ext4 rw 0 0\n"));
        // A mount below /home (a tenant's fuse mount) is another path and never selected.
        $this->assertSame('/dev/md2', pmssHomeWriteMountSource(self::HOME_MOUNTS."rclone /home/user/remote fuse.rclone rw 0 0\n"));
        // /home mounted from something that is not a /dev node: nothing to observe, no fallback to /.
        $this->assertSame('', pmssHomeWriteMountSource("/dev/md1 / ext4 rw 0 0\ntank/home /home zfs rw 0 0\n"));
        $this->assertSame('', pmssHomeWriteMountSource(''));
        $this->assertSame('', pmssHomeWriteMountSource("rootfs / rootfs rw 0 0\n"));
    }

    public function testCountersParseExactlyAndRejectMalformedInput(): void
    {
        $this->assertSame(1107279, pmssHomeWriteCompleted(self::DISKSTATS, 'md2'));
        // md20 is not md2, and a name absent from the table is null.
        $this->assertSame(55, pmssHomeWriteCompleted(self::DISKSTATS, 'md20'));
        $this->assertSame(null, pmssHomeWriteCompleted(self::DISKSTATS, 'md3'));
        $this->assertSame(null, pmssHomeWriteCompleted("9 2 md2 1 2 3\n", 'md2'));
        $this->assertSame(null, pmssHomeWriteCompleted("9 2 md2 1 2 3 4 x 6 7 8\n", 'md2'));

        $this->assertSame(617, pmssHomeWriteInflight("      33      617\n"));
        $this->assertSame(0, pmssHomeWriteInflight("       0        0\n"));
        foreach (['', '5', '1 2 3', 'a b', '-1 -2'] as $malformed) {
            $this->assertSame(null, pmssHomeWriteInflight($malformed), 'Malformed in-flight: '.$malformed);
        }
        $this->assertSame(5697, pmssHomeWriteUptime("5697.87 107.33\n"));
        $this->assertSame(null, pmssHomeWriteUptime('abc'));
        $this->assertSame(null, pmssHomeWriteUptime(''));

        $this->assertSame(['since' => 10, 'writes' => 20, 'logged' => 1], pmssHomeWriteParseState('10 20 1'));
        foreach (['', '10 20', '10 20 2', 'a 20 0', '10 20 0 9'] as $malformed) {
            $this->assertSame(null, pmssHomeWriteParseState($malformed), 'Malformed state: '.$malformed);
        }
    }

    public function testDecisionProgressingIdleAndStuck(): void
    {
        $open = ['since' => 100, 'writes' => 500, 'logged' => 0];

        // Progressing: the counter moved, so the window restarts at this sample.
        $this->assertSame([['since' => 900, 'writes' => 501, 'logged' => 0], 0], pmssHomeWriteDecide($open, 900, 501, 40));
        // Idle: nothing in flight closes any window, however long it was open.
        $this->assertSame([null, 0], pmssHomeWriteDecide($open, 900, 500, 0));
        // First sight of pending writes opens a window and never fails.
        $this->assertSame([['since' => 900, 'writes' => 500, 'logged' => 0], 0], pmssHomeWriteDecide(null, 900, 500, 3));
        // Stuck with in-flight writes: fails at the window boundary, not one second before.
        $this->assertSame([$open, 0], pmssHomeWriteDecide($open, 100 + PMSS_HOME_WRITE_WINDOW - 1, 500, 3));
        $this->assertSame([$open, 5], pmssHomeWriteDecide($open, 100 + PMSS_HOME_WRITE_WINDOW, 500, 3));
        $this->assertSame([$open, 5], pmssHomeWriteDecide($open, 100 + 3 * PMSS_HOME_WRITE_WINDOW, 500, 1));
        // A stuck device that drains its queue (nothing in flight) is no longer stuck.
        $this->assertSame([null, 0], pmssHomeWriteDecide($open, 100 + 3 * PMSS_HOME_WRITE_WINDOW, 500, 0));
        // Uptime going backwards means a new boot: the old window is void.
        $this->assertSame([['since' => 10, 'writes' => 500, 'logged' => 0], 0], pmssHomeWriteDecide($open, 10, 500, 3));
        // A counter that wrapped or reset is a change, not a stall.
        $this->assertSame([['since' => 5000, 'writes' => 3, 'logged' => 0], 0], pmssHomeWriteDecide($open, 5000, 3, 3));
    }

    public function testDeviceResolutionFollowsLinksAndRejectsNonBlockNames(): void
    {
        $root = $this->fixtureRoot();
        $this->pmssWriteFiles($root, ['dev/dm-3' => '', 'sys/class/block/dm-3/inflight' => "0 0\n"]);
        $this->pmssWriteSymlinks($root, ['dev/mapper/vg-home' => '../dm-3']);

        $this->assertSame('md2', pmssHomeWriteDeviceName('/dev/md2', $root));
        $this->assertSame('dm-3', pmssHomeWriteDeviceName('/dev/mapper/vg-home', $root), 'A dm link resolves to the kernel name.');
        $this->assertSame('', pmssHomeWriteDeviceName('/dev/missing', $root), 'A missing device node.');
        $this->assertSame('', pmssHomeWriteDeviceName('', $root));
        // A node with no matching sysfs entry cannot be observed.
        $this->pmssWriteFiles($root, ['dev/sdz' => '']);
        $this->assertSame('', pmssHomeWriteDeviceName('/dev/sdz', $root));
        // A link that leaves /dev is not a device name.
        $this->pmssWriteFiles($root, ['elsewhere/md2' => '']);
        $this->pmssWriteSymlinks($root, ['dev/escape' => '../elsewhere/md2']);
        $this->assertSame('', pmssHomeWriteDeviceName('/dev/escape', $root));
    }

    public function testRunStuckLogsOnceAndRecoversWhenWritesComplete(): void
    {
        $root = $this->fixtureRoot();
        $this->pmssWriteRelativeFile($root, 'run/pmss-watchdog-home-write', '500 1107279 0');
        $this->assertSame(5, $this->runCheck($root), 'Writes in flight, none completed for 500 s of uptime.');
        $this->assertSame('500 1107279 1', file_get_contents($root.'/run/pmss-watchdog-home-write'));
        $this->assertSame(5, $this->runCheck($root));
        $log = file_get_contents($root.'/var/log/pmss/checkHomeWriteWatchdog.log');
        $this->assertSame(1, substr_count($log, 'STUCK'), 'Evidence is logged once per stuck episode.');
        $this->assertStringContainsAllStrings(['device=md2', 'writes_completed=1107279', 'write_inflight=617', 'psi_io=[some avg10=96.90'], $log);

        $this->pmssWriteRelativeFile($root, 'proc/diskstats', str_replace(' 1107279 ', ' 1107280 ', self::DISKSTATS));
        $this->assertSame(0, $this->runCheck($root), 'One completed write ends it.');
        $this->assertSame('1000 1107280 0', file_get_contents($root.'/run/pmss-watchdog-home-write'));
        $this->assertSame(1, substr_count(file_get_contents($root.'/var/log/pmss/checkHomeWriteWatchdog.log'), 'recovered'));
    }

    public function testRunBusyHostWithHugeLoadIsNeverStuck(): void
    {
        $root = $this->fixtureRoot();
        // The failure the load rule caused: load far over the old thresholds, writes still completing.
        $this->pmssWriteRelativeFile($root, 'proc/loadavg', "905.00 400.00 300.00 12/3000 1\n");
        $this->pmssWriteRelativeFile($root, 'run/pmss-watchdog-home-write', '10 1107000 0');
        $this->assertSame(0, $this->runCheck($root));
        $this->assertSame('1000 1107279 0', file_get_contents($root.'/run/pmss-watchdog-home-write'));
        $this->assertFalse(file_exists($root.'/var/log/pmss/checkHomeWriteWatchdog.log'), 'A healthy check writes no evidence.');
    }

    public function testRunIdleAndCorruptStateNeverFail(): void
    {
        $root = $this->fixtureRoot();
        $this->pmssWriteRelativeFile($root, 'sys/class/block/md2/inflight', "       0        0\n");
        $this->pmssWriteRelativeFile($root, 'run/pmss-watchdog-home-write', '1 1107279 0');
        $this->assertSame(0, $this->runCheck($root), 'Idle device: nothing pending, nothing stuck.');
        $this->assertFalse(file_exists($root.'/run/pmss-watchdog-home-write'), 'Idle closes the window.');

        $this->pmssWriteRelativeFile($root, 'sys/class/block/md2/inflight', "0 9\n");
        $this->pmssWriteRelativeFile($root, 'run/pmss-watchdog-home-write', 'garbage');
        $this->assertSame(0, $this->runCheck($root), 'Corrupt state restarts the window.');
        $this->assertSame('1000 1107279 0', file_get_contents($root.'/run/pmss-watchdog-home-write'));
    }

    public function testRunMissingDeviceAndUnreadableCountersFailOpenAndLogOncePerEpisode(): void
    {
        $root = $this->fixtureRoot();
        $this->pmssWriteRelativeFile($root, 'proc/mounts', "/dev/gone / ext4 rw 0 0\n/dev/gone /home ext4 rw 0 0\n");
        $this->pmssWriteRelativeFile($root, 'run/pmss-watchdog-home-write', '1 1107279 1');
        $this->assertSame(0, $this->runCheck($root));
        $this->assertSame(0, $this->runCheck($root));
        $log = file_get_contents($root.'/var/log/pmss/checkHomeWriteWatchdog.log');
        $this->assertSame(1, substr_count($log, 'cannot decide, treating as not stuck'), 'A repeated reason is logged once.');
        $this->assertStringContainsString('/dev/gone does not resolve to a block device', $log);

        // The reason changes: it is logged again.
        $this->pmssWriteRelativeFile($root, 'proc/mounts', self::HOME_MOUNTS);
        $this->pmssWriteRelativeFile($root, 'proc/diskstats', " 259 0 nvme0n1 1 1 1 1 1 1 1 1\n");
        $this->assertSame(0, $this->runCheck($root));
        $this->assertSame(2, substr_count(file_get_contents($root.'/var/log/pmss/checkHomeWriteWatchdog.log'), 'cannot decide'));

        foreach (['proc/uptime' => 'x', 'sys/class/block/md2/inflight' => '??'] as $file => $broken) {
            $fixed = $this->fixtureRoot();
            $this->pmssWriteRelativeFile($fixed, $file, $broken);
            $this->assertSame(0, $this->runCheck($fixed), $file.' unreadable must not fail the check.');
        }
    }

    public function testRunUsesTheRootDeviceWhenHomeIsNotSeparate(): void
    {
        $root = $this->fixtureRoot();
        $this->pmssWriteFiles($root, ['proc/mounts' => "/dev/md1 / ext4 rw 0 0\n", 'dev/md1' => '', 'sys/class/block/md1/inflight' => "0 4\n",
            'proc/diskstats' => " 9 1 md1 5 0 5 5 77 0 5 5\n", 'run/pmss-watchdog-home-write' => '100 77 0']);
        $this->assertSame(5, $this->runCheck($root));
        $this->assertStringContainsString('device=md1', file_get_contents($root.'/var/log/pmss/checkHomeWriteWatchdog.log'));
    }

    public function testShimPassesOnlyStatusFiveAndMapsEveryInterpreterFailureToSuccess(): void
    {
        $root = $this->pmssMakeTempDir('pmss-watchdog-shim-');
        $php = escapeshellarg(PHP_BINARY);
        foreach ([
            [5, '<?php exit(5);'],
            [0, '<?php exit(0);'],
            [0, '<?php exit(255);'],
            [0, '<?php undefined_function_call();'],
            [0, '<?php this is a parse error'],
            [0, '<?php exit(1);'],
            [0, '<?php exit(253);'],
        ] as $i => [$expected, $logic]) {
            $this->pmssWriteRelativeFile($root, 'logic'.$i.'.php', $logic);
            $this->assertSame($expected, $this->shim($root, $php, $root.'/logic'.$i.'.php', 'test'), 'Logic: '.$logic);
        }
        $this->assertSame(0, $this->shim($root, $php, $root.'/no-such-logic.php', 'test'), 'A missing script exits 1 under php.');
        $this->assertSame(0, $this->shim($root, escapeshellarg($root.'/no-php'), $root.'/logic0.php', 'test'), 'A missing interpreter exits 127.');
        $this->assertSame(5, $this->shim($root, $php, $root.'/logic1.php', 'repair 5'), 'A repair call must keep the reboot.');
    }

    public function testInstallerShipsTheCheckAndRestartsOnlyWhenSomethingChanged(): void
    {
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/update/apps/watchdog.php', [
            "'/template.watchdog.home-write-check.sh'",
            "'/template.watchdog.home-write-check.php'",
            "'/home-write-check.sh'",
            "['Installing watchdog home write check', pmssBuildCommand('install', ['-m', '0755', \$homeCheckTemplate, \$homeCheckTarget])],",
            'function pmssWatchdogInstalledContents(array $paths): array',
        ]);
        $this->assertOrderedStrings([
            "\$wasActive = pmssSystemdUnitState('is-active', 'watchdog.service') === 'active';",
            "\$installedBefore = pmssWatchdogInstalledContents(",
            "['Installing watchdog configuration'",
            "['Installing watchdog home write check'",
            "runStep('Enabling watchdog service', 'systemctl enable --now watchdog.service');",
            'if ($wasActive && $installedBefore !== pmssWatchdogInstalledContents(',
            "'systemctl stop watchdog.service'",
            "'systemctl start watchdog.service'",
        ], $source);
        $this->assertStringNotContainsString('systemctl restart watchdog.service', $source, 'restart is cancelled by wd_keepalive on Debian');
        // Both the shim and its PHP logic must be present before activation.
        $this->assertStringContainsString('!is_file($homeCheckTemplate) || !is_file($homeCheckLogic)', $source);
    }

    public function testShimTemplateStaysThinGlue(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(self::SHIM, [
            '/usr/bin/php -n /etc/seedbox/config/template.watchdog.home-write-check.php test',
            '[ "$1" = repair ] && exit 5',
            '[ "$?" -eq 5 ] && exit 5',
        ]);
        $lines = count(array_filter(explode("\n", $this->pmssReadRepoFile(self::SHIM)), function (string $line): bool {
            return trim($line) !== '' && $line[0] !== '#';
        }));
        $this->assertTrue($lines <= 6, 'The shim is glue, not logic: '.$lines.' code lines');
    }

    /** A fixture tree shaped like the kernel files the check reads: a healthy busy md2 under /home. */
    private function fixtureRoot(): string
    {
        $root = $this->pmssMakeTempDir('pmss-home-write-');
        $this->pmssWriteFiles($root, [
            'proc/mounts' => self::HOME_MOUNTS,
            'proc/diskstats' => self::DISKSTATS,
            'proc/uptime' => "1000.50 900.25\n",
            'proc/loadavg' => "107.00 163.00 161.00 4/900 1\n",
            'proc/pressure/io' => "some avg10=96.90 avg60=90.00 avg300=80.00 total=1\nfull avg10=50.00 avg60=40.00 avg300=30.00 total=1\n",
            'dev/md1' => '', 'dev/md2' => '',
            'sys/class/block/md2/inflight' => "      33      617\n",
            'sys/class/block/md1/inflight' => "       0        0\n",
            'run/.keep' => '', 'var/log/pmss/.keep' => '',
        ]);
        return $root;
    }

    /** Run the real script file as the daemon's shim does, against a fixture tree. */
    private function runCheck(string $root): int
    {
        $command = escapeshellarg(PHP_BINARY).' -n '.escapeshellarg($this->pmssRepoPath(self::LOGIC)).' test';
        $result = $this->pmssExecShellCommand($command, ['PMSS_HOME_WRITE_ROOT' => $root]);
        // `php -n` is how the shim runs it: a fatal here (a missing extension function) would be hidden by the
        // shim as "not stuck" and the check would never fire, so a run must end cleanly and print nothing.
        $this->assertSame('', $result['output'], 'The check must run silently under php -n.');
        $this->assertTrue(in_array($result['rc'], [0, 5], true), 'Unexpected status '.$result['rc']);
        return $result['rc'];
    }

    /** Run the shim template with the interpreter and logic paths swapped for fixtures. */
    private function shim(string $root, string $php, string $logic, string $args): int
    {
        $script = str_replace(['/usr/bin/php', '/etc/seedbox/config/template.watchdog.home-write-check.php'],
            [trim($php, "'"), $logic], $this->pmssReadRepoFile(self::SHIM));
        $this->pmssWriteRelativeFile($root, 'shim.sh', $script);
        return $this->pmssExecShellCommand('sh '.escapeshellarg($root.'/shim.sh').' '.$args, [], '>/dev/null 2>&1')['rc'];
    }
}
