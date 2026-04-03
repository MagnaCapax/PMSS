<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class DistroRepoSelectionEdgeTest extends TestCase
{
    public function testDetectDistroLowercasesId(): void
    {
        $osRelease = $this->pmssWriteTempFile('os-release', implode("\n", [
            'ID=Debian',
            'VERSION_ID="11"',
            'VERSION_CODENAME=BULLSEYE',
        ])."\n");
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('debian', $info['name']);
        $this->assertEquals(11, $info['version']);
        $this->assertEquals('bullseye', $info['codename']);

        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testDetectDistroNormalisesUppercaseCodename(): void
    {
        $osRelease = $this->pmssWriteTempFile('os-release', implode("\n", [
            'ID=debian',
            'VERSION_ID=12',
            'VERSION_CODENAME=BOOKWORM',
        ])."\n");
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('bookworm', $info['codename']);
        $this->assertEquals(12, $info['version']);

        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testDetectDistroUnknownCodenameKeepsVersion(): void
    {
        $osRelease = $this->pmssWriteTempFile('os-release', implode("\n", [
            'ID=debian',
            'VERSION_ID="77"',
            'VERSION_CODENAME=aurora',
        ])."\n");
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals(77, $info['version']);
        $this->assertEquals('aurora', $info['codename']);

        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testDetectDistroWhitespaceInCodename(): void
    {
        $osRelease = $this->pmssWriteTempFile('os-release', implode("\n", [
            'ID=debian',
            'VERSION_ID=13',
            'VERSION_CODENAME="  trixie  "',
        ])."\n");
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('trixie', $info['codename']);
        $this->assertEquals(13, $info['version']);

        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testDetectDistroResetCacheSwitchesFiles(): void
    {
        $first = $this->pmssWriteTempFile('os-release', implode("\n", [
            'ID=debian',
            'VERSION_ID=11',
            'VERSION_CODENAME=bullseye',
        ])."\n");
        $second = $this->pmssWriteTempFile('os-release', implode("\n", [
            'ID=debian',
            'VERSION_ID=12',
            'VERSION_CODENAME=bookworm',
        ])."\n");

        putenv('PMSS_OS_RELEASE_PATH='.$first);
        \pmssResetOsReleaseCache();
        $firstInfo = \pmssDetectDistro();
        $this->assertEquals(11, $firstInfo['version']);

        putenv('PMSS_OS_RELEASE_PATH='.$second);
        \pmssResetOsReleaseCache();
        $secondInfo = \pmssDetectDistro();
        $this->assertEquals(12, $secondInfo['version']);

        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }
}
