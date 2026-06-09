<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/lib/user/directories.php';
require_once dirname(__DIR__, 4).'/scripts/lib/user/trafficLimit.php';

class TrafficLimitSafetyHelperTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-traffic-limit-safety-');
    }

    public function testEnsureStorageDirRejectsUnsafeTargets(): void
    {
        $filePath = $this->tempDir.'/not-a-dir';
        file_put_contents($filePath, 'x');
        [, $linkDir] = $this->pmssCreateSymlinkedDirectoryOrSkip($this->tempDir.'/real', $this->tempDir.'/link');

        foreach ([
            'relative path' => 'relative/path',
            'existing file' => $filePath,
            'symlink' => $linkDir,
        ] as $label => $path) {
            $this->assertTrue(\pmssTrafficLimitEnsureStorageDir($path) === false, $label);
        }
    }

    public function testEnsureStorageDirCreatesDirectoryWithStrictMode(): void
    {
        $path = $this->tempDir.'/runtime/trafficLimits';

        $this->assertTrue(\pmssTrafficLimitEnsureStorageDir($path));
        $this->assertTrue(is_dir($path));
        $this->assertEquals(0700, fileperms($path) & 0777);
    }

    public function testEnsureStorageDirConvergesExistingDirectoryMode(): void
    {
        $path = $this->pmssEnsureDir($this->tempDir.'/existing');
        chmod($path, 0755);

        $this->assertTrue(\pmssTrafficLimitEnsureStorageDir($path));
        $this->assertEquals(0700, fileperms($path) & 0777);
    }

    public function testRemoveGiBFileHandlesFilesystemCases(): void
    {
        $this->assertTrue(\pmssTrafficLimitRemoveGiBFile($this->tempDir.'/missing'));

        $path = $this->tempDir.'/quota';
        file_put_contents($path, '12');

        $this->assertTrue(\pmssTrafficLimitRemoveGiBFile($path));
        $this->assertTrue(!file_exists($path));

        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real', $this->tempDir.'/link', '12');
        $this->assertTrue(\pmssTrafficLimitRemoveGiBFile($linkPath) === false);
        $this->assertTrue(file_exists($realPath));

        $dirPath = $this->pmssEnsureDir($this->tempDir.'/dir');
        $this->assertTrue(\pmssTrafficLimitRemoveGiBFile($dirPath) === false);
        $this->assertTrue(is_dir($dirPath));
    }

    public function testConvergeFileModeHandlesFileDirectoryAndSymlinkCases(): void
    {
        $path = $this->tempDir.'/quota';
        file_put_contents($path, '12');
        chmod($path, 0644);

        $this->assertTrue(\pmssTrafficLimitConvergeFileMode($path, 0600));
        $this->assertEquals(0600, fileperms($path) & 0777);

        $dirPath = $this->pmssEnsureDir($this->tempDir.'/dir', 0700);
        $this->assertTrue(\pmssTrafficLimitConvergeFileMode($dirPath, 0700));

        [, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real', $this->tempDir.'/link', '12');
        $this->assertTrue(\pmssTrafficLimitConvergeFileMode($linkPath, 0600) === false);
    }

    public function testThrottleFileWritePersistsCapWithReadableMode(): void
    {
        $path = $this->tempDir.'/home/alice/.throttle';
        $this->pmssEnsureDir(dirname($path));

        $error = 'stale';
        $this->assertTrue(\pmssTrafficLimitThrottleFileWrite($path, 25, $error));
        $this->assertSame(null, $error);
        $this->assertSame('25', trim((string) file_get_contents($path)));
        $this->assertEquals(0644, fileperms($path) & 0777);
    }

    public function testThrottleFileWriteRejectsNegativeCap(): void
    {
        $path = $this->tempDir.'/home/alice/.throttle';
        $this->pmssEnsureDir(dirname($path));

        $error = null;
        $this->assertFalse(\pmssTrafficLimitThrottleFileWrite($path, -1, $error));
        $this->assertSame('invalid throttle cap', $error);
        $this->assertFalse(file_exists($path));
    }

    public function testThrottleFileRemoveTreatsMissingFileAsSuccess(): void
    {
        $path = $this->tempDir.'/home/alice/.throttle';
        $this->pmssEnsureDir(dirname($path));

        $error = 'stale';
        $this->assertTrue(\pmssTrafficLimitThrottleFileRemove($path, $error));
        $this->assertSame(null, $error);
    }

    public function testThrottleFileOperationsRejectSymlinkTargets(): void
    {
        foreach ([
            'write' => function (string $path, ?string &$error): bool {
                return \pmssTrafficLimitThrottleFileWrite($path, 25, $error);
            },
            'remove' => function (string $path, ?string &$error): bool {
                return \pmssTrafficLimitThrottleFileRemove($path, $error);
            },
        ] as $name => $operation) {
            [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real-'.$name, $this->tempDir.'/home/alice/.throttle-'.$name, 'old');

            $error = null;
            $this->assertFalse($operation($linkPath, $error), 'operation '.$name);
            $this->assertSame('unsafe throttle path', $error, 'operation '.$name);
            $this->assertSame('old', file_get_contents($realPath), 'operation '.$name);
        }
    }

    public function testMarkerTouchCreatesSafeMarkerWithStrictMode(): void
    {
        $path = $this->tempDir.'/runtime/trafficLimits/alice.enabled';
        $this->pmssEnsureDir(dirname($path), 0700);

        $this->assertTrue(\pmssTrafficLimitMarkerTouch('alice', $path));
        $this->assertTrue(is_file($path));
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testMarkerRemoveTreatsMissingFileAsSuccess(): void
    {
        $this->assertTrue(\pmssTrafficLimitMarkerRemove('alice', $this->tempDir.'/runtime/trafficLimits/alice.enabled'));
    }

    public function testMarkerOperationsRejectSymlinkTargets(): void
    {
        foreach ([
            'touch' => function (string $path): bool {
                return \pmssTrafficLimitMarkerTouch('alice', $path);
            },
            'remove' => function (string $path): bool {
                return \pmssTrafficLimitMarkerRemove('alice', $path);
            },
        ] as $name => $operation) {
            [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real-marker-'.$name, $this->tempDir.'/runtime/trafficLimits/alice-'.$name.'.enabled', 'old', 0700);

            list($result) = $this->pmssCaptureStdout(function () use ($operation, $linkPath): bool {
                return $operation($linkPath);
            });

            $this->assertFalse($result, 'operation '.$name);
            $this->assertTrue(is_link($linkPath), 'operation '.$name);
            $this->assertSame('old', file_get_contents($realPath), 'operation '.$name);
        }
    }

    public function testPersistTargetModesWritesAllTargetsWithRequestedModes(): void
    {
        $runtimeDir = $this->pmssEnsureDir($this->tempDir.'/runtime/trafficLimits', 0700);
        $homeDir = $this->pmssEnsureDir($this->tempDir.'/home/alice');

        $targets = [
            $runtimeDir.'/alice' => 0600,
            $homeDir.'/.trafficLimit' => 0664,
        ];
        $error = null;

        $this->assertTrue(\pmssTrafficLimitPersistTargetModes($targets, 42, $error));
        $this->assertSame(null, $error);
        $this->assertSame('42', trim((string) file_get_contents($runtimeDir.'/alice')));
        $this->assertSame('42', trim((string) file_get_contents($homeDir.'/.trafficLimit')));
        $this->assertEquals(0600, fileperms($runtimeDir.'/alice') & 0777);
        $this->assertEquals(0664, fileperms($homeDir.'/.trafficLimit') & 0777);
    }

    public function testPersistTargetModesRemovesExistingFilesForZeroValue(): void
    {
        $runtimePath = $this->tempDir.'/runtime/trafficLimits/alice';
        $homePath = $this->tempDir.'/home/alice/.trafficLimit';
        $this->pmssEnsureDir(dirname($runtimePath), 0700);
        $this->pmssEnsureDir(dirname($homePath));
        file_put_contents($runtimePath, '5');
        file_put_contents($homePath, '5');

        $error = 'stale';
        $this->assertTrue(\pmssTrafficLimitPersistTargetModes([$runtimePath => 0600, $homePath => 0664], 0, $error));
        $this->assertSame(null, $error);
        $this->assertFalse(file_exists($runtimePath));
        $this->assertFalse(file_exists($homePath));
    }

    public function testPersistTargetModesRejectsUnsafeRemovalTargets(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real', $this->tempDir.'/link', '12');

        $error = null;
        $this->assertFalse(\pmssTrafficLimitPersistTargetModes([$linkPath => 0600], 0, $error));
        $this->assertSame('refusing to remove non-file/symlink: '.$linkPath, $error);
        $this->assertTrue(file_exists($realPath));
    }
}
