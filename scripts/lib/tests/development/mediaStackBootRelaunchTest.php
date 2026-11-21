<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/mediaStackWatchdog.php';
require_once __DIR__.'/../common/TestCase.php';

/** Once-per-boot media-stack relaunch (ADR 0302): hermetic, with an injected launcher and boot-ID source. */
class MediaStackBootRelaunchTest extends TestCase
{
    private const NOW = 1900000000;

    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-media-boot-relaunch-']; }

    /** Home with Sonarr, Radarr and Jellyfin installed, a web root, and a pre-restart snapshot. */
    private function pmssAccount(array $states, ?array $record = null, int $age = 3600): array
    {
        $homeRoot = $this->pmssMakeTempDir('pmss-media-boot-home-');
        $home = $homeRoot.'/fixtur01';
        $this->pmssWriteFiles($home, array_fill_keys(
            ['.config/sonarr/config.xml', '.config/radarr/config.xml', '.config/jellyfin/config/network.xml', 'www/index.html'], 'x'));
        $apps = array();
        foreach ($states as $app => $state) {
            $apps[$app] = array('label' => $app, 'state' => $state, 'consecutiveFailures' => $state === 'failed' ? 5 : 0);
        }
        // The tenant's uid must resolve, or the slice launcher builds a failing command.
        $this->pmssLaunchStubs($home);
        $snapshot = array('timestamp' => date('c', self::NOW - $age), 'username' => 'fixtur01', 'apps' => $apps);
        if ($record !== null) $snapshot['relaunch'] = $record;
        $this->pmssWriteRelativeFile($home, '.media-stack-status.json', json_encode($snapshot));
        return array($homeRoot, $home);
    }

    /** One watchdog pass with every app absent (the restart took them down). */
    private function pmssPass(string $homeRoot, string $bootId, float $uptime, array &$launches, int $now = self::NOW): array
    {
        $boot = array('bootId' => $bootId, 'uptime' => $uptime, 'now' => $now, 'launcher' => $this->pmssMakeArrayLogger($launches));
        [$status] = $this->pmssCaptureStdout(static function () use ($homeRoot, $boot) {
            return \pmssMediaStackWatchdogRunUser('fixtur01', $homeRoot, self::pmssMakeCallbacks(['probe' => false])['probe'], $boot);
        });
        return is_array($status) ? $status : array();
    }

    private function pmssPublished(string $home): array
    {
        return \pmssJsonFileReadAssoc($home.'/.media-stack-status.json', true) ?? array();
    }

    public function testFirstPassAfterRestartRelaunchesOnlyNotFailedAppsOnce(): void
    {
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running', 'radarr' => 'failed', 'jellyfin' => 'stopped'));
        $launches = array();

        $first = $this->pmssPass($homeRoot, 'boot-b', 1000.0, $launches);
        $this->assertSame(1, count($launches));
        $this->assertSame('sonarr,jellyfin', $this->pmssLaunchedIds($launches[0]));
        $this->pmssAssertArraySubsetSame(array('bootId' => 'boot-b', 'apps' => array('sonarr', 'jellyfin'), 'done' => true), $first['relaunch']);
        $this->assertSame($first['relaunch'], $this->pmssPublished($home)['relaunch']);

        // Apps that die after the relaunch stay down: further passes in this boot launch nothing.
        for ($pass = 0; $pass < 5; $pass++) {
            $status = $this->pmssPass($homeRoot, 'boot-b', 1120.0 + $pass * 120, $launches);
        }
        $this->assertSame(1, count($launches));
        $this->assertSame('failed', $status['apps']['sonarr']['state']);
        $this->assertTrue($status['relaunch']['done']);
    }

    public function testOffAndUnobservedAppsAreNeverSelected(): void
    {
        [$homeRoot] = $this->pmssAccount(array('sonarr' => 'off', 'jellyfin' => 'running'));
        $launches = array();

        $this->pmssPass($homeRoot, 'boot-b', 1000.0, $launches);

        $this->assertSame('jellyfin', $this->pmssLaunchedIds($launches[0]));
        $this->assertStringNotContainsString('sonarr', $launches[0]);
        $this->assertStringNotContainsString('radarr', $launches[0]);
    }

    public function testAllFailedSelectionLatchesWithoutLaunching(): void
    {
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'failed', 'radarr' => 'failed', 'jellyfin' => 'failed'));
        $launches = array();

        $this->pmssPass($homeRoot, 'boot-b', 1000.0, $launches);

        $this->assertSame(array(), $launches);
        $this->pmssAssertArraySubsetSame(array('bootId' => 'boot-b', 'apps' => array(), 'done' => true), $this->pmssPublished($home)['relaunch']);
    }

    public function testStaggerCarriesTheSelectionUntilTheAccountDelayHasPassed(): void
    {
        $delay = \rtorrentProcessStaggerDelay('fixtur01', PMSS_MEDIA_STACK_RELAUNCH_MAX_DELAY);
        $this->assertTrue($delay > 0 && $delay <= PMSS_MEDIA_STACK_RELAUNCH_MAX_DELAY, 'fixture user needs a non-zero stagger delay');
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running', 'radarr' => 'running'));
        $launches = array();

        // Deferred passes: the absent apps turn failed after three passes, the selection must survive that.
        for ($pass = 0; $pass < 4; $pass++) {
            $status = $this->pmssPass($homeRoot, 'boot-b', max(1.0, $delay - 1), $launches);
        }
        $this->assertSame(array(), $launches);
        $this->assertSame('failed', $status['apps']['sonarr']['state']);
        $this->pmssAssertArraySubsetSame(array('apps' => array('sonarr', 'radarr'), 'done' => false), $this->pmssPublished($home)['relaunch']);

        $this->pmssPass($homeRoot, 'boot-b', (float) $delay, $launches);
        $this->assertSame(1, count($launches));
        $this->assertSame('sonarr,radarr', $this->pmssLaunchedIds($launches[0]));
        $this->pmssPass($homeRoot, 'boot-b', $delay + 120.0, $launches);
        $this->assertSame(1, count($launches));
    }

    public function testPendingSelectionSurvivesASecondRestartBeforeTheLaunch(): void
    {
        $delay = \rtorrentProcessStaggerDelay('fixtur01', PMSS_MEDIA_STACK_RELAUNCH_MAX_DELAY);
        [$homeRoot] = $this->pmssAccount(array('sonarr' => 'running', 'radarr' => 'running'));
        $launches = array();
        for ($pass = 0; $pass < 4; $pass++) {
            $this->pmssPass($homeRoot, 'boot-b', max(1.0, $delay - 1), $launches);
        }

        $this->pmssPass($homeRoot, 'boot-c', 1000.0, $launches);

        $this->assertSame(1, count($launches));
        $this->assertSame('sonarr,radarr', $this->pmssLaunchedIds($launches[0]));
    }

    public function testFailedSnapshotWriteLaunchesNothingAndRetriesOnlyOnce(): void
    {
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running'));
        $launches = array();

        // A boot ID that cannot be encoded makes the snapshot write fail.
        $failed = $this->pmssPass($homeRoot, "\xffboot", 1000.0, $launches);
        $this->assertSame(array(), $launches);
        $this->assertTrue(isset($failed['relaunch']));
        $this->assertFalse(isset($this->pmssPublished($home)['relaunch']));

        $this->pmssPass($homeRoot, 'boot-b', 1120.0, $launches);
        $this->pmssPass($homeRoot, 'boot-b', 1240.0, $launches);
        $this->assertSame(1, count($launches));
    }

    public function testUnreadableBootIdNeverRelaunches(): void
    {
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running'));
        $launches = array();

        $this->pmssPass($homeRoot, '', 1000.0, $launches);

        $this->assertSame(array(), $launches);
        $this->assertFalse(isset($this->pmssPublished($home)['relaunch']));
    }

    public function testDeployOntoARunningHostRelaunchesNothingButARestartAfterwardsDoes(): void
    {
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running'), null, 30);
        $launches = array();

        // Snapshot written 30 s ago, host up for a day: pre-record snapshot of the same boot.
        $this->pmssPass($homeRoot, 'boot-a', 86400.0, $launches);
        $this->assertSame(array(), $launches);
        $this->pmssAssertArraySubsetSame(array('bootId' => 'boot-a', 'apps' => array(), 'done' => true), $this->pmssPublished($home)['relaunch']);

        $this->pmssPass($homeRoot, 'boot-a', 86520.0, $launches, self::NOW + 120);
        $this->assertSame(array(), $launches);

        // Restart: the record carries the old boot ID, so the new boot relaunches even with a fresh snapshot.
        $this->pmssPass($homeRoot, 'boot-b', 5000.0, $launches, self::NOW + 1000);
        $this->assertSame(1, count($launches));
    }

    public function testSnapshotFromAnotherHostOrEditedAfterBootNeverRelaunches(): void
    {
        // A transferred home (or a tenant edit) brings a record of another boot in a snapshot newer than this boot.
        $record = array('bootId' => 'source-boot', 'apps' => array('sonarr'), 'done' => true);
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running'), $record, 30);
        $launches = array();

        $this->pmssPass($homeRoot, 'boot-a', 86400.0, $launches);
        $this->assertSame(array(), $launches);
        $this->pmssAssertArraySubsetSame(array('bootId' => 'boot-a', 'done' => true), $this->pmssPublished($home)['relaunch']);

        // The copy arrives again on the next transfer pass: still nothing.
        [$homeRoot] = $this->pmssAccount(array('sonarr' => 'running'), array('bootId' => 'source-boot', 'apps' => array(), 'done' => false), 10);
        $this->pmssPass($homeRoot, 'boot-a', 86520.0, $launches);
        $this->assertSame(array(), $launches);
    }

    public function testSnapshotWithoutTimestampOrFirstObservationNeverRelaunches(): void
    {
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running'));
        $this->pmssWriteRelativeFile($home, '.media-stack-status.json', json_encode(array('apps' => array('sonarr' => array('state' => 'running')))));
        $launches = array();
        $this->pmssPass($homeRoot, 'boot-b', 1000.0, $launches);

        unlink($home.'/.media-stack-status.json');
        $this->pmssPass($homeRoot, 'boot-b', 1000.0, $launches);

        $this->assertSame(array(), $launches);
    }

    public function testSnapshotNamesAreNeverTrusted(): void
    {
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running'), array('bootId' => 'boot-old', 'apps' => array('sonarr', '$(id)', 'prowlarr', 7), 'done' => false));
        $launches = array();

        $this->pmssPass($homeRoot, 'boot-b', 1000.0, $launches);

        // Only an installed app from the fixed definitions survives: prowlarr is not installed, the rest are junk.
        $this->assertSame(1, count($launches));
        $this->assertSame('sonarr', $this->pmssLaunchedIds($launches[0]));
        $this->assertStringNotContainsString('prowlarr', $launches[0]);
        $this->assertStringNotContainsString('$(id)', $launches[0]);
    }

    public function testSuspendedAccountIsSkippedLoggedAndLatched(): void
    {
        [$homeRoot, $home] = $this->pmssAccount(array('sonarr' => 'running'));
        rename($home.'/www', $home.'/www-disabled');
        $launches = array();

        $this->pmssPass($homeRoot, 'boot-b', 1000.0, $launches);
        rename($home.'/www-disabled', $home.'/www');
        $this->pmssPass($homeRoot, 'boot-b', 1120.0, $launches);

        $this->assertSame(array(), $launches);
        $this->pmssAssertArraySubsetSame(array('done' => true, 'skipped' => 'suspended'), $this->pmssPublished($home)['relaunch']);
    }

    public function testMovedAccountIsSkipped(): void
    {
        [$homeRoot] = $this->pmssAccount(array('sonarr' => 'running'));
        $configDir = $this->pmssMakeTempDir('pmss-media-boot-config-');
        $this->pmssWriteRelativeFile($configDir, 'users/fixtur01.json', json_encode(array('movedUntil' => '2099-01-01')));
        $this->assertSame('2099-01-01', \pmssUserMovedUntil('fixtur01', new \UserConfigStore($configDir)));
        $launches = array();

        $this->pmssWithEnv(array('PMSS_CONFIG_DIR' => $configDir), function () use ($homeRoot, &$launches): void {
            $this->pmssPass($homeRoot, 'boot-b', 1000.0, $launches);
        });

        $this->assertSame(array(), $launches);
    }

    public function testBootContextReadersAcceptOnlyWellFormedValues(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-media-boot-proc-');
        $this->pmssWriteFiles($dir, ['boot_id' => "6f1e2a3c-0a4b-4c5d-8e9f-a1b2c3d4e5f6\n", 'bad_id' => "not a boot id\n",
            'uptime' => "12345.67 98765.43\n", 'bad_uptime' => "soon\n"]);

        $this->assertSame('6f1e2a3c-0a4b-4c5d-8e9f-a1b2c3d4e5f6', \pmssMediaStackBootIdRead($dir.'/boot_id'));
        $this->assertSame('', \pmssMediaStackBootIdRead($dir.'/bad_id'));
        $this->assertSame('', \pmssMediaStackBootIdRead($dir.'/missing'));
        $this->assertSame(12345.67, \pmssMediaStackBootUptimeRead($dir.'/uptime'));
        $this->assertSame(0.0, \pmssMediaStackBootUptimeRead($dir.'/bad_uptime'));
        $this->assertSame(0.0, \pmssMediaStackBootUptimeRead($dir.'/missing'));
    }

    public function testCronPassesTheDescriptorExportingLockAcquire(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/mediaStackInstancesCheck.php', array("}, 'pmssUserWatchdogLockAcquire');"));
    }

    /** Run a shell command through proc_open and read its output to EOF, as passthru() does. */
    private function pmssRunToEof(string $command): array
    {
        $process = proc_open(array('/bin/sh', '-c', $command.' 2>&1'), array(1 => array('pipe', 'w')), $pipes);
        $this->assertTrue(is_resource($process));
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return array('rc' => proc_close($process), 'output' => $output);
    }

    /** The comma-separated app list a built launch command passes to the installer. */
    private function pmssLaunchedIds(string $command): string
    {
        return preg_match('/PMSS_MEDIA_STACK_START_APPS=\W*([a-z,]*)/', $command, $match) === 1 ? $match[1] : '';
    }

    /** Stub the service manager so the built root command can run and be inspected. */
    private function pmssLaunchStubs(string $home): string
    {
        $log = $this->tempDir.'/stub.log';
        $bin = $this->pmssWriteExecutableFiles($this->tempDir.'/bin', array(
            'id' => "#!/bin/sh\n[ \"\$1\" = '-u' ] && [ \"\$2\" = 'fixtur01' ] && echo 2002 && exit 0\nexit 1\n",
            'systemctl' => "#!/bin/sh\nprintf 'systemctl %s\\n' \"\$*\" >> ".escapeshellarg($log)."\n",
            'systemd-run' => "#!/bin/sh\nprintf 'systemd-run %s\\n' \"\$*\" >> ".escapeshellarg($log)."\nwhile [ \"\$1\" != '--' ]; do shift; done\nshift\nexec \"\$@\"\n",
            'su' => "#!/bin/sh\nprintf 'su %s | %s\\n' \"\$1\" \"\$2\" >> ".escapeshellarg($log)."\nHOME=".escapeshellarg($home)." exec /bin/sh -c \"\$3\"\n",
        ));
        $this->pmssTrackEnvOverrides($this->pmssPathPrefixedEnvironment($bin));
        return $log;
    }

    public function testBuiltCommandRunsAsTheTenantInItsSliceDetachedAndWithoutHoldingTheLock(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-boot-launch-');
        $log = $this->pmssLaunchStubs($home);
        $lockDir = $this->pmssMakeTempDir('pmss-media-boot-locks-', 0700);
        $this->pmssTrackEnvOverrides(array('PMSS_RUNTIME_LOCK_DIR' => $lockDir));
        $this->pmssTrackEnvKeys(array(PMSS_UPDATE_LOCK_FDS_ENV));
        // A copy with the mode, whose installer outlives the watchdog pass.
        $this->pmssWriteRelativeFile($home, 'install-media-stack.sh', "case \"\$1\" in\n\t--start-stopped) sleep 2; printf '%s' \"\$PMSS_MEDIA_STACK_START_APPS\" > \"\$HOME/ran\" ;;\nesac\n");

        $lockPath = \pmssRuntimeLockPath('pmss-mediaStackInstancesCheck.lock');
        // PHP in the test sandbox opens its own lock descriptors close-on-exec, so a leak cannot be
        // reproduced here; the contract is the close prefix leading the command, plus a free lock below.
        $lock = \pmssUserWatchdogLockAcquire($lockPath);
        $this->assertTrue($lock !== false);
        $command = \pmssMediaStackBootRelaunchCommand('fixtur01', array('radarr', 'sonarr'));
        $this->assertSame(0, strpos($command, \pmssLockChildClosePrefix()));
        $this->assertTrue(\pmssLockChildClosePrefix() !== '');

        // The launch returns at once even though the installer sleeps: nothing keeps its output pipe open.
        $started = microtime(true);
        $result = $this->pmssRunToEof($command);
        $this->assertTrue(microtime(true) - $started < 1.5, 'the launch must not wait for the installer');
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame('', $result['output']);

        // The background installer holds no descriptor of the cron lock.
        \pmssLockHandleRelease($lock);
        $again = \pmssLockFileAcquire($lockPath, true);
        $this->assertTrue($again !== false, 'the cron lock must be free while the relaunched installer runs');
        \pmssLockHandleRelease($again);

        $stubLog = $this->pmssReadFileOrEmpty($log);
        $this->assertStringContainsString('systemctl start user-2002.slice', $stubLog);
        $this->assertStringContainsString('--slice=user-2002.slice', $stubLog);
        $this->assertStringContainsString('su fixtur01 | -c', $stubLog);
        $this->assertStringNotContainsString('su - ', $stubLog);

        for ($wait = 0; $wait < 60 && !is_file($home.'/ran'); $wait++) usleep(100000);
        $this->assertSame('sonarr,radarr', $this->pmssReadFileOrEmpty($home.'/ran'));
    }

    public function testInstallerCopyWithoutTheStartStoppedModeIsNeverRun(): void
    {
        $home = $this->pmssMakeTempDir('pmss-media-boot-oldcopy-');
        $this->pmssLaunchStubs($home);
        // An older copy ignores unknown arguments and would run the full installer.
        $this->pmssWriteRelativeFile($home, 'install-media-stack.sh', "touch \"\$HOME/full-install-ran\"\n");

        $result = $this->pmssExecShellCommand(\pmssMediaStackBootRelaunchCommand('fixtur01', array('sonarr')));
        usleep(500000);

        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertFalse(file_exists($home.'/full-install-ran'));
    }

    public function testSelectionIsEscapedIntoTheTenantCommand(): void
    {
        $command = \pmssMediaStackBootRelaunchInnerCommand(array('sonarr', "x'; touch /tmp/pwned; '", 'jellyfin'));

        $this->assertStringContainsString("PMSS_MEDIA_STACK_START_APPS='sonarr,jellyfin'", $command);
        $this->assertStringNotContainsString('pwned', $command);
        $this->assertSame(1, preg_match('/^cd ~; \{ grep -q -- .--start-stopped\). install-media-stack\.sh && /', $command));
        $this->assertSame(1, preg_match('/\; \} >\/dev\/null 2>&1 <\/dev\/null &$/', $command));
    }
}
