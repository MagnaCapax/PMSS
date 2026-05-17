<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class DistroRepoSelectionEdgeTest extends TestCase
{
    public function testDetectDistroLowercasesId(): void
    {
        $this->pmssAssertDetectedDistro([
            'ID' => 'Debian',
            'VERSION_ID' => '11',
            'VERSION_CODENAME' => 'BULLSEYE',
        ], 'debian', 11, 'bullseye');
    }

    public function testDetectDistroNormalisesUppercaseCodename(): void
    {
        $this->pmssAssertDetectedDistro([
            'ID' => 'debian',
            'VERSION_ID' => '12',
            'VERSION_CODENAME' => 'BOOKWORM',
        ], 'debian', 12, 'bookworm');
    }

    public function testDetectDistroUnknownCodenameKeepsVersion(): void
    {
        $this->pmssAssertDetectedDistro([
            'ID' => 'debian',
            'VERSION_ID' => '77',
            'VERSION_CODENAME' => 'aurora',
        ], 'debian', 77, 'aurora');
    }

    public function testDetectDistroWhitespaceInCodename(): void
    {
        $this->pmssAssertDetectedDistro([
            'ID' => 'debian',
            'VERSION_ID' => '13',
            'VERSION_CODENAME' => '  trixie  ',
        ], 'debian', 13, 'trixie');
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
