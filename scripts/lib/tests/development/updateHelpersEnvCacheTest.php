<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class UpdateHelpersEnvCacheTest extends TestCase
{
    public function testOsReleaseHelpersLoadStandaloneWithRuntimeDependency(): void
    {
        $file = $this->pmssWriteTempFile('standalone', "ID=debian\nVERSION_ID=12\n", 'pmss-env');
        $repoRoot = $this->pmssRepoRoot();
        $script = <<<'PHP'
putenv('PMSS_OS_RELEASE_PATH='.__OS_RELEASE__);
require_once __REPO_ROOT__.'/scripts/lib/update/osRelease.php';
\pmssResetOsReleaseCache();
echo \getOsReleaseData()['ID'] ?? '';
PHP;

        $script = str_replace(
            ['__REPO_ROOT__', '__OS_RELEASE__'],
            [var_export($repoRoot, true), var_export($file, true)],
            $script
        );

        $output = trim($this->pmssRunInlinePhp($script, $this->pmssTestModeEnv(), '2>&1'));

        $this->assertEquals('debian', $output);
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testGetOsReleaseDataUsesOverridePath(): void
    {
        $file = $this->pmssWriteTempFile('override', 'ID=custom', 'pmss-env');
        $this->pmssWithOsReleasePath($file, function (): void {
            $this->assertEquals('custom', \getOsReleaseData()['ID']);
        });
    }

    public function testGetOsReleaseDataCachesPerPath(): void
    {
        $file = $this->pmssWriteTempFile('cache', "ID=test\nVERSION_ID=1\n", 'pmss-env');
        $this->pmssWithOsReleasePath($file, function () use ($file): void {
            $first = \getOsReleaseData();
            file_put_contents($file, "ID=test\nVERSION_ID=2\n");
            $second = \getOsReleaseData();
            $this->assertEquals($first, $second);
        });
    }

    public function testResetOsReleaseCacheReloadsData(): void
    {
        $file = $this->pmssWriteTempFile('reload', "ID=test\nVERSION_ID=3\n", 'pmss-env');
        $this->pmssWithOsReleasePath($file, function () use ($file): void {
            \getOsReleaseData();
            file_put_contents($file, "ID=test\nVERSION_ID=4\n");
            \pmssResetOsReleaseCache();
            $data = \getOsReleaseData();
            $this->assertEquals('4', $data['VERSION_ID']);
        });
    }

    public function testResetCacheLeavesOtherPathsUntouched(): void
    {
        $first = $this->pmssWriteTempFile('first', "ID=alpha\nVERSION_ID=1\n", 'pmss-env');
        $second = $this->pmssWriteTempFile('second', "ID=beta\nVERSION_ID=2\n", 'pmss-env');

        $this->pmssWithOsReleasePath($first, function () use ($second): void {
            $firstData = \getOsReleaseData();
            $this->assertEquals('alpha', $firstData['ID']);

            $this->pmssWithOsReleasePath($second, function (): void {
                $secondData = \getOsReleaseData();
                $this->assertEquals('beta', $secondData['ID']);
            });
        });
    }

    public function testGetOsReleaseDataHandlesMissingFile(): void
    {
        $this->pmssWithOsReleasePath('/nonexistent/os-release', function (): void {
            $data = \getOsReleaseData();
            $this->assertTrue(is_array($data));
            $this->assertEquals([], $data);
        });
    }

    private function pmssWithOsReleasePath(string $file, callable $callback): void
    {
        $this->pmssWithEnv(['PMSS_OS_RELEASE_PATH' => $file], function () use ($callback): void {
            \pmssResetOsReleaseCache();
            try {
                $callback();
            } finally {
                \pmssResetOsReleaseCache();
            }
        });
    }
}
