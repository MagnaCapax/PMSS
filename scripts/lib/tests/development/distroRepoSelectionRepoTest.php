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
        $this->pmssWithAptSourcesPath($target, function () use ($dir, $target): void {
            $template = "deb http://mirror.example bullseye main\n";
            $logs = [];
            $logger = $this->pmssMakeArrayLogger($logs);

            \updateAptSources('debian', 11, '', $this->pmssDebianRepoTemplates([
                'bullseye' => $template,
            ]), $logger);

            $this->assertTrue(is_dir($dir));
            $this->assertEquals($template, file_get_contents($target));
        });
    }

    public function testUpdateAptSourcesLeavesFileWhenTemplateEmpty(): void
    {
        $initial = "deb http://existing bullseye main\n";
        $this->pmssWithTempAptSources($initial, function (string $target) use ($initial): void {
            \updateAptSources('debian', 11, sha1($initial), $this->pmssDebianRepoTemplates(), function (): void {});

            $this->assertEquals($initial, file_get_contents($target));
            $this->assertTrue(!file_exists($target.'.pmss-backup'));
        });
    }

    public function testUpdateAptSourcesWithoutExistingFileSkipsBackup(): void
    {
        $target = $this->pmssMakeTempPath('pmss-apt-target-');
        $this->pmssWithAptSourcesPath($target, function () use ($target): void {
            $template = "deb http://mirror.example bookworm main\n";
            \updateAptSources('debian', 12, '', $this->pmssDebianRepoTemplates([
                'bookworm' => $template,
            ]), function (): void {});

            $this->assertEquals($template, file_get_contents($target));
            $this->assertTrue(!file_exists($target.'.pmss-backup'));
        });
    }

    public function testPmssVersionFromCodenameCoversStretch(): void
    {
        $this->assertEquals(9, \pmssVersionFromCodename('stretch'));
    }

}
