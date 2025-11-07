<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class UpdateHelpersSafeWriteTest extends TestCase
{
    public function testSafeWriteSourcesOverwritesExisting(): void
    {
        $target = $this->makeTempSources('old');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $result = \pmssSafeWriteSources('new', 'UnitTest', null);
        $this->assertTrue($result);
        $this->assertEquals('new', file_get_contents($target));
        $this->assertEquals('old', file_get_contents($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesReturnsFalseWhenTargetIsDirectory(): void
    {
        $dir = sys_get_temp_dir().'/pmss-dir-'.bin2hex(random_bytes(4));
        if (file_exists($dir)) {
            if (is_dir($dir)) {
                @rmdir($dir);
            } else {
                @unlink($dir);
            }
        }
        @mkdir($dir, 0755, true);
        $this->assertTrue(is_dir($dir));
        putenv('PMSS_APT_SOURCES_PATH='.$dir);

        $result = \pmssSafeWriteSources('data', 'DirTest', null);
        $this->assertTrue($result === false);
        $this->assertTrue(file_exists($dir.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesCreatesParentDirectoriesWhenMissing(): void
    {
        $dir = sys_get_temp_dir().'/pmss-missing-'.bin2hex(random_bytes(4));
        $target = $dir.'/sources.list';
        if (file_exists($dir)) {
            @unlink($dir);
        }
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $result = \pmssSafeWriteSources('deb test main', 'DirCreate', null);
        $this->assertTrue($result);
        $this->assertTrue(is_dir($dir));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesBackupUpdatedOnSecondWrite(): void
    {
        $target = $this->makeTempSources('first');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        \pmssSafeWriteSources('second', 'UnitTest', null);
        \pmssSafeWriteSources('third', 'UnitTest', null);

        $this->assertEquals('third', file_get_contents($target));
        $this->assertEquals('second', file_get_contents($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    private function makeTempSources(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmss-sources-');
        if ($path === false) {
            $path = sys_get_temp_dir().'/pmss-sources-'.bin2hex(random_bytes(6));
        }
        file_put_contents($path, $content);
        return $path;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
    }
}

