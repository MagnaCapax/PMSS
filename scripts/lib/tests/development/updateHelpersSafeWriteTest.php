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

    public function testAptWriteValidUntilOverrideCreatesParentDirectories(): void
    {
        $dir = sys_get_temp_dir().'/pmss-apt-override-'.bin2hex(random_bytes(4));
        $target = $dir.'/apt.conf.d/90ignore-release-date';
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $result = \pmssAptWriteValidUntilOverride($logger, $target);

        $this->assertTrue($result);
        $this->assertEquals("Acquire::Check-Valid-Until \"false\";\n", file_get_contents($target));
        $this->assertEquals([], $logs);

        @unlink($target);
        @rmdir(dirname($target));
        @rmdir($dir);
    }

    public function testAptWriteValidUntilOverrideLogsParentDirectoryFailure(): void
    {
        $dir = sys_get_temp_dir().'/pmss-apt-override-blocked-'.bin2hex(random_bytes(4));
        $blocker = $dir.'/blocked';
        @mkdir($dir, 0755, true);
        file_put_contents($blocker, 'not-a-directory');
        $target = $blocker.'/90ignore-release-date';
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };

        $result = \pmssAptWriteValidUntilOverride($logger, $target);

        $this->assertTrue($result === false);
        $this->assertTrue((bool) array_filter($logs, static function (string $line) use ($blocker): bool {
            return strpos($line, 'Unable to create apt.conf.d directory for Release timestamp override: '.$blocker) !== false;
        }));
        $this->assertTrue(!file_exists($target));

        @unlink($blocker);
        @rmdir($dir);
    }

    public function testAptRunCleanReturnsTrueOnSuccess(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };
        $runner = static function (): array {
            return ['rc' => 0, 'output' => ''];
        };

        $result = \pmssAptRunClean($logger, $runner);

        $this->assertTrue($result);
        $this->assertEquals([], $logs);
    }

    public function testAptRunCleanLogsFailureOutput(): void
    {
        $logs = [];
        $logger = function (string $message) use (&$logs): void {
            $logs[] = $message;
        };
        $runner = static function (): array {
            return ['rc' => 100, 'output' => 'simulated apt failure'];
        };

        $result = \pmssAptRunClean($logger, $runner);

        $this->assertTrue($result === false);
        $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
            return strpos($line, 'apt-get clean failed with rc 100 (simulated apt failure)') !== false;
        }));
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
