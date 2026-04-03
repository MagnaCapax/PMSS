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
        putenv('PMSS_APT_SOURCES_PATH='.$target);

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

        $this->pmssRestoreEnv('PMSS_APT_SOURCES_PATH', false);
    }

    public function testUpdateAptSourcesLeavesFileWhenTemplateEmpty(): void
    {
        $initial = "deb http://existing bullseye main\n";
        $target = $this->pmssWriteTempFile('sources', $initial);
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        \updateAptSources('debian', 11, sha1($initial), [
            'bullseye' => '',
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals($initial, file_get_contents($target));
        $this->assertTrue(!file_exists($target.'.pmss-backup'));

        $this->pmssRestoreEnv('PMSS_APT_SOURCES_PATH', false);
    }

    public function testUpdateAptSourcesWithoutExistingFileSkipsBackup(): void
    {
        $target = $this->pmssMakeTempPath('pmss-apt-target-');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $template = "deb http://mirror.example bookworm main\n";
        \updateAptSources('debian', 12, '', [
            'bookworm' => $template,
            'bullseye' => '', 'buster' => '', 'jessie' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals($template, file_get_contents($target));
        $this->assertTrue(!file_exists($target.'.pmss-backup'));

        $this->pmssRestoreEnv('PMSS_APT_SOURCES_PATH', false);
    }

    public function testPmssVersionFromCodenameCoversStretch(): void
    {
        $this->assertEquals(9, \pmssVersionFromCodename('stretch'));
    }

}
