<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class DistroRepoSelectionEdgeTest extends TestCase
{
    public function testDetectDistroLowercasesId(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=Debian',
            'VERSION_ID="11"',
            'VERSION_CODENAME=BULLSEYE',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('debian', $info['name']);
        $this->assertEquals(11, $info['version']);
        $this->assertEquals('bullseye', $info['codename']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testDetectDistroNormalisesUppercaseCodename(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID=12',
            'VERSION_CODENAME=BOOKWORM',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('bookworm', $info['codename']);
        $this->assertEquals(12, $info['version']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testDetectDistroUnknownCodenameKeepsVersion(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID="77"',
            'VERSION_CODENAME=aurora',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals(77, $info['version']);
        $this->assertEquals('aurora', $info['codename']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testDetectDistroWhitespaceInCodename(): void
    {
        $osRelease = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID=13',
            'VERSION_CODENAME="  trixie  "',
        ]);
        putenv('PMSS_OS_RELEASE_PATH='.$osRelease);
        \pmssResetOsReleaseCache();

        $info = \pmssDetectDistro();
        $this->assertEquals('trixie', $info['codename']);
        $this->assertEquals(13, $info['version']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testDetectDistroResetCacheSwitchesFiles(): void
    {
        $first = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID=11',
            'VERSION_CODENAME=bullseye',
        ]);
        $second = $this->writeOsRelease([
            'ID=debian',
            'VERSION_ID=12',
            'VERSION_CODENAME=bookworm',
        ]);

        putenv('PMSS_OS_RELEASE_PATH='.$first);
        \pmssResetOsReleaseCache();
        $firstInfo = \pmssDetectDistro();
        $this->assertEquals(11, $firstInfo['version']);

        putenv('PMSS_OS_RELEASE_PATH='.$second);
        \pmssResetOsReleaseCache();
        $secondInfo = \pmssDetectDistro();
        $this->assertEquals(12, $secondInfo['version']);

        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    private function writeOsRelease(array $lines): string
    {
        return $this->tempFile('os-release', implode("\n", $lines)."\n");
    }

    private function tempFile(string $prefix, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmss-'.$prefix.'-');
        if ($path === false) {
            $path = sys_get_temp_dir().'/pmss-'.$prefix.'-'.bin2hex(random_bytes(6));
        }
        file_put_contents($path, $content);
        return $path;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
    }
}
