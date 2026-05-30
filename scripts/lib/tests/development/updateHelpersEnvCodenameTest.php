<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class UpdateHelpersEnvCodenameTest extends TestCase
{
    public function testGetDistroVersionStripsSuffix(): void
    {
        $this->pmssWithOsRelease(['ID' => 'debian', 'VERSION_ID' => '12 (bookworm)'], function (): void {
            $this->assertEquals('12', \getDistroVersion());
        });
    }

    public function testGetDistroVersionReturnsRawWhenNonNumeric(): void
    {
        $this->pmssWithOsRelease(['ID' => 'debian', 'VERSION_ID' => 'sid'], function (): void {
            $this->assertEquals('sid', \getDistroVersion());
        });
    }

    public function testGetDistroNameEmptyWhenMissing(): void
    {
        $this->pmssWithOsRelease(['VERSION_ID' => '11'], function (): void {
            $this->assertEquals('', \getDistroName());
        });
    }

    public function testGetDistroCodenameLowercasesAndTrims(): void
    {
        $this->pmssWithOsRelease(['ID' => 'debian', 'VERSION_CODENAME' => '  BULLSEYE  '], function (): void {
            $this->assertEquals('bullseye', \getDistroCodename());
        });
    }

    public function testGetDistroCodenameEmptyWhenNotPresent(): void
    {
        $this->pmssWithOsRelease(['ID' => 'debian', 'VERSION_ID' => '12'], function (): void {
            $this->assertEquals('', \getDistroCodename());
        });
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
