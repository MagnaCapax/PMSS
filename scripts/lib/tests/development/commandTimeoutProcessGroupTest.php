<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

/**
 * Timeouts must bound the CHILD, not only the parent's wait.
 *
 * The 2026-07-03 root compromise happened because a timed-out command's
 * daemonizing grandchild survived: `proc_terminate()` signals only the direct
 * child, so the grandchild reparented to PID 1 and kept running as root.
 * ADR 0034 records the remedy shape -- the command must run in its own process
 * group and the whole group must be signalled on timeout.
 *
 * These tests reproduce the orphan. They FAIL on the pre-fix tree.
 */
class CommandTimeoutProcessGroupTest extends TestCase
{
    /** Spawn a grandchild that records its pid, then outlives the shell that started it. */
    private function orphanSpawningCommand(string $pidFile, bool $ignoreSigterm = false): string
    {
        $trap = $ignoreSigterm ? 'trap "" TERM; ' : '';
        $inner = $trap.'echo $$ > '.escapeshellarg($pidFile).'; exec sleep 300';

        return 'nohup sh -c '.escapeshellarg($inner).' >/dev/null 2>&1 & '.$trap.'sleep 300';
    }

    /** Report whether the recorded grandchild is still alive, and reap it either way. */
    private function orphanSurvived(string $pidFile): bool
    {
        // Signal delivery and reparenting are asynchronous; poll instead of sleeping blind.
        $deadline = microtime(true) + 3.0;
        $pid = 0;
        while (microtime(true) < $deadline) {
            $raw = trim((string) @file_get_contents($pidFile));
            if ($raw !== '' && ctype_digit($raw)) {
                $pid = (int) $raw;
                break;
            }
            usleep(100000);
        }
        if ($pid <= 0) {
            return false;
        }

        $survived = false;
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if (!@posix_kill($pid, 0)) {
                return false;
            }
            $survived = true;
            usleep(100000);
        }

        @posix_kill($pid, 9);

        return $survived;
    }

    public function testTimeoutKillsDaemonizingGrandchild(): void
    {
        $pidFile = $this->pmssMakeTempFile('pmss-orphan-');
        $result = \pmssCommandCapture($this->orphanSpawningCommand($pidFile), 2);

        $this->assertTrue($result['rc'] !== 0, 'timed-out command must report failure');
        $this->assertFalse(
            $this->orphanSurvived($pidFile),
            'daemonizing grandchild survived the timeout -- the process group was not signalled'
        );
    }

    public function testTimeoutKillsSigtermIgnoringGrandchild(): void
    {
        $pidFile = $this->pmssMakeTempFile('pmss-orphan-sigterm-');
        \pmssCommandCapture($this->orphanSpawningCommand($pidFile, true), 2);

        $this->assertFalse(
            $this->orphanSurvived($pidFile),
            'SIGTERM-ignoring grandchild survived -- the SIGKILL escalation missed the process group'
        );
    }

    public function testProcessGroupWrapperMakesTheDirectChildItsOwnGroupLeader(): void
    {
        $bash = \pmssCommandProcessGroupWrap('/bin/bash -lc '.escapeshellarg('sleep 30'), 5);
        $process = proc_open($bash, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process), 'wrapped invocation must launch');

        usleep(500000);
        $status = proc_get_status($process);
        $pid = (int) ($status['pid'] ?? 0);
        $this->assertTrue($pid > 0, 'wrapped invocation must expose a pid');
        $this->assertSame(
            $pid,
            posix_getpgid($pid),
            'the direct child must lead its own process group so the group can be signalled safely'
        );
        $this->assertTrue(
            posix_getpgid($pid) !== posix_getpgid(getmypid()),
            'the child group must differ from the caller group so a group kill cannot hit the caller'
        );

        @proc_terminate($process, 9);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        @proc_close($process);
    }

    public function testUnboundedCommandsAreNotWrapped(): void
    {
        $bash = '/bin/bash -lc '.escapeshellarg('true');

        // userTransfer.php sets PMSS_COMMAND_TIMEOUT=0 so TB-scale migrations are never bounded.
        $this->assertSame($bash, \pmssCommandProcessGroupWrap($bash, 0), 'a zero deadline must stay unwrapped');
        $this->assertSame($bash, \pmssCommandProcessGroupWrap($bash, -1), 'a negative deadline must stay unwrapped');
    }

    public function testWrapperPreservesExitCodeFidelity(): void
    {
        foreach ([0, 42, 127] as $expected) {
            $result = \pmssCommandCapture('exit '.$expected, 30);
            $this->assertSame($expected, $result['rc'], 'exit code must survive the process-group wrapper');
        }
    }

    public function testWrapperPreservesOutputCapture(): void
    {
        $result = \pmssCommandCapture('echo out; echo err >&2', 30);

        $this->assertSame('out', trim($result['stdout']));
        $this->assertSame('err', trim($result['stderr']));
    }

    public function testStatusProbeRunnerEnforcesADeadline(): void
    {
        // systemStatus's binary probes execute installed third-party binaries as root to read a
        // version line. The runner must bound them; an unbounded shell_exec would wedge
        // systemTest and leave an orphan behind it. Behavioural, because the probe runner and
        // the probe specs live on different lines and no line-scoped lint can see the pair.
        require_once dirname(__DIR__, 2).'/systemStatus.php';
        $runCommand = \pmssStatusContextResolve()['runCommand'];

        $startedAt = microtime(true);
        $output = $runCommand('sleep 60; echo late');
        $elapsed = microtime(true) - $startedAt;

        $this->assertTrue(
            $elapsed < 55,
            sprintf('status probe runner must enforce a deadline; it ran %.1fs', $elapsed)
        );
        $this->assertSame('', trim((string) $output), 'a probe that hit its deadline must yield no version detail');
    }

    public function testNoCommandClassCarriesABespokeTimeout(): void
    {
        // Invariant, not a named-constant check: any future per-class carve-out fails this too.
        $this->pmssWithEnv(['PMSS_COMMAND_TIMEOUT' => '300'], function (): void {
            $baseline = \pmssCommandTimeoutSeconds('true');
            $this->assertSame(300, $baseline, 'the env override must be honoured verbatim');
            foreach (['apt-get update', 'dpkg --configure -a', 'apt install foo', 'rsync -a a b'] as $command) {
                $this->assertSame(
                    $baseline,
                    \pmssCommandTimeoutSeconds($command),
                    'no command class may carry its own deadline: '.$command
                );
            }
        });
    }
}
