<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';
require_once dirname(__DIR__, 2).'/update/apps/arr.php';

class ArrUpdateTest extends TestCase
{
    public function testUpdateInstallsReleaseFromLocalArchiveAndCleansWorkspace(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-arr-update-install-');
        $app = 'PmssArrInstall'.bin2hex(random_bytes(3));
        $installPath = $baseDir.'/install';
        $extractDir = 'PackageDir';
        $archivePath = $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'new']);
        $metadataPath = $this->writeMetadata($baseDir, basename($archivePath), $archivePath);
        $shimDir = $this->writeCurlShim($baseDir);
        $workPattern = sys_get_temp_dir().'/'.strtolower($app).'-*';

        try {
            $this->pmssWithEnv([
                'PATH' => $shimDir.':'.(string) getenv('PATH'),
                'PMSS_LOG_FILE' => $baseDir.'/runtime.log',
            ], function () use ($app, $installPath, $metadataPath, $extractDir): void {
                $this->runArrUpdate($app, $installPath, $metadataPath, $extractDir);
            });

            $this->assertTrue(is_dir($installPath), 'expected install path to be created');
            $this->assertEquals('new', (string) @file_get_contents($installPath.'/marker.txt'));
            $this->assertEquals([], glob($workPattern) ?: [], 'expected workspace cleanup after install');
        } finally {
            $this->cleanup($baseDir);
            $this->cleanupGlob($workPattern);
        }
    }

    public function testUpdateKeepsExistingInstallWhenExtractionFails(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-arr-update-extract-fail-');
        $app = 'PmssArrExtractFail'.bin2hex(random_bytes(3));
        $installPath = $baseDir.'/install';
        $extractDir = 'ExpectedDir';
        $archivePath = $this->createArchive($baseDir, 'WrongDir', ['marker.txt' => 'replacement']);
        $metadataPath = $this->writeMetadata($baseDir, basename($archivePath), $archivePath);
        $shimDir = $this->writeCurlShim($baseDir);
        $workPattern = sys_get_temp_dir().'/'.strtolower($app).'-*';

        @mkdir($installPath, 0755, true);
        @file_put_contents($installPath.'/marker.txt', 'existing');

        try {
            $this->pmssWithEnv([
                'PATH' => $shimDir.':'.(string) getenv('PATH'),
                'PMSS_LOG_FILE' => $baseDir.'/runtime.log',
            ], function () use ($app, $installPath, $metadataPath, $extractDir): void {
                $this->runArrUpdate($app, $installPath, $metadataPath, $extractDir);
            });

            $this->assertEquals('existing', (string) @file_get_contents($installPath.'/marker.txt'));
            $this->assertEquals([], glob($workPattern) ?: [], 'expected workspace cleanup after extract failure');
        } finally {
            $this->cleanup($baseDir);
            $this->cleanupGlob($workPattern);
        }
    }

    public function testUpdatePrefersHostArchitectureAssetWhenMultipleBuildsMatch(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-arr-update-arch-match-');
        $app = 'PmssArrArch'.bin2hex(random_bytes(3));
        $installPath = $baseDir.'/install';
        $extractDir = 'PackageDir';
        $metadataPath = $this->writeMetadataForAssets($baseDir, [
            ['bundle-1.2.3-arm.tar.gz', $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'arm'], 'bundle-1.2.3-arm.tar.gz')],
            ['bundle-1.2.3-arm64.tar.gz', $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'arm64'], 'bundle-1.2.3-arm64.tar.gz')],
            ['bundle-1.2.3-x64.tar.gz', $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'x64'], 'bundle-1.2.3-x64.tar.gz')],
        ]);
        $shimDir = $this->writeCurlShim($baseDir, 'amd64');

        try {
            $this->pmssWithEnv([
                'PATH' => $shimDir.':'.(string) getenv('PATH'),
                'PMSS_LOG_FILE' => $baseDir.'/runtime.log',
            ], function () use ($app, $installPath, $metadataPath, $extractDir): void {
                $this->runArrUpdate($app, $installPath, $metadataPath, $extractDir, '/bundle-([0-9.]+)-.*\.tar\.gz/');
            });

            $this->assertEquals('x64', (string) @file_get_contents($installPath.'/marker.txt'));
        } finally {
            $this->cleanup($baseDir);
        }
    }

    public function testUpdateFallsBackToGenericAssetWhenArchSpecificBuildDoesNotMatch(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-arr-update-arch-fallback-');
        $app = 'PmssArrArchFallback'.bin2hex(random_bytes(3));
        $installPath = $baseDir.'/install';
        $extractDir = 'PackageDir';
        $metadataPath = $this->writeMetadataForAssets($baseDir, [
            ['bundle-1.2.3-arm.tar.gz', $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'arm'], 'bundle-1.2.3-arm.tar.gz')],
            ['bundle-1.2.3.tar.gz', $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'generic'])],
        ]);
        $shimDir = $this->writeCurlShim($baseDir, 'amd64');

        try {
            $this->pmssWithEnv([
                'PATH' => $shimDir.':'.(string) getenv('PATH'),
                'PMSS_LOG_FILE' => $baseDir.'/runtime.log',
            ], function () use ($app, $installPath, $metadataPath, $extractDir): void {
                $this->runArrUpdate($app, $installPath, $metadataPath, $extractDir, '/bundle-([0-9.]+)(?:-.*)?\.tar\.gz/');
            });

            $this->assertEquals('generic', (string) @file_get_contents($installPath.'/marker.txt'));
        } finally {
            $this->cleanup($baseDir);
        }
    }

    public function testUpdateRejectsUnsafeExtractDirectoryBeforeFetchingMetadata(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-arr-update-unsafe-extract-');
        $output = '';

        try {
            $this->pmssWithEnv([], function () use ($baseDir, &$output): void {
                ob_start();
                $this->runArrUpdate('PmssArrUnsafeExtract', $baseDir.'/install', $baseDir.'/missing-releases.json', '../PackageDir');
                $output = (string) ob_get_clean();
            });

            $this->assertStringContainsString('Invalid updater configuration: extract_dir', $output);
            $this->assertStringNotContainsString('Unable to fetch release metadata', $output);
        } finally {
            $this->cleanup($baseDir);
        }
    }

    public function testUpdateDoesNotReportInstalledWhenInstallParentIsMissing(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-arr-update-move-fail-');
        $app = 'PmssArrMoveFail'.bin2hex(random_bytes(3));
        $installPath = $baseDir.'/missing-parent/install';
        $extractDir = 'PackageDir';
        $archivePath = $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'new']);
        $metadataPath = $this->writeMetadata($baseDir, basename($archivePath), $archivePath);
        $shimDir = $this->writeCurlShim($baseDir);
        $output = '';

        try {
            $this->pmssWithEnv([
                'PATH' => $shimDir.':'.(string) getenv('PATH'),
            ], function () use ($app, $installPath, $metadataPath, $extractDir, &$output): void {
                ob_start();
                $this->runArrUpdate($app, $installPath, $metadataPath, $extractDir);
                $output = (string) ob_get_clean();
            });

            $this->assertFalse(is_dir($installPath), 'install path should stay absent when its parent directory is missing');
            $this->assertStringContainsString('Install parent directory missing; refusing to replace application', $output);
            $this->assertStringNotContainsString('Installed version 1.2.3', $output);
        } finally {
            $this->cleanup($baseDir);
        }
    }

    public function testUpdateUsesTimeoutWrappedBinaryProbeBeforeSkippingMatchingInstall(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-arr-update-timeout-probe-');
        $app = 'PmssArrProbe'.bin2hex(random_bytes(3));
        $installPath = $baseDir.'/install';
        $extractDir = 'PackageDir';
        $archivePath = $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'replacement']);
        $metadataPath = $this->writeMetadata($baseDir, basename($archivePath), $archivePath);
        $shimDir = $this->writeCurlShim($baseDir);
        $timeoutLog = $baseDir.'/timeout.log';

        @mkdir($installPath, 0755, true);
        @file_put_contents($installPath.'/marker.txt', 'existing');
        $this->writeVersionBinary($installPath.'/'.$app, '1.2.3');
        $this->writeTimeoutShim($shimDir);

        try {
            $this->pmssWithEnv([
                'PATH' => $shimDir.':'.(string) getenv('PATH'),
                'PMSS_ARR_TEST_TIMEOUT_LOG' => $timeoutLog,
                'PMSS_LOG_FILE' => $baseDir.'/runtime.log',
            ], function () use ($app, $installPath, $metadataPath, $extractDir): void {
                $this->runArrUpdate($app, $installPath, $metadataPath, $extractDir);
            });

            $this->assertEquals('existing', (string) @file_get_contents($installPath.'/marker.txt'));
            $timeoutOutput = (string) @file_get_contents($timeoutLog);
            $this->assertStringContainsString('10 '.($installPath.'/'.$app).' --version', $timeoutOutput);
            $this->assertStringNotContainsString(' -v', $timeoutOutput, 'expected --version probe to satisfy the version check without a fallback probe');
        } finally {
            $this->cleanup($baseDir);
        }
    }

    public function testUpdateReinstallsWhenTimeoutWrappedVersionProbesReturnNoVersion(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-arr-update-timeout-fallback-');
        $app = 'PmssArrTimeout'.bin2hex(random_bytes(3));
        $installPath = $baseDir.'/install';
        $extractDir = 'PackageDir';
        $archivePath = $this->createArchive($baseDir, $extractDir, ['marker.txt' => 'replacement']);
        $metadataPath = $this->writeMetadata($baseDir, basename($archivePath), $archivePath);
        $shimDir = $this->writeCurlShim($baseDir);
        $timeoutLog = $baseDir.'/timeout.log';

        @mkdir($installPath, 0755, true);
        @file_put_contents($installPath.'/marker.txt', 'existing');
        $this->writeVersionBinary($installPath.'/'.$app, '9.9.9');
        $this->writeTimeoutShim($shimDir);

        try {
            $this->pmssWithEnv([
                'PATH' => $shimDir.':'.(string) getenv('PATH'),
                'PMSS_ARR_TEST_TIMEOUT_LOG' => $timeoutLog,
                'PMSS_ARR_TEST_TIMEOUT_MODE' => 'always-timeout',
                'PMSS_LOG_FILE' => $baseDir.'/runtime.log',
            ], function () use ($app, $installPath, $metadataPath, $extractDir): void {
                $this->runArrUpdate($app, $installPath, $metadataPath, $extractDir);
            });

            $this->assertEquals('replacement', (string) @file_get_contents($installPath.'/marker.txt'));
            $timeoutOutput = (string) @file_get_contents($timeoutLog);
            $this->assertStringContainsString('10 '.($installPath.'/'.$app).' --version', $timeoutOutput);
            $this->assertStringContainsString('10 '.($installPath.'/'.$app).' -v', $timeoutOutput);
        } finally {
            $this->cleanup($baseDir);
        }
    }

    private function createArchive(string $baseDir, string $extractDir, array $files, string $archiveName = 'bundle-1.2.3.tar.gz'): string
    {
        $archiveRoot = $baseDir.'/archive';
        $payloadDir = $archiveRoot.'/'.$extractDir;
        @mkdir($payloadDir, 0755, true);
        foreach ($files as $name => $contents) {
            @file_put_contents($payloadDir.'/'.$name, $contents);
        }

        $archivePath = $baseDir.'/'.$archiveName;
        $output = [];
        $rc = 0;
        exec(
            sprintf(
                'tar -czf %s -C %s %s 2>&1',
                escapeshellarg($archivePath),
                escapeshellarg($archiveRoot),
                escapeshellarg($extractDir)
            ),
            $output,
            $rc
        );
        $this->assertEquals(0, $rc, implode("\n", $output));
        return $archivePath;
    }

    private function runArrUpdate(
        string $app,
        string $installPath,
        string $metadataPath,
        string $extractDir,
        string $assetPattern = '/bundle-([0-9.]+)\.tar\.gz/'
    ): void {
        \pmssArrUpdate([
            'app' => $app,
            'install_path' => $installPath,
            'releases_url' => $metadataPath,
            'asset_pattern' => $assetPattern,
            'extract_dir' => $extractDir,
            'user_agent' => 'PMSS-Test',
        ]);
    }

    private function writeMetadata(string $baseDir, string $assetName, string $archivePath): string
    {
        $metadataPath = $baseDir.'/releases.json';
        @file_put_contents($metadataPath, json_encode([
            [
                'assets' => [
                    [
                        'name' => $assetName,
                        'browser_download_url' => 'file://'.$archivePath,
                    ],
                ],
            ],
        ]));
        return $metadataPath;
    }

    private function writeMetadataForAssets(string $baseDir, array $assets): string
    {
        $metadataPath = $baseDir.'/releases.json';
        $payload = ['assets' => []];
        foreach ($assets as $asset) {
            $payload['assets'][] = [
                'name' => $asset[0],
                'browser_download_url' => 'file://'.$asset[1],
            ];
        }

        @file_put_contents($metadataPath, json_encode([$payload]));
        return $metadataPath;
    }

    private function writeCurlShim(string $baseDir, string $architecture = ''): string
    {
        $shimDir = $baseDir.'/bin';
        @mkdir($shimDir, 0755, true);
        $curl = $shimDir.'/curl';
        @file_put_contents($curl, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
target=''
source_url=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o)
      target="$2"
      shift 2
      ;;
    -s|-S|-L|--fail)
      shift
      ;;
    *)
      source_url="$1"
      shift
      ;;
  esac
done
cp "${source_url#file://}" "$target"
SH
        );
        @chmod($curl, 0755);

        if ($architecture !== '') {
            $dpkg = $shimDir.'/dpkg';
            @file_put_contents($dpkg, "#!/usr/bin/env bash\nif [ \"\${1:-}\" = \"--print-architecture\" ]; then\n  printf '%s\\n' ".escapeshellarg($architecture)."\n  exit 0\nfi\nexit 1\n");
            @chmod($dpkg, 0755);
        }

        return $shimDir;
    }

    private function writeVersionBinary(string $path, string $version): void
    {
        @file_put_contents($path, "#!/usr/bin/env bash\nif [ \"\${1:-}\" = \"--version\" ] || [ \"\${1:-}\" = \"-v\" ]; then\n  printf '%s\\n' ".escapeshellarg($version)."\n  exit 0\nfi\nexit 1\n");
        @chmod($path, 0755);
    }

    private function writeTimeoutShim(string $shimDir): void
    {
        $timeout = $shimDir.'/timeout';
        @file_put_contents($timeout, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "${PMSS_ARR_TEST_TIMEOUT_LOG:?}"
if [ "${PMSS_ARR_TEST_TIMEOUT_MODE:-pass-through}" = "always-timeout" ]; then
  exit 124
fi
if [ "$#" -lt 2 ]; then
  exit 125
fi
shift
exec "$@"
SH
        );
        @chmod($timeout, 0755);
    }

    private function cleanupGlob(string $pattern): void
    {
        foreach (glob($pattern) ?: [] as $path) {
            $this->cleanup($path);
        }
    }

}
