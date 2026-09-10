<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/runtime.php';

class RuntimeLockSafetyTest extends TestCase
{
    public function testLockAcquireRejectsUnsafeModesBeforeFilesystemChanges(): void
    {
        $root = $this->pmssMakeTempDir('pmss-runtime-lock-modes-');
        $path = $this->pmssWriteFile($root.'/lock', 'retained lock state');
        $missing = $root.'/missing/lock';
        $owner = \pmssLockFileAcquire($path, true, 'c+');
        $this->assertTrue(is_resource($owner));

        try {
            // Both busy-handle policies must reject before opening or creating paths.
            foreach (['', 'w', 'w+', 'wb', 'wb+', 'w+b', 'wt', 'w+t', 'we', 'w+e', "w\0+", "c\0+", "\0"] as $mode) {
                foreach ([true, false] as $closeOnBusy) {
                    foreach ([$path, $root.'/new-lock', $missing] as $target) {
                        $this->pmssAssertNoPhpWarnings(function () use ($target, $mode, $closeOnBusy): void {
                            $busy = true;
                            $handle = \pmssLockFileAcquire($target, true, $mode, true, $closeOnBusy, $busy);
                            \pmssLockHandleRelease($handle);
                            $this->assertFalse($handle);
                            $this->assertFalse($busy, 'Rejected modes are not lock contention');
                        });
                    }
                    $this->assertSame('retained lock state', file_get_contents($path));
                    $this->assertFalse(file_exists($root.'/new-lock'));
                    $this->assertFalse(file_exists(dirname($missing)));
                }
            }

            $busy = null;
            $competing = \pmssLockFileAcquire($path, true, 'c+', false, true, $busy);
            \pmssLockHandleRelease($competing);
            $this->assertFalse($competing);
            $this->assertTrue($busy, 'Rejected opens must leave the owner lock held');
        } finally {
            \pmssLockHandleRelease($owner);
        }
    }

    public function testLockAcquirePreservesNonTruncatingModesAndBusyHandles(): void
    {
        $path = $this->pmssMakeTempFile('pmss-runtime-lock-modes-');
        foreach (['c', 'c+', 'cb', 'c+b', 'r', 'r+', 'rb', 'r+b', 'a', 'a+', 'ab', 'a+b'] as $mode) {
            $this->pmssWriteFile($path, 'retained lock state');
            $busy = true;
            $owner = \pmssLockFileAcquire($path, true, $mode, false, true, $busy);
            try {
                $this->assertTrue(is_resource($owner));
                $this->assertFalse($busy);
                // Legacy callers can retain an open, unlocked stream on contention.
                foreach ([true, false] as $closeOnBusy) {
                    $competing = \pmssLockFileAcquire($path, true, $mode, false, $closeOnBusy, $busy);
                    try {
                        $this->assertTrue($busy);
                        $this->assertSame(!$closeOnBusy, is_resource($competing));
                        $this->assertSame('retained lock state', file_get_contents($path));
                    } finally {
                        \pmssLockHandleRelease($competing, false);
                    }
                }
            } finally {
                \pmssLockHandleRelease($owner);
            }
        }
    }

    public function testLockAcquirePreservesNonTruncatingFileCreation(): void
    {
        $root = $this->pmssMakeTempDir('pmss-runtime-lock-create-');
        foreach (['c', 'c+', 'a', 'a+', 'x', 'x+', 'xb', 'x+b'] as $index => $mode) {
            $path = $root.'/'.$index.'/lock';
            $busy = true;
            $handle = \pmssLockFileAcquire($path, true, $mode, true, true, $busy);
            try {
                $this->assertTrue(is_resource($handle));
                $this->assertFalse($busy);
                $this->assertTrue(\pmssLockFileHandleMatchesPath($handle, $path));
                $this->assertSame('', file_get_contents($path));
                $this->assertSame(5, fwrite($handle, 'state'));
                $this->assertTrue(fflush($handle));
                $this->assertSame('state', file_get_contents($path));
            } finally {
                \pmssLockHandleRelease($handle);
            }
        }
    }

    public function testLockHandleOperationsRejectInvalidResourcesWithoutWarnings(): void
    {
        $path = $this->pmssMakeTempFile('pmss-runtime-lock-');
        $this->pmssWriteFile($path, 'unchanged');
        $closed = fopen($path, 'r');
        fclose($closed);
        $context = stream_context_create();

        // A stream context is a live resource, but none of the file APIs accept it.
        foreach ([null, false, true, 0, 'handle', [], new \stdClass(), $closed, $context] as $handle) {
            $this->pmssAssertNoPhpWarnings(function () use ($handle, $path): void {
                $this->assertFalse(\pmssLockFileHandleMatchesPath($handle, $path));
                $this->assertSame([], \pmssLockHandleFdList($handle, dirname($path)));
                $this->assertFalse(\pmssLockHandleWritePid($handle));
                \pmssLockHandleRelease($handle);
                \pmssLockHandleRelease($handle, false);
            });
        }

        $this->assertSame('unchanged', file_get_contents($path));
        $this->assertTrue(is_resource($context), 'Rejected resources must remain untouched');
    }

    public function testLockHandleLifecyclePreservesPidAndReleaseBehavior(): void
    {
        $path = $this->pmssMakeTempFile('pmss-runtime-lock-');
        foreach ([[true, false], [false, false], [true, true], [false, true]] as [$unlock, $cron]) {
            $this->pmssWriteFile($path, str_repeat('old pid ', 8));
            $acquire = function (string $resolved) use ($path) {
                $this->assertSame(\pmssRuntimeLockPath('pmss-test.lock'), $resolved);
                return \pmssLockFileAcquire($path, true, 'c+');
            };
            $handle = $cron ? \pmssCronLockAcquire('test', null, $acquire) : \pmssLockFileAcquire($path, true, 'c+');
            $this->assertTrue(is_resource($handle));

            try {
                $this->assertTrue(\pmssLockFileHandleMatchesPath($handle, $path));
                $this->assertTrue(\pmssLockHandleWritePid($handle));
                $this->assertSame((string) getmypid(), file_get_contents($path));

                // A competing open must stay busy until the original handle closes.
                $busy = null;
                $competing = \pmssLockFileAcquire($path, true, 'c', false, true, $busy);
                \pmssLockHandleRelease($competing);
                $this->assertFalse($competing);
                $this->assertTrue($busy);
            } finally {
                \pmssLockHandleRelease($handle, $unlock);
            }

            $this->assertFalse(is_resource($handle));
            $reacquired = \pmssLockFileAcquire($path, true);
            try {
                $this->assertTrue(is_resource($reacquired), 'Release must allow the next lock acquisition');
            } finally {
                \pmssLockHandleRelease($reacquired);
            }
        }
    }

    public function testCronLockSkipPreservesOutputAndExitPolicy(): void
    {
        foreach ([
            ['null', 0, '', "test already running; skipping\n"],
            ["'pmssCronLockSkipLog'", 0, 'TIMESTAMP: test already running; skipping', ''],
            ['static function (): void {}', 0, '', ''],
            ['static function ($message): void { echo "log:".$message; }', 0, 'log:test already running; skipping', ''],
            ['static function (): int { return 1; }', 1, '', ''],
        ] as [$callback, $rc, $stdout, $stderr]) {
            $code = 'require '.var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).'; '
                .'pmssCronLockAcquire("test", '.$callback.', static function ($path) { return false; }); exit(99);';
            ['result' => $result, 'stderrPath' => $stderrPath] = $this->pmssExecShellCommandWithTempStderr(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
            $this->assertSame($rc, $result['rc']);
            $this->assertSame($stdout, preg_replace('/\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/', 'TIMESTAMP', $result['output']));
            $this->assertSame($stderr, file_get_contents($stderrPath));
        }
    }

    public function testLockHandleFdListPreservesMatchingSortedDescriptors(): void
    {
        $root = $this->pmssMakeTempDir('pmss-runtime-lock-fds-');
        $path = $this->pmssWriteFile($root.'/lock', '');
        $other = $this->pmssWriteFile($root.'/other', '');
        $fdRoot = $this->pmssEnsureDir($root.'/fds');
        foreach (['9' => $path, '3' => $path, '1' => $path, '4' => $other, 'name' => $path] as $fd => $target) {
            $this->pmssCreateSymlinkOrSkip($target, $fdRoot.'/'.$fd);
        }
        $handle = fopen($path, 'c+');

        try {
            $this->assertSame([3, 9], \pmssLockHandleFdList($handle, $fdRoot));
        } finally {
            \pmssLockHandleRelease($handle);
        }
    }

    public function testLockFileHandleMatchesCurrentPath(): void
    {
        $path = $this->pmssMakeTempFile('pmss-runtime-lock-');
        $handle = @fopen($path, 'c+');
        $this->assertTrue(is_resource($handle), 'Expected lock fixture handle');

        try {
            $this->assertTrue(\pmssLockFileHandleMatchesPath($handle, $path));
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
    }

    public function testLockFileHandleRejectsReplacedPath(): void
    {
        $path = $this->pmssMakeTempFile('pmss-runtime-lock-');
        $handle = @fopen($path, 'c+');
        $this->assertTrue(is_resource($handle), 'Expected lock fixture handle');

        try {
            @unlink($path);
            $this->pmssWriteFile($path, "replacement\n");

            $this->assertFalse(\pmssLockFileHandleMatchesPath($handle, $path));
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }
    }
}
