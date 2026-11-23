<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/mediaStackWatchdog.php';
require_once __DIR__.'/../common/TestCase.php';

class MediaStackWatchdogTest extends TestCase
{
    public function testSessionProbeRejectsForeignAndMultiPaneCommands(): void
    {
        $this->assertFalse(\pmssMediaStackWatchdogPaneOwned('sonarr', "bash\n"));
        $this->assertTrue(\pmssMediaStackWatchdogPaneOwned('sonarr', 'cd "$HOME/.bin/Sonarr" && dotnet Sonarr.dll'));
        $this->assertFalse(\pmssMediaStackWatchdogPaneOwned('sonarr', "cd \"\$HOME/.bin/Sonarr\"\nbash\n"));
        $this->assertFalse(\pmssMediaStackWatchdogPaneOwned('radarr', 'cd "$HOME/.bin/Sonarr" && dotnet Sonarr.dll'));
        $this->assertFalse(\pmssMediaStackWatchdogPaneOwned('other', 'cd "$HOME/.bin/Sonarr" && dotnet Sonarr.dll'));
    }

    public function testDefinitionsCoverTheManagedPanelApps(): void
    {
        $this->assertSame(array('sonarr', 'radarr', 'prowlarr', 'sabnzbd', 'cloudplow', 'jellyfin', 'autobrr'), array_keys(\pmssMediaStackWatchdogAppDefinitions()));
    }

    public function testExpectedAppsOnlyIncludesPreparedAccountDirectories(): void
    {
        $home = $this->pmssMakeTempDir('media-stack-watchdog-apps-');
        $this->pmssWriteFiles($home, ['.config/sonarr/config.xml' => '<Config />',
            '.bin/Radarr/Radarr.dll' => 'binary', '.bin/autobrr/autobrr' => 'binary']);

        $apps = \pmssMediaStackWatchdogExpectedApps($home);

        $this->assertSame(array('sonarr', 'radarr', 'autobrr'), array_keys($apps));
    }

    public function testRepeatedMissingSessionsBecomeFailedAtThirdObservation(): void
    {
        $apps = array('sonarr' => \pmssMediaStackWatchdogAppDefinitions()['sonarr']);
        $probe = self::pmssMakeCallbacks(['probe' => false])['probe'];

        $first = \pmssMediaStackWatchdogSnapshot('fixtur01', $apps, array(), $probe);
        $second = \pmssMediaStackWatchdogSnapshot('fixtur01', $apps, $first, $probe);
        $third = \pmssMediaStackWatchdogSnapshot('fixtur01', $apps, $second, $probe);

        $this->assertSame('stopped', $first['apps']['sonarr']['state']);
        $this->assertSame(2, $second['apps']['sonarr']['consecutiveFailures']);
        $this->assertSame('failed', $third['apps']['sonarr']['state']);
        $this->assertSame('failed', $third['state']);
    }

    public function testHealthySessionClearsPreviousFailureCount(): void
    {
        $apps = array('sonarr' => \pmssMediaStackWatchdogAppDefinitions()['sonarr']);
        $previous = array('apps' => array('sonarr' => array('state' => 'failed', 'consecutiveFailures' => 8)));

        $status = \pmssMediaStackWatchdogSnapshot('fixtur01', $apps, $previous, self::pmssMakeCallbacks(['probe' => true])['probe']);

        $this->assertSame('healthy', $status['state']);
        $this->pmssAssertArraySubsetSame(['state' => 'running', 'consecutiveFailures' => 0], $status['apps']['sonarr']);
    }

    public function testOverallStateFollowsMixedAppStatesInEitherOrder(): void
    {
        $apps = array_intersect_key(\pmssMediaStackWatchdogAppDefinitions(), array_flip(['sonarr', 'radarr']));
        foreach ([[0, true, true, 'healthy'], [0, true, false, 'degraded'], [2, false, true, 'failed'], [2, false, false, 'failed']] as [$failures, $sonarr, $radarr, $expected]) {
            $previous = ['apps' => ['sonarr' => ['consecutiveFailures' => $failures]]];
            $probe = static function ($user, $app) use ($sonarr, $radarr): bool { return $app === 'sonarr' ? $sonarr : $radarr; };
            foreach ([$apps, array_reverse($apps, true)] as $ordered) {
                $status = \pmssMediaStackWatchdogSnapshot('fixtur01', $ordered, $previous, $probe);
                $this->assertSame($expected, $status['state']);
            }
        }
        $this->assertSame('healthy', \pmssMediaStackWatchdogSnapshot('fixtur01', [], [], self::pmssMakeCallbacks(['probe' => false])['probe'])['state']);
    }

    public function testDeliberatelyStoppedAppIsOffWithoutFailureOrAlert(): void
    {
        $home = $this->pmssMakeTempDir('pmss-watchdog-off-');
        $this->pmssWriteFile($home.'/.sonarrDisable', '');
        $apps = array('sonarr' => \pmssMediaStackWatchdogAppDefinitions()['sonarr']);
        $previous = array('apps' => array('sonarr' => array('state' => 'failed', 'consecutiveFailures' => 9)));
        $probes = [];
        $status = \pmssMediaStackWatchdogSnapshot('fixtur01', $apps, $previous,
            self::pmssMakeCallbacks(['probe' => false], $probes)['probe'], $home);
        $this->pmssAssertArraySubsetSame(['state' => 'off', 'consecutiveFailures' => 0], $status['apps']['sonarr']);
        $this->assertSame('healthy', $status['state']);
        $this->assertSame([], $probes);
    }

    public function testRunUserPublishesTheObservedAccountSnapshot(): void
    {
        $homeRoot = $this->pmssMakeTempDir('media-stack-watchdog-run-');
        $home = $homeRoot.'/fixtur01';
        $this->pmssWriteFiles($home, ['.config/jellyfin/config/network.xml' => '<NetworkConfiguration />',
            '.config/sonarr/config.xml' => '<Config />']);

        $status = \pmssMediaStackWatchdogRunUser('fixtur01', $homeRoot, self::pmssMakeCallbacks(['probe' => true])['probe']);

        $this->assertSame('healthy', $status['state']);
        $this->assertSame('running', $status['apps']['sonarr']['state']);
        $this->assertEquals($status, \pmssJsonFileReadAssoc($home.'/.media-stack-status.json', true));
    }

    public function testInstallThatSkippedJellyfinIsObservedThroughItsAliasBlock(): void
    {
        // The installer continues without Jellyfin when no usable FFmpeg 4.4+ exists, so no network.xml,
        // but it always writes the "PMSS Media stack aliases" block into ~/.bashrc.custom.
        $homeRoot = $this->pmssMakeTempDir('media-stack-watchdog-nojellyfin-');
        $home = $homeRoot.'/fixtur01';
        $this->pmssWriteFiles($home, ['.config/sonarr/config.xml' => '<Config />',
            '.bashrc.custom' => "export FOO=1\n# PMSS Media stack aliases (updated Nov 2025)\nalias sonarr='true'\n"]);

        $status = \pmssMediaStackWatchdogRunUser('fixtur01', $homeRoot, self::pmssMakeCallbacks(['probe' => true])['probe']);

        $this->assertSame('healthy', $status['state']);
        $this->assertSame(array('sonarr'), array_keys($status['apps']));
        $this->assertEquals($status, \pmssJsonFileReadAssoc($home.'/.media-stack-status.json', true));
    }

    public function testJellyfinInstallWithoutAliasBlockIsStillObserved(): void
    {
        $homeRoot = $this->pmssMakeTempDir('media-stack-watchdog-noaliases-');
        $home = $homeRoot.'/fixtur01';
        $this->pmssWriteFiles($home, ['.config/jellyfin/config/network.xml' => '<NetworkConfiguration />',
            '.config/sonarr/config.xml' => '<Config />', '.bashrc.custom' => "export FOO=1\n"]);

        $status = \pmssMediaStackWatchdogRunUser('fixtur01', $homeRoot, self::pmssMakeCallbacks(['probe' => true])['probe']);

        $this->assertSame('running', $status['apps']['sonarr']['state']);
        $this->assertTrue(is_file($home.'/.media-stack-status.json'));
    }

    public function testAppDirectoriesWithNeitherMarkerAreNotObserved(): void
    {
        $homeRoot = $this->pmssMakeTempDir('media-stack-watchdog-nomarker-');
        $home = $homeRoot.'/fixtur01';
        $this->pmssWriteFiles($home, ['.config/sonarr/config.xml' => '<Config />', '.bashrc.custom' => "alias ll='ls -l'\n"]);
        $bare = $homeRoot.'/fixtur02';
        $this->pmssWriteFiles($bare, ['.config/radarr/config.xml' => '<Config />']);

        $this->assertSame(null, \pmssMediaStackWatchdogRunUser('fixtur01', $homeRoot, self::pmssMakeCallbacks(['probe' => true])['probe']));
        $this->assertSame(null, \pmssMediaStackWatchdogRunUser('fixtur02', $homeRoot, self::pmssMakeCallbacks(['probe' => true])['probe']));
        $this->assertFalse(file_exists($home.'/.media-stack-status.json'));
        $this->assertFalse(file_exists($bare.'/.media-stack-status.json'));
    }

    public function testSymlinkedAliasFileIsNotTrusted(): void
    {
        // The alias file is account-editable and read as root: a symlink must never be followed.
        $homeRoot = $this->pmssMakeTempDir('media-stack-watchdog-symlink-');
        $home = $homeRoot.'/fixtur01';
        $this->pmssWriteFiles($home, ['.config/sonarr/config.xml' => '<Config />']);
        $this->pmssWriteFiles($homeRoot, ['elsewhere/aliases' => "# PMSS Media stack aliases (updated Nov 2025)\n"]);
        $this->assertTrue(symlink($homeRoot.'/elsewhere/aliases', $home.'/.bashrc.custom'));

        $this->assertSame(null, \pmssMediaStackWatchdogRunUser('fixtur01', $homeRoot, self::pmssMakeCallbacks(['probe' => true])['probe']));
        $this->assertFalse(file_exists($home.'/.media-stack-status.json'));
    }

    public function testRootCronSchedulesTheContextFirstWatchdogEntrypoint(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/seedbox/config/root.cron', [
            '/scripts/cron/mediaStackInstancesCheck.php', '/var/log/pmss/mediaStackInstancesCheck.log',
        ]);
    }

    public function testStatusWriteIsReadableAndUsesCustomerMode(): void
    {
        $home = $this->pmssMakeTempDir('media-stack-watchdog-status-');
        $path = \pmssMediaStackWatchdogStatusPath($home);
        $status = array('state' => 'healthy', 'apps' => array('sonarr' => array('state' => 'running')));

        $this->assertTrue(\pmssMediaStackWatchdogStatusWrite($home, $path, $status));
        $this->assertEquals($status, \pmssJsonFileReadAssoc($path, true));
        $this->assertSame(0644, fileperms($path) & 0777);
    }

    public function testUnsafeStatusPathIsRejected(): void
    {
        $this->assertSame('', \pmssMediaStackWatchdogStatusPath('/tmp/../home/fixtur01'));
        $home = $this->pmssMakeTempDir('media-stack-watchdog-unsafe-status-');
        $this->assertFalse(\pmssMediaStackWatchdogStatusWrite($home, '/tmp/relative-status.json', array()));
    }

    public function testInvalidStatusEncodingLeavesNoTemporaryFiles(): void
    {
        $home = $this->pmssMakeTempDir('media-stack-encoding-');
        $path = $home.'/.media-stack-status.json';
        foreach ([false, true] as $existing) {
            if ($existing) $this->pmssWriteFile($path, 'previous status');
            foreach ([['invalid' => "\xff"], ['invalid' => INF], ['invalid' => NAN]] as $status) {
                $this->assertFalse(\pmssMediaStackWatchdogStatusWrite($home, $path, $status));
                $this->assertSame([], array_values(array_diff(glob($home.'/.media-stack-status.*'), [$path])));
                $this->assertSame($existing, file_exists($path));
                if ($existing) $this->assertSame('previous status', file_get_contents($path));
            }
        }
    }

}
