<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class UpdateHelpersEnvCodenameTest extends TestCase
{
    public function testGetDistroVersionStripsSuffix(): void
    {
        $file = $this->tempFile('version', "ID=debian\nVERSION_ID=\"12 (bookworm)\"\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('12', \getDistroVersion());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroVersionReturnsRawWhenNonNumeric(): void
    {
        $file = $this->tempFile('version', "ID=debian\nVERSION_ID=sid\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('sid', \getDistroVersion());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroNameEmptyWhenMissing(): void
    {
        $file = $this->tempFile('noname', "VERSION_ID=11\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('', \getDistroName());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroCodenameLowercasesAndTrims(): void
    {
        $file = $this->tempFile('codename', "ID=debian\nVERSION_CODENAME=  BULLSEYE  \n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('bullseye', \getDistroCodename());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testGetDistroCodenameEmptyWhenNotPresent(): void
    {
        $file = $this->tempFile('nocodename', "ID=debian\nVERSION_ID=12\n");
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('', \getDistroCodename());
        $this->clearEnv('PMSS_OS_RELEASE_PATH');
    }

    public function testPmssVersionFromCodenameUnknownReturnsZero(): void
    {
        $this->assertEquals(0, \pmssVersionFromCodename('unknown-planet'));
    }

    public function testGetPmssVersionTrimsWhitespace(): void
    {
        $file = $this->tempFile('version-file', "git/main:2025-01-01\n\n");
        $this->assertEquals('git/main:2025-01-01', \getPmssVersion($file));
    }

    public function testGetPmssVersionReturnsUnknownForEmptyFile(): void
    {
        $file = $this->tempFile('empty-version', '');
        $this->assertEquals('unknown', \getPmssVersion($file));
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

