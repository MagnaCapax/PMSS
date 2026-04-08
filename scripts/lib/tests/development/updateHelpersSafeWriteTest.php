<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class UpdateHelpersSafeWriteTest extends TestCase
{
    public function testSafeWriteSourcesOverwritesExisting(): void
    {
        $target = $this->pmssWriteTempFile('sources', 'old');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $result = \pmssSafeWriteSources('new', 'UnitTest', null);
        $this->assertTrue($result);
        $this->assertEquals('new', file_get_contents($target));
        $this->assertEquals('old', file_get_contents($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesReturnsFalseWhenTargetIsDirectory(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dir-');
        $this->assertTrue(is_dir($dir));
        putenv('PMSS_APT_SOURCES_PATH='.$dir);

        $result = \pmssSafeWriteSources('data', 'DirTest', null);
        $this->assertTrue($result === false);
        $this->assertTrue(file_exists($dir.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesCreatesParentDirectoriesWhenMissing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-missing-');
        $target = $dir.'/sources.list';
        $this->cleanup($dir);
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        $result = \pmssSafeWriteSources('deb test main', 'DirCreate', null);
        $this->assertTrue($result);
        $this->assertTrue(is_dir($dir));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testSafeWriteSourcesBackupUpdatedOnSecondWrite(): void
    {
        $target = $this->pmssWriteTempFile('sources', 'first');
        putenv('PMSS_APT_SOURCES_PATH='.$target);

        \pmssSafeWriteSources('second', 'UnitTest', null);
        \pmssSafeWriteSources('third', 'UnitTest', null);

        $this->assertEquals('third', file_get_contents($target));
        $this->assertEquals('second', file_get_contents($target.'.pmss-backup'));

        $this->clearEnv('PMSS_APT_SOURCES_PATH');
    }

    public function testAptWriteValidUntilOverrideCreatesParentDirectories(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-apt-override-');
        $target = $dir.'/apt.conf.d/90ignore-release-date';
        [$result, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($target): bool {
            return \pmssAptWriteValidUntilOverride($logger, $target);
        });

        $this->assertTrue($result);
        $this->assertEquals("Acquire::Check-Valid-Until \"false\";\n", file_get_contents($target));
        $this->assertEquals([], $logs);
    }

    public function testAptWriteValidUntilOverrideLogsParentDirectoryFailure(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-apt-override-blocked-');
        $blocker = $dir.'/blocked';
        file_put_contents($blocker, 'not-a-directory');
        $target = $blocker.'/90ignore-release-date';
        [$result, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($target): bool {
            return \pmssAptWriteValidUntilOverride($logger, $target);
        });

        $this->assertTrue($result === false);
        $this->assertTrue((bool) array_filter($logs, static function (string $line) use ($blocker): bool {
            return strpos($line, 'Unable to create apt.conf.d directory for Release timestamp override: '.$blocker) !== false;
        }));
        $this->assertTrue(!file_exists($target));
    }

    public function testAptRunCleanReturnsTrueOnSuccess(): void
    {
        $runner = static function (): array {
            return ['rc' => 0, 'output' => ''];
        };

        [$result, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($runner): bool {
            return \pmssAptRunClean($logger, $runner);
        });

        $this->assertTrue($result);
        $this->assertEquals([], $logs);
    }

    public function testAptRunCleanLogsFailureOutput(): void
    {
        $runner = static function (): array {
            return ['rc' => 100, 'output' => 'simulated apt failure'];
        };

        [$result, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($runner): bool {
            return \pmssAptRunClean($logger, $runner);
        });

        $this->assertTrue($result === false);
        $this->assertTrue((bool) array_filter($logs, static function (string $line): bool {
            return strpos($line, 'apt-get clean failed with rc 100 (simulated apt failure)') !== false;
        }));
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
    }
}
