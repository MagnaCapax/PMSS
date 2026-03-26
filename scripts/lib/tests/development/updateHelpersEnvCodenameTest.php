<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class UpdateHelpersEnvCodenameTest extends TestCase
{
    public function testGetDistroVersionStripsSuffix(): void
    {
        $file = $this->pmssWriteTempFile('version', "ID=debian\nVERSION_ID=\"12 (bookworm)\"\n", 'pmss-env');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('12', \getDistroVersion());
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testGetDistroVersionReturnsRawWhenNonNumeric(): void
    {
        $file = $this->pmssWriteTempFile('version', "ID=debian\nVERSION_ID=sid\n", 'pmss-env');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('sid', \getDistroVersion());
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testGetDistroNameEmptyWhenMissing(): void
    {
        $file = $this->pmssWriteTempFile('noname', "VERSION_ID=11\n", 'pmss-env');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('', \getDistroName());
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testGetDistroCodenameLowercasesAndTrims(): void
    {
        $file = $this->pmssWriteTempFile('codename', "ID=debian\nVERSION_CODENAME=  BULLSEYE  \n", 'pmss-env');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('bullseye', \getDistroCodename());
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testGetDistroCodenameEmptyWhenNotPresent(): void
    {
        $file = $this->pmssWriteTempFile('nocodename', "ID=debian\nVERSION_ID=12\n", 'pmss-env');
        putenv('PMSS_OS_RELEASE_PATH='.$file);
        \pmssResetOsReleaseCache();
        $this->assertEquals('', \getDistroCodename());
        $this->pmssRestoreEnv('PMSS_OS_RELEASE_PATH', false);
    }

    public function testPmssVersionFromCodenameUnknownReturnsZero(): void
    {
        $this->assertEquals(0, \pmssVersionFromCodename('unknown-planet'));
    }

    public function testGetPmssVersionTrimsWhitespace(): void
    {
        $file = $this->pmssWriteTempFile('version-file', "git/main:2025-01-01\n\n", 'pmss-env');
        $this->assertEquals('git/main:2025-01-01', \getPmssVersion($file));
    }

    public function testGetPmssVersionReturnsUnknownForEmptyFile(): void
    {
        $file = $this->pmssWriteTempFile('empty-version', '', 'pmss-env');
        $this->assertEquals('unknown', \getPmssVersion($file));
    }

}
