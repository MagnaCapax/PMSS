<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/mediaStackWatchdog.php';
require_once __DIR__.'/../common/TestCase.php';

class MediaStackWatchdogTest extends TestCase
{
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

    public function testStatusPublicationFailuresPreservePreviousBytesAndCleanStaging(): void
    {
        foreach (['open', 'false', 'zero', 'short', 'chmod', 'rename', 'writeThrow', 'chmodThrow', 'renameThrow', 'writeError', 'success'] as $mode) {
            $home = $this->pmssMakeTempDir('media-stack-publication-');
            $path = $home.'/.media-stack-status.json';
            file_put_contents($path, 'previous status');
            $result = $this->statusWriteFixture($home, $mode);
            $throws = strpos($mode, 'Throw') !== false || $mode === 'writeError';
            $this->assertSame($throws ? null : $mode === 'success', $result['result'], $mode);
            $this->assertSame($throws, $result['sameThrowable'], $mode);
            $this->assertSame([], array_values(array_diff(glob($home.'/.media-stack-status.*'), [$path])), $mode);
            $expected = $mode === 'success' ? \pmssJsonEncodePrettyLine(['state' => 'healthy']) : 'previous status';
            $this->assertSame($expected, file_get_contents($path), $mode);
            if ($mode === 'success') $this->assertSame(0644, fileperms($path) & 0777);
        }
    }

    private function statusWriteFixture(string $home, string $mode): array
    {
        // Intercept only publication operations; path validation and fixture I/O stay real.
        $script = <<<'PHP'
namespace MediaStackStatusFixture;
function tempnam($directory, $prefix) {
    return $GLOBALS['mode'] === 'open' ? false : \tempnam($directory, $prefix);
}
function file_put_contents($path, $data, $flags) {
    $mode = $GLOBALS['mode'];
    if ($mode === 'writeThrow' || $mode === 'writeError') throw $GLOBALS['throwable'];
    if ($mode === 'false') return false;
    if ($mode === 'zero') return 0;
    return \file_put_contents($path, $mode === 'short' ? substr($data, 0, -1) : $data, $flags);
}
function chmod($path, $permissions) {
    if ($GLOBALS['mode'] === 'chmodThrow') throw $GLOBALS['throwable'];
    return $GLOBALS['mode'] === 'chmod' ? false : \chmod($path, $permissions);
}
function rename($source, $target) {
    if ($GLOBALS['mode'] === 'renameThrow') throw $GLOBALS['throwable'];
    return $GLOBALS['mode'] === 'rename' ? false : \rename($source, $target);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/mediaStackWatchdog.php', 'MediaStackStatusFixture');
        $script .= <<<'PHP'
$GLOBALS['mode'] = getenv('PMSS_TEST_STATUS_MODE');
$GLOBALS['throwable'] = $GLOBALS['mode'] === 'writeError' ? new \Error('write') : new \RuntimeException('publication');
$home = getenv('PMSS_TEST_STATUS_HOME');
$result = null;
$sameThrowable = false;
try {
    $result = pmssMediaStackWatchdogStatusWrite($home, $home.'/.media-stack-status.json', ['state' => 'healthy']);
} catch (\Throwable $caught) {
    $sameThrowable = $caught === $GLOBALS['throwable'];
}
echo json_encode(['result' => $result, 'sameThrowable' => $sameThrowable]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_STATUS_MODE' => $mode, 'PMSS_TEST_STATUS_HOME' => $home]);
    }
}
