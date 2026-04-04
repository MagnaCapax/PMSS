<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/apt.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

class DistroRepoSelectionRepoTest extends TestCase
{
    public function testUpdateAptSourcesCreatesParentDirectory(): void
    {
        $dir = $this->pmssMakeTempPath('pmss-apt-dir-');
        $target = $dir.'/sources.list';
        if (file_exists($dir)) {
            @unlink($dir);
        }
        $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $target], function () use ($dir, $target): void {
            $template = "deb http://mirror.example bullseye main\n";
            $logs = [];
            $logger = function (string $msg) use (&$logs): void {
                $logs[] = $msg;
            };

            \updateAptSources('debian', 11, '', [
                'bullseye' => $template,
                'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
            ], $logger);

            $this->assertTrue(is_dir($dir));
            $this->assertEquals($template, file_get_contents($target));
        });
    }

    public function testUpdateAptSourcesLeavesFileWhenTemplateEmpty(): void
    {
        $initial = "deb http://existing bullseye main\n";
        $target = $this->pmssWriteTempFile('sources', $initial);
        $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $target], function () use ($initial, $target): void {
            \updateAptSources('debian', 11, sha1($initial), [
                'bullseye' => '',
                'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
            ], function (): void {});

            $this->assertEquals($initial, file_get_contents($target));
            $this->assertTrue(!file_exists($target.'.pmss-backup'));
        });
    }

    public function testUpdateAptSourcesWithoutExistingFileSkipsBackup(): void
    {
        $target = $this->pmssMakeTempPath('pmss-apt-target-');
        $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $target], function () use ($target): void {
            $template = "deb http://mirror.example bookworm main\n";
            \updateAptSources('debian', 12, '', [
                'bookworm' => $template,
                'bullseye' => '', 'buster' => '', 'jessie' => '', 'trixie' => '',
            ], function (): void {});

            $this->assertEquals($template, file_get_contents($target));
            $this->assertTrue(!file_exists($target.'.pmss-backup'));
        });
    }

    public function testPmssVersionFromCodenameCoversStretch(): void
    {
        $this->assertEquals(9, \pmssVersionFromCodename('stretch'));
    }

}
