<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';

/** Guard scripts/lib/user/watchdogProcess.php with temporary proc fixtures and PATH-injected probes. */
class WatchdogProcessBoundaryTest extends TestCase
{
    public function testOptionShapedPatternsNeverInvokePgrep(): void
    {
        $log = $this->pmssMakeTempFile('watchdog-option-log-');
        $binDir = $this->pmssMakeInvocationLogStub('pgrep', $log, 'watchdog-option-bin-');
        $this->pmssWithPathPrefix($binDir, function (): void {
            foreach (['-', '-f', '-x', '--help', '--version', '-u fixtur02'] as $pattern) {
                $rc = 99;
                $output = ['stale'];
                $this->assertSame([], pmssUserWatchdogProcessPids('fixtur01', $pattern, [], $rc, $output));
                $this->assertSame(1, $rc);
                $this->assertSame([], $output);
                $this->assertFalse(pmssUserWatchdogProcessRunning('fixtur01', $pattern));
                $this->assertSame(null, pmssUserWatchdogProcessStartTime('fixtur01', $pattern));
            }
        });
        $this->assertSame('', file_get_contents($log));
    }

    public function testOrdinaryAndAnchoredDashPatternsStillReturnProbeResults(): void
    {
        $lines = ['0017', '17', '9'];
        $binDir = $this->pmssMakeLineOutputStub('pgrep', $lines, 'watchdog-valid-bin-');
        $this->pmssWithPathPrefix($binDir, function () use ($lines): void {
            foreach (['demo', '^demo.*worker$', '^-f$', '[-]f', ' demo ', "demo's"] as $pattern) {
                $this->assertSame([17, 9], pmssUserWatchdogProcessPids('fixtur01', $pattern, [], $rc, $output));
                $this->assertSame(0, $rc);
                $this->assertSame($lines, $output);
            }
        });
    }

    public function testProcPathsAndPidsRejectMalformedValuesBeforeReading(): void
    {
        $proc = $this->procFixture('1000', '100');
        // Existing lookalike directories must not supply an inode-time fallback for invalid PIDs.
        foreach (['0', '000', PHP_INT_MAX.'0'] as $pid) {
            mkdir($proc.'/'.$pid);
            touch($proc.'/'.$pid, 1234567890);
        }
        foreach (['', '0', '000', '-1', '+77', '../77', "77\0", PHP_INT_MAX.'0'] as $pid) {
            $this->assertSame(null, pmssUserWatchdogProcStartTime($proc, $pid));
        }
        foreach (["\0".$proc, $proc."\0", $proc."\0/other"] as $path) {
            $this->assertSame(null, pmssUserWatchdogProcStartTime($path, '77'));
        }
        $this->assertSame(null, pmssUserWatchdogProcStartTime($proc, '999'));
    }

    public function testMissingKernelMetadataKeepsInodeFallback(): void
    {
        foreach (['stat', '77/stat'] as $missing) {
            $proc = $this->procFixture('1000', '100');
            unlink($proc.'/'.$missing);
            // Removing the process stat file changes the fixture directory's mtime.
            touch($proc.'/77', 1234567890);
            clearstatcache();
            $this->assertSame(1234567890, pmssUserWatchdogProcStartTime($proc, '77'));
        }
    }

    public function testMalformedAndOverflowingKernelFieldsKeepInodeFallback(): void
    {
        foreach ([
            [PHP_INT_MAX.'0', '100'], ['000'.PHP_INT_MAX.'0', '100'],
            ['1000', PHP_INT_MAX.'0'], ['1000', '000'.PHP_INT_MAX.'0'],
            [(string) PHP_INT_MAX, '100'], [(string) (PHP_INT_MAX - 1), '200'],
            ['invalid', '100'], ['1000', '-1'], ['1000', 'invalid'], ['1000', ''],
        ] as [$boot, $ticks]) {
            $proc = $this->procFixture($boot, $ticks);
            $this->assertSame(1234567890, pmssUserWatchdogProcStartTime($proc, '77'));
        }
    }

    public function testRepresentableKernelTimesPreserveTickTruncationAndBounds(): void
    {
        foreach ([
            ['1000', '12345', 1123], ['0001000', '00012345', 1123],
            ['0', '0', 0], ['1000', '99', 1000], ['1000', '100', 1001],
            [(string) PHP_INT_MAX, '0', PHP_INT_MAX],
            [(string) (PHP_INT_MAX - 1), '100', PHP_INT_MAX],
            ['0', (string) PHP_INT_MAX, intdiv(PHP_INT_MAX, 100)],
        ] as [$boot, $ticks, $expected]) {
            $proc = $this->procFixture($boot, $ticks);
            $this->assertSame($expected, pmssUserWatchdogProcStartTime($proc, '77'));
        }
    }

    public function testNulCorruptionAnywhereInKernelMetadataKeepsInodeFallback(): void
    {
        foreach (['stat', '77/stat'] as $target) {
            $proc = $this->procFixture('1000', '100');
            $raw = file_get_contents($proc.'/'.$target);
            // Include corruption outside the matched fields and bytes trim() would erase.
            foreach (["\0".$raw, $raw."\0", "other\0\n".$raw,
                $raw."\nother=\0", substr_replace($raw, "\0", 2, 0)] as $corrupt) {
                $this->pmssWriteFile($proc.'/'.$target, $corrupt);
                touch($proc.'/77', 1234567890);
                clearstatcache();
                $this->assertSame(1234567890, pmssUserWatchdogProcStartTime($proc, '77'), $target);
            }
        }
    }

    public function testProcessStatHeaderMustIdentifyTheRequestedPidAndFrameTheCommand(): void
    {
        $proc = $this->procFixture('1000', '100');
        $raw = file_get_contents($proc.'/77/stat');
        $tail = substr($raw, strrpos($raw, ')') + 1);
        foreach ([
            ['77 (worker)', 1001], ['77 (worker (nested))', 1001],
            ['77 (demo worker) x)', 1001], ['00077 (worker)', 1001],
            ['78 (worker)', 1234567890], ['0 (worker)', 1234567890],
            [PHP_INT_MAX.'0 (worker)', 1234567890], ['bad (worker)', 1234567890],
            ['(worker)', 1234567890], ['77 worker)', 1234567890],
            ['77 (worker', 1234567890], ["77\t(worker)", 1234567890],
            ["junk\n77 (worker)", 1234567890], ['-77 (worker)', 1234567890],
        ] as [$header, $expected]) {
            // Keep a complete, valid numeric tail so only the header decides the fallback.
            $this->pmssWriteFile($proc.'/77/stat', $header.$tail);
            clearstatcache();
            $this->assertSame($expected, pmssUserWatchdogProcStartTime($proc, '77'), $header);
        }
    }

    /** Field 22 follows nineteen fields after the parenthesised command name. */
    private function procFixture(string $boot, string $ticks): string
    {
        $proc = $this->pmssMakeTempDir('watchdog-proc-boundary-');
        $fields = array_merge(['S'], array_fill(0, 18, '0'), [$ticks]);
        $this->pmssWriteFiles($proc, [
            'stat' => "cpu 1 2 3\nbtime ".$boot."\n",
            '77/stat' => '77 (demo worker) x) '.implode(' ', $fields)."\n",
        ]);
        touch($proc.'/77', 1234567890);
        return $proc;
    }
}
