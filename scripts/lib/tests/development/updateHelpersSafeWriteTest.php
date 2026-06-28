<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class UpdateHelpersSafeWriteTest extends TestCase
{
    public function testSafeWriteSourcesOverwritesExisting(): void
    {
        $target = $this->pmssWriteTempFile('sources', 'old');
        $this->pmssWithAptSourcesPath($target, function () use ($target): void {
            $result = \pmssSafeWriteSources('new', 'UnitTest', null);
            $this->assertTrue($result);
            $this->assertEquals('new', file_get_contents($target));
            $this->assertEquals('old', file_get_contents($target.'.pmss-backup'));
        });
    }

    public function testSafeWriteSourcesReturnsFalseWhenTargetIsDirectory(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dir-');
        $this->assertTrue(is_dir($dir));
        $this->pmssWithAptSourcesPath($dir, function () use ($dir): void {
            $result = \pmssSafeWriteSources('data', 'DirTest', null);
            $this->assertTrue($result === false);
            $this->assertTrue(file_exists($dir.'.pmss-backup'));
        });
    }

    public function testSafeWriteSourcesCreatesParentDirectoriesWhenMissing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-missing-');
        $target = $dir.'/sources.list';
        $this->cleanup($dir);
        $this->pmssWithAptSourcesPath($target, function () use ($dir): void {
            $result = \pmssSafeWriteSources('deb test main', 'DirCreate', null);
            $this->assertTrue($result);
            $this->assertTrue(is_dir($dir));
        });
    }

    public function testSafeWriteSourcesBackupUpdatedOnSecondWrite(): void
    {
        $target = $this->pmssWriteTempFile('sources', 'first');
        $this->pmssWithAptSourcesPath($target, function () use ($target): void {
            \pmssSafeWriteSources('second', 'UnitTest', null);
            \pmssSafeWriteSources('third', 'UnitTest', null);

            $this->assertEquals('third', file_get_contents($target));
            $this->assertEquals('second', file_get_contents($target.'.pmss-backup'));
        });
    }

    public function testSafeWriteSourcesRejectsSymlinkTarget(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-apt-symlink-target-');
        $victim = $dir.'/victim';
        $target = $dir.'/sources.list';
        file_put_contents($victim, 'victim');
        $this->assertTrue(symlink($victim, $target));

        $this->pmssWithAptSourcesPath($target, function () use ($victim): void {
            $logs = [];
            $result = \pmssSafeWriteSources('new', 'SymlinkTarget', $this->pmssMakeArrayLogger($logs));

            $this->assertTrue($result === false);
            $this->assertEquals('victim', file_get_contents($victim));
            $this->pmssAssertMessagesContain($logs, 'Unsafe target path for SymlinkTarget sources.list');
        });
    }

    public function testSafeWriteSourcesRejectsSymlinkBackup(): void
    {
        $target = $this->pmssWriteTempFile('sources', 'old');
        $victim = $target.'.victim';
        file_put_contents($victim, 'victim');
        $this->assertTrue(symlink($victim, $target.'.pmss-backup'));

        $this->pmssWithAptSourcesPath($target, function () use ($target, $victim): void {
            $logs = [];
            $result = \pmssSafeWriteSources('new', 'SymlinkBackup', $this->pmssMakeArrayLogger($logs));

            $this->assertTrue($result === false);
            $this->assertEquals('old', file_get_contents($target));
            $this->assertEquals('victim', file_get_contents($victim));
            $this->pmssAssertMessagesContain($logs, 'Unsafe backup path for SymlinkBackup sources.list');
        });
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
        $this->pmssAssertMessagesContain(
            $logs,
            'Unable to create apt.conf.d directory for Release timestamp override: '.$blocker
        );
        $this->assertTrue(!file_exists($target));
    }

    public function testAptRunCleanRunnerOutcomes(): void
    {
        foreach ([
            [
                static function (): array { return ['rc' => 0, 'output' => '']; },
                true,
                '',
            ],
            [
                static function (): array { return ['rc' => 100, 'output' => 'simulated apt failure']; },
                false,
                'apt-get clean failed with rc 100 (simulated apt failure)',
            ],
            [
                static function (): array { throw new \RuntimeException('simulated runner failure'); },
                false,
                'apt-get clean runner failed: simulated runner failure',
            ],
            [
                static function (): array { return ['rc' => 'not-a-number', 'output' => 'ignored']; },
                false,
                'apt-get clean runner returned invalid rc; treating as failure',
            ],
        ] as [$runner, $expectedResult, $expectedLog]) {
            [$result, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($runner): bool {
                return \pmssAptRunClean($logger, $runner);
            });

            $this->assertSame($expectedResult, $result);
            $expectedLog === ''
                ? $this->assertEquals([], $logs)
                : $this->pmssAssertMessagesContain($logs, $expectedLog);
        }
    }
}
