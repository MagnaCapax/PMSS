<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/runtime.php';
require_once dirname(__DIR__, 2).'/update/apps/arr.php';

class ArrUpdateTest extends TestCase
{
    use FilesystemCleanupTrait;

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
                \pmssArrUpdate([
                    'app' => $app,
                    'install_path' => $installPath,
                    'releases_url' => $metadataPath,
                    'asset_pattern' => '/bundle-([0-9.]+)\.tar\.gz/',
                    'extract_dir' => $extractDir,
                    'user_agent' => 'PMSS-Test',
                ]);
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
                \pmssArrUpdate([
                    'app' => $app,
                    'install_path' => $installPath,
                    'releases_url' => $metadataPath,
                    'asset_pattern' => '/bundle-([0-9.]+)\.tar\.gz/',
                    'extract_dir' => $extractDir,
                    'user_agent' => 'PMSS-Test',
                ]);
            });

            $this->assertEquals('existing', (string) @file_get_contents($installPath.'/marker.txt'));
            $this->assertEquals([], glob($workPattern) ?: [], 'expected workspace cleanup after extract failure');
        } finally {
            $this->cleanup($baseDir);
            $this->cleanupGlob($workPattern);
        }
    }

    private function createArchive(string $baseDir, string $extractDir, array $files): string
    {
        $archiveRoot = $baseDir.'/archive';
        $payloadDir = $archiveRoot.'/'.$extractDir;
        @mkdir($payloadDir, 0755, true);
        foreach ($files as $name => $contents) {
            @file_put_contents($payloadDir.'/'.$name, $contents);
        }

        $archivePath = $baseDir.'/bundle-1.2.3.tar.gz';
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

    private function writeCurlShim(string $baseDir): string
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
        return $shimDir;
    }

    private function cleanupGlob(string $pattern): void
    {
        foreach (glob($pattern) ?: [] as $path) {
            $this->cleanup($path);
        }
    }

}
