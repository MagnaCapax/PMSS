<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/mediaStackWatchdog.php';
require_once __DIR__.'/../common/TestCase.php';

class MediaStackWatchdogTest extends TestCase
{
    public function testDefinitionsCoverTheFiveManagedPanelApps(): void
    {
        $this->assertSame(array('sonarr', 'radarr', 'prowlarr', 'sabnzbd', 'cloudplow', 'jellyfin'), array_keys(\pmssMediaStackWatchdogAppDefinitions()));
    }

    public function testExpectedAppsOnlyIncludesPreparedAccountDirectories(): void
    {
        $home = $this->pmssMakeTempDir('media-stack-watchdog-apps-');
        $this->pmssWriteRelativeFile($home, '.config/sonarr/config.xml', '<Config />');
        $this->pmssWriteRelativeFile($home, '.bin/Radarr/Radarr.dll', 'binary');

        $apps = \pmssMediaStackWatchdogExpectedApps($home);

        $this->assertSame(array('sonarr', 'radarr'), array_keys($apps));
    }

    public function testRepeatedMissingSessionsBecomeFailedAtThirdObservation(): void
    {
        $apps = array('sonarr' => \pmssMediaStackWatchdogAppDefinitions()['sonarr']);
        $probe = static function (): bool { return false; };

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

        $status = \pmssMediaStackWatchdogSnapshot('alice', $apps, $previous, static function (): bool { return true; });

        $this->assertSame('healthy', $status['state']);
        $this->assertSame('running', $status['apps']['sonarr']['state']);
        $this->assertSame(0, $status['apps']['sonarr']['consecutiveFailures']);
    }

    public function testRunUserPublishesTheObservedAccountSnapshot(): void
    {
        $homeRoot = $this->pmssMakeTempDir('media-stack-watchdog-run-');
        $home = $homeRoot.'/alice';
        $this->pmssWriteRelativeFile($home, '.config/jellyfin/config/network.xml', '<NetworkConfiguration />');
        $this->pmssWriteRelativeFile($home, '.config/sonarr/config.xml', '<Config />');

        $status = \pmssMediaStackWatchdogRunUser('alice', $homeRoot, static function (): bool { return true; });

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

        $this->assertTrue(\pmssMediaStackWatchdogStatusWrite($path, $status));
        $this->assertEquals($status, \pmssJsonFileReadAssoc($path, true));
        $this->assertSame(0644, fileperms($path) & 0777);
    }

    public function testUnsafeStatusPathIsRejected(): void
    {
        $this->assertSame('', \pmssMediaStackWatchdogStatusPath('/tmp/../home/alice'));
        $this->assertFalse(\pmssMediaStackWatchdogStatusWrite('/tmp/relative-status.json', array()));
    }
}
