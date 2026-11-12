<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Isolated kernel-read boundaries in scripts/lib/runtime/commandDiagnostics.php. */
class RuntimeForkDiagnosticsInputSafetyTest extends TestCase
{
    public function testCorruptKernelReadsRetainUnavailableResults(): void
    {
        $load = $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandDiagnostics.php', 'ForkInputFixture');
        $results = $this->pmssRunInlinePhpJson(<<<'PHP'
namespace ForkInputFixture;
// Every observation is synthetic; neither /proc nor cgroups are read.
function opendir($path) { return false; }
function is_dir($path) { return $path === '/sys/fs/cgroup/'; }
function is_readable($path) { return array_key_exists($path, $GLOBALS['reads']); }
function file_get_contents($path, $includePath, $context, $offset, $length) {
    $GLOBALS['limits'][$path] = [$offset, $length];
    return $GLOBALS['reads'][$path];
}
function pmssCgroupSelfPath() { return '/'; }
function pmssTextLines($raw) { return preg_split('/\r?\n/', $raw); }
function pmssLogWhitespaceCollapse($raw) { return preg_replace('/\s+/', ' ', $raw); }
PHP
            .$load.<<<'PHP'
$valid = [
    '/proc/sys/kernel/pid_max' => " 32768\n",
    '/proc/sys/kernel/threads-max' => " 65536\n",
    '/proc/loadavg' => " 0.01 0.02 0.03 1/2 3\n",
    '/proc/self/limits' => "Max processes    10    20    processes\nMax open files    30    40    files\n",
    '/proc/meminfo' => "MemAvailable: 1024 kB\nMemTotal: 2048 kB\n",
    '/sys/fs/cgroup//cgroup.procs' => "1\n2\n",
    '/sys/fs/cgroup//pids.current' => "2\n",
    '/sys/fs/cgroup//pids.max' => "16\n",
    '/sys/fs/cgroup//pids.events' => "max 3\n",
    '/sys/fs/cgroup//memory.current' => "1048576\n",
    '/sys/fs/cgroup//memory.max' => "2097152\n",
    '/sys/fs/cgroup//memory.events' => "oom 1\noom_kill 2\n",
];
$results = [];
foreach (['valid', 'leading', 'middle', 'trailing', 'only-nul', 'failed', 'empty', 'whitespace',
    'memory-only', 'processes-only', 'events-only', 'pid-only'] as $case) {
    // Reset the diagnostic throttle so each case exercises the same reads.
    unset($GLOBALS['PMSS_LAST_FORK_DIAG_AT'], $GLOBALS['PMSS_LAST_FORK_DIAG_CONTEXT']);
    $GLOBALS['reads'] = $valid;
    foreach ($GLOBALS['reads'] as &$raw) {
        if ($case === 'leading') $raw = "\0".$raw;
        elseif ($case === 'middle') $raw = substr($raw, 0, 2)."\0".substr($raw, 2);
        elseif ($case === 'trailing') $raw .= "\0";
        elseif ($case === 'only-nul') $raw = "\0";
        elseif ($case === 'failed') $raw = false;
        elseif ($case === 'empty') $raw = '';
        elseif ($case === 'whitespace') $raw = " \t\r\n";
    }
    unset($raw);
    // One corrupt source must not discard independent valid observations.
    $single = ['memory-only' => '/proc/meminfo', 'processes-only' => '/sys/fs/cgroup//cgroup.procs',
        'events-only' => '/sys/fs/cgroup//memory.events', 'pid-only' => '/proc/sys/kernel/pid_max'];
    if (isset($single[$case])) $GLOBALS['reads'][$single[$case]] .= "\0";
    $GLOBALS['limits'] = [];
    $logs = [];
    pmssDumpForkDiagnostics($case, static function ($line) use (&$logs) { $logs[] = $line; });
    // The first line has live PID/UID metadata, unrelated to input interpretation.
    $results[$case] = ['logs' => array_slice($logs, 1), 'limits' => $GLOBALS['limits']];
}
echo json_encode($results);
PHP
        );

        $validLogs = [
            '[FORK] diag: kernel procs=n/a pid_max=32768 threads_max=65536 loadavg=0.01 0.02 0.03 1/2 3',
            '[FORK] diag: rlimits | Max processes 10 20 processes | Max open files 30 40 files',
            '[FORK] diag: mem avail=1.0MiB total=2.0MiB',
            '[FORK] diag: cgroup path=/ dir=/sys/fs/cgroup/ procs=2 pids=2/16 pids.events.max=3 mem=1.0MiB/2.0MiB mem.events.oom=1 mem.events.oom_kill=2',
        ];
        $this->assertSame($validLogs, $results['valid']['logs']);
        $partialCases = ['memory-only', 'processes-only', 'events-only', 'pid-only'];
        foreach ($results as $case => $result) {
            if (in_array($case, $partialCases, true)) {
                $expected = $validLogs;
                if ($case === 'memory-only') unset($expected[2]);
                elseif ($case === 'processes-only') $expected[3] = str_replace(' procs=2', '', $expected[3]);
                elseif ($case === 'events-only') $expected[3] = str_replace(' mem.events.oom=1 mem.events.oom_kill=2', '', $expected[3]);
                elseif ($case === 'pid-only') $expected[0] = str_replace('pid_max=32768', 'pid_max=n/a', $expected[0]);
                $this->assertSame(array_values($expected), $result['logs'], $case);
            } elseif ($case !== 'valid') {
                $this->assertSame([
                    '[FORK] diag: kernel procs=n/a pid_max=n/a threads_max=n/a loadavg=n/a',
                ], $result['logs'], $case);
            }
            // Refusals must retain acquisition order and each bounded-read size.
            $this->assertSame([
                '/proc/sys/kernel/pid_max' => [0, 4096],
                '/proc/sys/kernel/threads-max' => [0, 4096],
                '/proc/loadavg' => [0, 4096],
                '/proc/self/limits' => [0, 16384],
                '/proc/meminfo' => [0, 16384],
                '/sys/fs/cgroup//pids.max' => [0, 4096],
                '/sys/fs/cgroup//pids.current' => [0, 4096],
                '/sys/fs/cgroup//pids.events' => [0, 4096],
                '/sys/fs/cgroup//memory.max' => [0, 4096],
                '/sys/fs/cgroup//memory.current' => [0, 4096],
                '/sys/fs/cgroup//memory.events' => [0, 4096],
            ] + ($case === 'valid' || in_array($case, $partialCases, true)
                ? ['/sys/fs/cgroup//cgroup.procs' => [0, 262144]] : []),
                $result['limits'], $case);
        }
    }
}
