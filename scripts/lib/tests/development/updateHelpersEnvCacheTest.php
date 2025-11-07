<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class UpdateHelpersEnvCacheTest extends TestCase
{
    public function testOsReleasePathDefaultsToEtc(): void
    {
        $this->assertEquals('/etc/os-release', \pmssOsReleasePath());
    }

    public function testOsReleasePathOverrideTakesPrecedence(): void
    {
        $file = $this->tempFile('override', 'ID=custom');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        $this->assertEquals($file, \pmssOsReleasePath());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetOsReleaseDataCachesPerPath(): void
    {
        $file = $this->tempFile('cache', "ID=test\nVERSION_ID=1\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $first = \getOsReleaseData();
        file_put_contents($file, "ID=test\nVERSION_ID=2\n");
        $second = \getOsReleaseData();
        $this->assertEquals($first, $second);
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testResetOsReleaseCacheReloadsData(): void
    {
        $file = $this->tempFile('reload', "ID=test\nVERSION_ID=3\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        \getOsReleaseData();
        file_put_contents($file, "ID=test\nVERSION_ID=4\n");
        \pmssResetOsReleaseCache();
        $data = \getOsReleaseData();
        $this->assertEquals('4', $data['VERSION_ID']);
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testResetCacheLeavesOtherPathsUntouched(): void
    {
        $first = $this->tempFile('first', "ID=alpha\nVERSION_ID=1\n");
        $second = $this->tempFile('second', "ID=beta\nVERSION_ID=2\n");

        putenv('PMSS_OS_RELEASE_PATH='.$first);
        \pmssResetOsReleaseCache();
        $firstData = \getOsReleaseData();
        $this->assertEquals('alpha', $firstData['ID']);

        putenv('PMSS_OS_RELEASE_PATH='.$second);
        \pmssResetOsReleaseCache();
        $secondData = \getOsReleaseData();
        $this->assertEquals('beta', $secondData['ID']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetOsReleaseDataHandlesMissingFile(): void
    {
        putenv('PMSS_OS_RELEASE_PATH=/nonexistent/os-release');
        \pmssResetOsReleaseCache();
        $data = \getOsReleaseData();
        $this->assertTrue(is_array($data));
        $this->assertEquals([], $data);
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testOsReleasePathOverrideClearsAfterUnset(): void
    {
        $file = $this->tempFile('override', 'ID=temp');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals($file, \pmssOsReleasePath());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
        $this->assertEquals('/etc/os-release', \pmssOsReleasePath());
    }

    private function tempFile(string $prefix, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmss-env-'.$prefix.'-');
        if ($path === false) {
            $path = sys_get_temp_dir().'/pmss-env-'.$prefix.'-'.bin2hex(random_bytes(6));
        }
        file_put_contents($path, $content);
        return $path;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
    }
}

