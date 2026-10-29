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
        $this->pmssWriteRelativeFile($home, '.config/sonarr/config.xml', '<Config />');
        $this->pmssWriteRelativeFile($home, '.bin/Radarr/Radarr.dll', 'binary');
        $this->pmssWriteRelativeFile($home, '.bin/autobrr/autobrr', 'binary');

        $apps = \pmssMediaStackWatchdogExpectedApps($home);

        $this->assertSame(array('sonarr', 'radarr', 'autobrr'), array_keys($apps));
    }

    public function testRepeatedMissingSessionsBecomeFailedAtThirdObservation(): void
    {
        $apps = array('sonarr' => \pmssMediaStackWatchdogAppDefinitions()['sonarr']);
        $probe = self::pmssMakeCallbacks(['probe' => false])['probe'];

        $first = \pmssMediaStackWatchdogSnapshot('alice', $apps, array(), $probe);
        $second = \pmssMediaStackWatchdogSnapshot('alice', $apps, $first, $probe);
        $third = \pmssMediaStackWatchdogSnapshot('alice', $apps, $second, $probe);

        $this->assertSame('stopped', $first['apps']['sonarr']['state']);
        $this->assertSame(2, $second['apps']['sonarr']['consecutiveFailures']);
        $this->assertSame('failed', $third['apps']['sonarr']['state']);
        $this->assertSame('failed', $third['state']);
    }

    public function testHealthySessionClearsPreviousFailureCount(): void
    {
        $apps = array('sonarr' => \pmssMediaStackWatchdogAppDefinitions()['sonarr']);
        $previous = array('apps' => array('sonarr' => array('state' => 'failed', 'consecutiveFailures' => 8)));

        $status = \pmssMediaStackWatchdogSnapshot('alice', $apps, $previous, self::pmssMakeCallbacks(['probe' => true])['probe']);

        $this->assertSame('healthy', $status['state']);
        $this->assertSame('running', $status['apps']['sonarr']['state']);
        $this->assertSame(0, $status['apps']['sonarr']['consecutiveFailures']);
    }

    public function testOverallStateFollowsMixedAppStatesInEitherOrder(): void
    {
        $apps = array_intersect_key(\pmssMediaStackWatchdogAppDefinitions(), array_flip(['sonarr', 'radarr']));
        foreach ([[0, true, true, 'healthy'], [0, true, false, 'degraded'], [2, false, true, 'failed'], [2, false, false, 'failed']] as [$failures, $sonarr, $radarr, $expected]) {
            $previous = ['apps' => ['sonarr' => ['consecutiveFailures' => $failures]]];
            $probe = static function ($user, $app) use ($sonarr, $radarr): bool { return $app === 'sonarr' ? $sonarr : $radarr; };
            foreach ([$apps, array_reverse($apps, true)] as $ordered) {
                $status = \pmssMediaStackWatchdogSnapshot('alice', $ordered, $previous, $probe);
                $this->assertSame($expected, $status['state']);
            }
        }
        $this->assertSame('healthy', \pmssMediaStackWatchdogSnapshot('alice', [], [], self::pmssMakeCallbacks(['probe' => false])['probe'])['state']);
    }

    public function testDeliberatelyStoppedAppIsOffWithoutFailureOrAlert(): void
    {
        $home = $this->pmssMakeTempDir('pmss-watchdog-off-');
        $this->pmssWriteFile($home.'/.sonarrDisable', '');
        $apps = array('sonarr' => \pmssMediaStackWatchdogAppDefinitions()['sonarr']);
        $previous = array('apps' => array('sonarr' => array('state' => 'failed', 'consecutiveFailures' => 9)));
        $probes = 0;
        $status = \pmssMediaStackWatchdogSnapshot('alice', $apps, $previous, static function () use (&$probes): bool {
            $probes++;
            return false;
        }, $home);
        $this->assertSame('off', $status['apps']['sonarr']['state']);
        $this->assertSame(0, $status['apps']['sonarr']['consecutiveFailures']);
        $this->assertSame('healthy', $status['state']);
        $this->assertSame(0, $probes);
    }

    public function testRunUserPublishesTheObservedAccountSnapshot(): void
    {
        $homeRoot = $this->pmssMakeTempDir('media-stack-watchdog-run-');
        $home = $homeRoot.'/alice';
        $this->pmssWriteRelativeFile($home, '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');
        $this->pmssWriteRelativeFile($home, '.config/sonarr/config.xml', '<Config />');

        $status = \pmssMediaStackWatchdogRunUser('alice', $homeRoot, self::pmssMakeCallbacks(['probe' => true])['probe']);

        $this->assertSame('healthy', $status['state']);
        $this->assertSame('running', $status['apps']['sonarr']['state']);
        $this->assertEquals($status, \pmssJsonFileReadAssoc($home.'/.media-stack-status.json', true));
    }

    public function testRootCronSchedulesTheContextFirstWatchdogEntrypoint(): void
    {
        $cron = $this->pmssReadRepoFile('etc/seedbox/config/root.cron');

        $this->assertStringContainsString('/scripts/cron/mediaStackInstancesCheck.php', $cron);
        $this->assertStringContainsString('/var/log/pmss/mediaStackInstancesCheck.log', $cron);
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
        $this->assertSame('', \pmssMediaStackWatchdogStatusPath('/tmp/../home/alice'));
        $home = $this->pmssMakeTempDir('media-stack-watchdog-unsafe-status-');
        $this->assertFalse(\pmssMediaStackWatchdogStatusWrite($home, '/tmp/relative-status.json', array()));
    }

    public function testInvalidStatusEncodingLeavesNoTemporaryFiles(): void
    {
        $home = $this->pmssMakeTempDir('media-stack-encoding-');
        $path = $home.'/.media-stack-status.json';
        foreach ([false, true] as $existing) {
            if ($existing) file_put_contents($path, 'previous status');
            foreach ([['invalid' => "\xff"], ['invalid' => INF], ['invalid' => NAN]] as $status) {
                $this->assertFalse(\pmssMediaStackWatchdogStatusWrite($home, $path, $status));
                $this->assertSame([], array_values(array_diff(glob($home.'/.media-stack-status.*'), [$path])));
                $this->assertSame($existing, file_exists($path));
                if ($existing) $this->assertSame('previous status', file_get_contents($path));
            }
        }
    }

}
