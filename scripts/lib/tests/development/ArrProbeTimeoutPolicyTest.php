<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/arr.php';

class ArrProbeTimeoutPolicyTest extends TestCase
{
    public function testVersionProbeCommandKeepsProwlarrProbeBounded(): void
    {
        $command = \pmssArrVersionProbeCommand('/opt/Prowlarr/Prowlarr', '--version');

        $this->assertStringContainsAllStrings([
            'timeout --kill-after=5s 50s ',
            "'/opt/Prowlarr/Prowlarr'",
            "'--version'",
            '2>/dev/null',
        ], $command);
        $legacyTimeout = 'timeout '.'10 ';
        $this->assertStringNotContainsString($legacyTimeout, $command);
    }

    public function testSupportedStarrAppsUseSharedInstallPathPreset(): void
    {
        foreach (\pmssArrSupportedApps() as $app) {
            $config = \pmssArrAppConfig($app);

            $this->assertTrue(is_array($config), $app.' config should exist');
            $this->assertSame('/opt/'.$app, (string) $config['install_path']);
        }
    }

    public function testSharedAppVersionProbeCapturesOutput(): void
    {
        $output = \pmssAppVersionProbeOutput('printf %s '.escapeshellarg('v1.2.3'), 5);

        $this->assertSame('v1.2.3', $output);
    }

    public function testSharedAppVersionProbeLogsTimeouts(): void
    {
        $timeoutLog = $this->pmssMakeTempFile('pmss-app-version-timeout-');
        $output = 'not-run';
        $previousCorrelationId = $GLOBALS['PMSS_CORRELATION_ID_CACHE'] ?? null;

        try {
            $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = null;
            $this->pmssWithEnv([
                'PMSS_TIMEOUT_FIRE_LOG' => $timeoutLog,
                'PMSS_CORRELATION_ID' => 'app-version-probe-test',
            ], function () use (&$output): void {
                $output = \pmssAppVersionProbeOutput('php -r '.escapeshellarg('sleep(2);'), 1);
            });
        } finally {
            $GLOBALS['PMSS_CORRELATION_ID_CACHE'] = $previousCorrelationId;
        }

        $this->assertSame('', $output);
        $lines = file($timeoutLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertTrue(is_array($lines) && count($lines) >= 1, 'expected app probe timeout-fire JSONL entry');
        $data = json_decode((string) end($lines), true);
        $this->assertTrue(is_array($data), 'expected app probe timeout-fire JSON payload');
        $this->assertSame('timeout_fired', $data['event'] ?? '');
        $this->assertSame(1, $data['intended_seconds'] ?? 0);
        $this->assertSame(124, $data['exit_status'] ?? 0);
        $this->assertSame('SIGTERM', $data['signal'] ?? '');
        $this->assertSame('app-version-probe-test', $data['correlation_id'] ?? '');
    }

    public function testServarrEntrypointReplacesPerAppWrappers(): void
    {
        $appRoot = dirname(__DIR__, 2).'/update/apps';

        $this->assertSame(['Lidarr', 'Prowlarr', 'Radarr', 'Readarr', 'Sonarr'], \pmssArrSupportedApps());
        $this->assertTrue(is_file($appRoot.'/servarr.php'), 'single Servarr entrypoint should exist');
        foreach (['lidarr.php', 'prowlarr.php', 'radarr.php', 'readarr.php', 'sonarr.php'] as $wrapper) {
            $this->assertFalse(is_file($appRoot.'/'.$wrapper), $wrapper.' should not remain as a parallel app wrapper');
        }
        $this->assertStringContainsString('pmssArrUpdateSupportedApps();', (string) @file_get_contents($appRoot.'/servarr.php'));
    }

    public function testReleaseAssetSelectionPrefersTargetArchitectureOverGenericBuild(): void
    {
        $asset = \pmssArrReleaseAssetSelect([
            [
                'assets' => [
                    ['name' => 'Radarr.develop.1.2.3.linux-arm64.tar.gz', 'browser_download_url' => 'https://example.invalid/arm64'],
                    ['name' => 'Radarr.develop.1.2.3.linux.tar.gz', 'browser_download_url' => 'https://example.invalid/generic'],
                    ['name' => 'Radarr.develop.1.2.3.linux-x64.tar.gz', 'browser_download_url' => 'https://example.invalid/x64'],
                ],
            ],
        ], '/Radarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i', 'amd64');

        $this->assertSame(['1.2.3.', 'https://example.invalid/x64', 'Radarr.develop.1.2.3.linux-x64.tar.gz'], $asset);
    }
}
