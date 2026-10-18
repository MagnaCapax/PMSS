<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime/stdoutHeartbeat.php';

final class StdoutHeartbeatTest extends TestCase
{
    public function testTtyDecisionAndIntervalGuard(): void
    {
        $this->assertFalse(\pmssStdoutHeartbeatShouldStart(true));
        $this->assertTrue(\pmssStdoutHeartbeatShouldStart(false));
        $this->assertSame(null, \pmssStdoutHeartbeatStart(0));
    }

    public function testLifecycleScriptsStartHeartbeatBeforeHomeGuard(): void
    {
        foreach (['terminateUser.php', 'suspend.php', 'unsuspend.php'] as $script) {
            $this->pmssAssertRepoFileContract('scripts/'.$script, ['ordered' => [[
                "require_once __DIR__.'/lib/runtime/stdoutHeartbeat.php';",
                'if (pmssStdoutHeartbeatShouldStart(pmssStreamIsTty(STDOUT))) {',
                'pmssStdoutHeartbeatStart();',
                "pmssRequireHomeMounted('".$script."')",
            ]]]);
        }
    }

    /** A shell child must keep writing while PHP is blocked in passthru(). */
    public function testBlockingCallKeepsStdoutAliveAndPreservesLastLine(): void
    {
        $library = var_export($this->pmssRepoPath('scripts/lib/runtime/stdoutHeartbeat.php'), true);
        $code = 'require '.$library.'; $pid = pmssStdoutHeartbeatStart(5); '
            .'fwrite(STDERR, "HEARTBEAT {$pid}\\n"); passthru("sleep 12"); '
            .'pmssStdoutHeartbeatStop(); echo "LAST\\n"; exit(7);';
        $process = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        $started = microtime(true);
        $lastByteAt = $started;
        $output = '';
        $times = [];
        stream_set_blocking($pipes[1], false);
        while (!feof($pipes[1]) && microtime(true) - $started < 20) {
            $read = [$pipes[1]];
            $write = $except = [];
            if (stream_select($read, $write, $except, 1) > 0) {
                $chunk = fread($pipes[1], 4096);
                if ($chunk !== '') {
                    $now = microtime(true);
                    $this->assertTrue($now - $lastByteAt <= 6, 'stdout gap exceeded six seconds');
                    $lastByteAt = $now;
                    $times[] = $now;
                    $output .= $chunk;
                }
            }
        }
        $metadata = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($process);
        $this->assertTrue(count($times) >= 3, 'expected multiple heartbeat reads');
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
        $this->assertSame('LAST', end($lines));
        $this->assertSame(7, $rc);
        $this->assertMatches('/HEARTBEAT (\d+)/', $metadata);
        preg_match('/HEARTBEAT (\d+)/', $metadata, $match);
        $this->assertFalse($this->heartbeatRunning((int) $match[1]), 'heartbeat survived normal exit');
    }

    public function testHeartbeatLeavesAfterParentIsKilled(): void
    {
        $library = var_export($this->pmssRepoPath('scripts/lib/runtime/stdoutHeartbeat.php'), true);
        $code = 'require '.$library.'; $pid = pmssStdoutHeartbeatStart(2); '
            .'fwrite(STDERR, "HEARTBEAT {$pid}\\n"); sleep(30);';
        $process = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        $metadata = fgets($pipes[2]);
        $this->assertMatches('/HEARTBEAT (\d+)/', $metadata);
        preg_match('/HEARTBEAT (\d+)/', $metadata, $match);
        $heartbeatPid = (int) $match[1];
        $status = proc_get_status($process);
        posix_kill($status['pid'], SIGKILL);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $deadline = microtime(true) + 4;
        while ($this->heartbeatRunning($heartbeatPid) && microtime(true) < $deadline) {
            usleep(100000);
        }
        $this->assertFalse($this->heartbeatRunning($heartbeatPid), 'heartbeat survived its parent');
    }

    /** A zombie has exited and cannot write to stdout. */
    private function heartbeatRunning(int $pid): bool
    {
        $status = @file_get_contents('/proc/'.$pid.'/stat');
        return is_string($status) && !preg_match('/^\d+ \(.+\) Z /', $status);
    }
}
