<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class DistroRepoSelectionEdgeTest extends TestCase
{
    public function testDetectDistroLowercasesId(): void
    {
        $this->pmssWithOsRelease([
            'ID' => 'Debian',
            'VERSION_ID' => '11',
            'VERSION_CODENAME' => 'BULLSEYE',
        ], function (): void {
            $info = \pmssDetectDistro();
            $this->assertEquals('debian', $info['name']);
            $this->assertEquals(11, $info['version']);
            $this->assertEquals('bullseye', $info['codename']);
        });
    }

    public function testDetectDistroNormalisesUppercaseCodename(): void
    {
        $this->pmssWithOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '12',
            'VERSION_CODENAME' => 'BOOKWORM',
        ], function (): void {
            $info = \pmssDetectDistro();
            $this->assertEquals('bookworm', $info['codename']);
            $this->assertEquals(12, $info['version']);
        });
    }

    public function testDetectDistroUnknownCodenameKeepsVersion(): void
    {
        $this->pmssWithOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '77',
            'VERSION_CODENAME' => 'aurora',
        ], function (): void {
            $info = \pmssDetectDistro();
            $this->assertEquals(77, $info['version']);
            $this->assertEquals('aurora', $info['codename']);
        });
    }

    public function testDetectDistroWhitespaceInCodename(): void
    {
        $this->pmssWithOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '13',
            'VERSION_CODENAME' => '  trixie  ',
        ], function (): void {
            $info = \pmssDetectDistro();
            $this->assertEquals('trixie', $info['codename']);
            $this->assertEquals(13, $info['version']);
        });
    }

    public function testDetectDistroResetCacheSwitchesFiles(): void
    {
        $first = $this->pmssWriteTempFile('os-release', $this->pmssRenderOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '11',
            'VERSION_CODENAME' => 'bullseye',
        ]));
        $second = $this->pmssWriteTempFile('os-release', $this->pmssRenderOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '12',
            'VERSION_CODENAME' => 'bookworm',
        ]));

        $this->pmssWithEnv(['PMSS_OS_RELEASE_PATH' => $first], function () use ($second): void {
            \pmssResetOsReleaseCache();
            $firstInfo = \pmssDetectDistro();
            $this->assertEquals(11, $firstInfo['version']);

            $this->pmssWithEnv(['PMSS_OS_RELEASE_PATH' => $second], function (): void {
                \pmssResetOsReleaseCache();
                $secondInfo = \pmssDetectDistro();
                $this->assertEquals(12, $secondInfo['version']);
            });
        });
    }
}
