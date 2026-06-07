<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';
require_once dirname(__DIR__, 2).'/update/apps/arr.php';

class ArrProbeTimeoutPolicyTest extends TestCase
{
    public function testVersionProbeCommandKeepsSafeCommandStringWithoutCustomTimeoutWrapper(): void
    {
        $command = \pmssArrVersionProbeCommand('/opt/Prowlarr/Prowlarr', '--version');

        $this->assertStringContainsAllStrings([
            '/opt/Prowlarr/Prowlarr',
            "'--version'",
        ], $command);
        $this->assertStringNotContainsString('timeout ', $command);
    }

    public function testArrVersionProbeRunUsesSharedAppProbe(): void
    {
        $probe = $this->pmssMakeTempFile('pmss-arr-probe-');
        $this->pmssWriteExecutableFile($probe, "#!/usr/bin/env bash\nprintf 'Prowlarr 1.2.3\\n'\n");

        $this->assertSame('Prowlarr 1.2.3', \pmssArrVersionProbeRun($probe, '--version'));
    }

    public function testSupportedStarrAppsKeepCanonicalBranchMapOrder(): void
    {
        $this->assertSame([
            'Lidarr' => 'develop|master',
            'Prowlarr' => 'develop|master',
            'Radarr' => 'develop|master',
            'Readarr' => 'develop|master',
            'Sonarr' => 'main|develop',
        ], \PMSS_ARR_APP_BRANCHES);
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
        $data = pmssJsonLineFileLast($timeoutLog);
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

        $this->assertSame(['Lidarr', 'Prowlarr', 'Radarr', 'Readarr', 'Sonarr'], array_keys(\PMSS_ARR_APP_BRANCHES));
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

    public function testReleaseActivationPathGuardAllowsPrivateWorkChild(): void
    {
        $workDir = $this->pmssMakeTempDir('pmss-arr-work-', 0700);
        $extractPath = $workDir.'/Radarr';
        $installParent = $this->pmssMakeTempDir('pmss-arr-install-', 0700);
        @mkdir($extractPath, 0700);

        $this->assertTrue(\pmssArrReleaseActivationPathsAreSafe(
            $workDir,
            'pmss-arr-work-',
            $extractPath,
            $installParent.'/Radarr'
        ));
    }

    public function testReleaseActivationPathGuardRejectsUnsafeInstallRoot(): void
    {
        $workDir = $this->pmssMakeTempDir('pmss-arr-work-', 0700);
        $extractPath = $workDir.'/Radarr';
        @mkdir($extractPath, 0700);

        $this->assertFalse(\pmssArrReleaseActivationPathsAreSafe(
            $workDir,
            'pmss-arr-work-',
            $extractPath,
            '/opt'
        ));
    }

    public function testReleaseActivationPathGuardRejectsSymlinkedExtractPath(): void
    {
        $workDir = $this->pmssMakeTempDir('pmss-arr-work-', 0700);
        $outside = $this->pmssMakeTempDir('pmss-arr-outside-', 0700);
        $extractPath = $workDir.'/Radarr';
        $installParent = $this->pmssMakeTempDir('pmss-arr-install-', 0700);
        $this->pmssCreateSymlinkOrSkip($outside, $extractPath);

        $this->assertFalse(\pmssArrReleaseActivationPathsAreSafe(
            $workDir,
            'pmss-arr-work-',
            $extractPath,
            $installParent.'/Radarr'
        ));
    }
}
