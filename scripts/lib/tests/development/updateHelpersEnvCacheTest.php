<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class UpdateHelpersEnvCacheTest extends TestCase
{
    public function testGetOsReleaseDataUsesOverridePath(): void
    {
        $file = $this->pmssWriteTempFile('override', 'ID=custom', 'pmss-env');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('custom', \getOsReleaseData()['ID']);
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testGetOsReleaseDataCachesPerPath(): void
    {
        $file = $this->pmssWriteTempFile('cache', "ID=test\nVERSION_ID=1\n", 'pmss-env');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $first = \getOsReleaseData();
        file_put_contents($file, "ID=test\nVERSION_ID=2\n");
        $second = \getOsReleaseData();
        $this->assertEquals($first, $second);
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testResetOsReleaseCacheReloadsData(): void
    {
        $file = $this->pmssWriteTempFile('reload', "ID=test\nVERSION_ID=3\n", 'pmss-env');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        \getOsReleaseData();
        file_put_contents($file, "ID=test\nVERSION_ID=4\n");
        \pmssResetOsReleaseCache();
        $data = \getOsReleaseData();
        $this->assertEquals('4', $data['VERSION_ID']);
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testResetCacheLeavesOtherPathsUntouched(): void
    {
        $first = $this->pmssWriteTempFile('first', "ID=alpha\nVERSION_ID=1\n", 'pmss-env');
        $second = $this->pmssWriteTempFile('second', "ID=beta\nVERSION_ID=2\n", 'pmss-env');

        putenv('PMSS_OS_RELEASE_PATH='.$first);
        \pmssResetOsReleaseCache();
        $firstData = \getOsReleaseData();
        $this->assertEquals('alpha', $firstData['ID']);

        putenv('PMSS_OS_RELEASE_PATH='.$second);
        \pmssResetOsReleaseCache();
        $secondData = \getOsReleaseData();
        $this->assertEquals('beta', $secondData['ID']);

        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testGetOsReleaseDataHandlesMissingFile(): void
    {
        putenv('PMSS_OS_RELEASE_PATH=/nonexistent/os-release');
        \pmssResetOsReleaseCache();
        $data = \getOsReleaseData();
        $this->assertTrue(is_array($data));
        $this->assertEquals([], $data);
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }
}
