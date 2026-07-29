<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';
require_once dirname(__DIR__, 2).'/update/apps/arr.php';

class ArrInstallerNoExecPolicyTest extends TestCase
{
    /**
     * INSTALL !== EXECUTION: the installer must expose no way to run an installed
     * binary. Asserting on the capability (not on a line number) keeps this test
     * valid as the module evolves.
     */
    public function testInstallerExposesNoBinaryExecutionSurface(): void
    {
        foreach (['pmssArrVersionProbeRun', 'pmssArrVersionProbeCommand'] as $removed) {
            $this->assertFalse(
                function_exists($removed),
                $removed.' must not exist: PMSS may never execute an installed ARR binary'
            );
        }
        $this->assertFalse(
            defined('PMSS_ARR_VERSION_PROBE_TIMEOUT_SECONDS'),
            'A probe timeout constant implies a probe; bounding the parent wait does not bound the child'
        );
    }

    /**
     * The installer legitimately shells out to curl/tar/mv and to `dpkg
     * --print-architecture`; what it must never do is treat an artifact in the
     * install tree as something to run. `is_executable()` is a permission test, not
     * a trust test, and the shared app-version probe only exists to run binaries.
     */
    public function testInstallerSourceContainsNoBinaryTrustOrProbePrimitives(): void
    {
        $source = (string) @file_get_contents(dirname(__DIR__, 2).'/update/apps/arr.php');

        $this->assertStringContainsString('pmssArrInstalledVersionRead', $source, 'sanity: installer source loaded');
        foreach (['is_executable', 'pmssAppVersionProbe', 'proc_open'] as $primitive) {
            $this->assertStringNotContainsString(
                $primitive,
                $source,
                'arr.php must not reach for '.$primitive.' -- installed binaries are never run'
            );
        }
    }

    public function testInstalledVersionReadUsesMetadataOnlyAndIgnoresBinaries(): void
    {
        $installPath = $this->pmssMakeTempDir('pmss-arr-install-', 0700);

        // An executable that would daemonize if run must never be consulted.
        $binary = $installPath.'/Radarr';
        $this->pmssWriteExecutableFile($binary, "#!/usr/bin/env bash\ntouch ".escapeshellarg($installPath.'/EXECUTED')."\nprintf '9.9.9\\n'\n");
        $this->assertTrue(\pmssArrInstalledVersionRead($installPath, 'Radarr') === null, 'no metadata means no version');
        $this->assertFalse(is_file($installPath.'/EXECUTED'), 'version detection must not execute the installed binary');

        // Metadata is the only accepted source.
        @file_put_contents($installPath.'/version.txt', "6.2.0.10390\n");
        $this->assertSame('6.2.0.10390', \pmssArrInstalledVersionRead($installPath, 'Radarr'));
    }

    public function testInstalledVersionReadRejectsSymlinkedVersionMarkers(): void
    {
        $installPath = $this->pmssMakeTempDir('pmss-arr-symlink-', 0700);
        $outside = $this->pmssMakeTempFile('pmss-arr-outside-');
        @file_put_contents($outside, "1.2.3\n");
        $this->pmssCreateSymlinkOrSkip($outside, $installPath.'/version.txt');

        $this->assertTrue(
            \pmssArrInstalledVersionRead($installPath, 'Radarr') === null,
            'root must not follow a version marker symlink planted in a foreign-owned install tree'
        );
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

        // The captured version is normalized through pmssArrVersionExtract so it matches
        // the value read back from version.txt; the raw greedy capture is "1.2.3." and a
        // trailing separator would make every comparison unequal and reinstall forever.
        $this->assertSame(['1.2.3', 'https://example.invalid/x64', 'Radarr.develop.1.2.3.linux-x64.tar.gz'], $asset);
    }

    public function testReleaseAssetVersionMatchesTheValuePersistedAndReadBack(): void
    {
        $asset = \pmssArrReleaseAssetSelect([
            ['assets' => [[
                'name' => 'Radarr.develop.6.2.0.10390.linux-core-x64.tar.gz',
                'browser_download_url' => 'https://example.invalid/x64',
            ]]],
        ], '/Radarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i', 'amd64');

        $installPath = $this->pmssMakeTempDir('pmss-arr-roundtrip-', 0700);
        @file_put_contents($installPath.'/version.txt', $asset[0].PHP_EOL);

        $this->assertSame(
            $asset[0],
            \pmssArrInstalledVersionRead($installPath, 'Radarr'),
            'the persisted version must round-trip, otherwise every update reinstalls every app'
        );
    }

    public function testReleaseActivationPathGuardHandlesSafeAndUnsafeBoundaries(): void
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

        $this->assertFalse(\pmssArrReleaseActivationPathsAreSafe(
            $workDir,
            'pmss-arr-work-',
            $extractPath,
            '/opt'
        ));

        $linkWorkDir = $this->pmssMakeTempDir('pmss-arr-work-', 0700);
        $linkExtractPath = $linkWorkDir.'/Radarr';
        $outside = $this->pmssMakeTempDir('pmss-arr-outside-', 0700);
        $this->pmssCreateSymlinkOrSkip($outside, $linkExtractPath);

        $this->assertFalse(\pmssArrReleaseActivationPathsAreSafe(
            $linkWorkDir,
            'pmss-arr-work-',
            $linkExtractPath,
            $installParent.'/Radarr'
        ));
    }
}
