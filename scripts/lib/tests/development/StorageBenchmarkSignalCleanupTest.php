<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * An interrupted storage benchmark must not leave its preallocated test file behind.
 *
 * Runs the real CLI in a child process against a stub fio that blocks, sends the signal while fio
 * runs, and checks the reserved pmss-fio-*.dat is gone and the exit status is 128 + signal.
 */
class StorageBenchmarkSignalCleanupTest extends TestCase
{
    /** Start the benchmark with a blocking fio stub; return [process, pipes, targetDir, fioPidFile]. */
    private function startBenchmarkWithBlockingFio(): array
    {
        $dir = $this->pmssMakeTempDir('pmss-bench-signal-', 0700);
        $target = $dir.'/target';
        mkdir($target, 0700);
        $pidFile = $dir.'/fio.pid';
        // The stub records its pid, then blocks like a running fio job would.
        $bin = $this->pmssMakeExecutableStub('fio', "#!/bin/sh\necho \$\$ > ".escapeshellarg($pidFile)."\nexec sleep 60\n", 'pmss-bench-fio-');
        $command = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 3).'/util/storageBenchmark.php')
            .' --target='.escapeshellarg($target).' --size=1M --runtime=1 --json='.escapeshellarg($dir.'/bench.jsonl');
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->pmssPathPrefixedEnvironment($bin, ['HOME' => $dir]));
        $this->assertTrue(is_resource($process), 'benchmark child must launch');

        // Poll until fio is running, i.e. the file is reserved and the job loop has started.
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && !is_file($pidFile)) usleep(100000);
        $this->assertTrue(is_file($pidFile), 'the stub fio must have started');
        return [$process, $pipes, $target, $pidFile];
    }

    /** Wait for the child to exit and return its exit code, killing the blocked fio stub first. */
    private function finishChild($process, array $pipes, string $pidFile): int
    {
        $fioPid = (int) trim((string) @file_get_contents($pidFile));
        // The child may wait for its fio at exit; end the stub so the wait is bounded.
        if ($fioPid > 0) @posix_kill($fioPid, 9);
        $exit = -1;
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) { $exit = (int) $status['exitcode']; break; }
            usleep(100000);
        }
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
        return $exit;
    }

    /** Wait up to five seconds for no pmss-fio-*.dat to remain in the target directory. */
    private function waitForFileGone(string $target): bool
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            if (glob($target.'/pmss-fio-*.dat') === []) return true;
            usleep(100000);
        }
        return false;
    }

    public function testSignalsRemoveTheReservedFileAndExitWithTheSignalStatus(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->assertTrue(true, 'pcntl unavailable: the handler is a no-op by design');
            return;
        }
        foreach ([SIGTERM => 'SIGTERM', SIGINT => 'SIGINT', SIGHUP => 'SIGHUP'] as $signal => $name) {
            [$process, $pipes, $target, $pidFile] = $this->startBenchmarkWithBlockingFio();
            $this->assertSame(1, count(glob($target.'/pmss-fio-*.dat')), $name.': the file must be reserved while fio runs');

            $pid = (int) proc_get_status($process)['pid'];
            posix_kill($pid, $signal);

            $gone = $this->waitForFileGone($target);
            $exit = $this->finishChild($process, $pipes, $pidFile);
            $this->assertTrue($gone, $name.': the reserved test file must be removed');
            $this->assertSame(128 + $signal, $exit, $name.': exit status must be 128 + signal');
        }
    }
}
