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
        $dir = sys_get_temp_dir().'/pmss-apt-'.bin2hex(random_bytes(4));
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

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testUpdateAptSourcesLeavesFileWhenTemplateEmpty(): void
    {
        $initial = "deb http://existing bullseye main\n";
        $target = $this->tempFile('sources', $initial);
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        \updateAptSources('debian', 11, sha1($initial), [
            'bullseye' => '',
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals($initial, file_get_contents($target));
        $this->assertTrue(!file_exists($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testUpdateAptSourcesWithoutExistingFileSkipsBackup(): void
    {
        $target = sys_get_temp_dir().'/pmss-apt-'.bin2hex(random_bytes(4));
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $template = "deb http://mirror.example bookworm main\n";
        \updateAptSources('debian', 12, '', [
            'bookworm' => $template,
            'bullseye' => '', 'buster' => '', 'jessie' => '', 'trixie' => '',
        ], function (): void {});

        $this->assertEquals($template, file_get_contents($target));
        $this->assertTrue(!file_exists($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testPmssVersionFromCodenameCoversStretch(): void
    {
        $this->assertEquals(9, \pmssVersionFromCodename('stretch'));
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

