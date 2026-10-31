<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

/** Lock the runtime include surface, value bytes and both process observation policies. */
class RuntimeBoundariesCharacterizationTest extends TestCase
{
    public function testEnvironmentAloneExportsEveryResponsibilityWithoutOutput(): void
    {
        $script = 'ob_start(); require_once '.var_export(dirname(__DIR__, 2).'/runtime/environment.php', true).';'
            .'$output = ob_get_clean(); echo json_encode([$output, '
            .'pmssLogDir(), pmssRuntimeDir(), pmssStateDir(), pmssBuildCommand("printf", ["a b", "0"]), '
            .'pmssUnsignedDecimalIntParse("00012"), pmssParseSizeToBytes("1.5 MiB"), '
            .'iterator_to_array(pmssConfigTextRows("#skip\\na b\\n0", 1)), '
            .'pmssIopingSamplesMsFromOutput("time=250 us"), function_exists("pmssCommandPath")]);';
        $this->assertSame(['', '/var/log/pmss', '/var/run/pmss', '/var/lib/pmss', "printf 'a b' '0'",
            12, 1572864, [1 => ['a', 'b'], 2 => ['0']], [0.25], true], $this->pmssRunInlinePhpJson($script,
                ['PMSS_LOG_DIR' => '', 'PMSS_RUNTIME_DIR' => '', 'PMSS_STATE_DIR' => '']));
    }

    public function testByteFormattingKeepsPromotionRoundingAndMinimumUnits(): void
    {
        foreach ([
            [0, 1, 0, '0 B'], [1023, 1, 0, '1023 B'], [1024, 1, 0, '1.0 KiB'],
            [1048575, 1, 0, '1024.0 KiB'], [1048576, 2, 0, '1.00 MiB'],
            [512, 3, 1, '0.500 KiB'], [-1024, 1, 0, '-1024 B'], [-1024, 1, 1, '-1.0 KiB'],
            [0, 2, 99, '0.00 PiB'], [1024, 0, -1, '1 KiB'], [1125899906842624, 1, 0, '1.0 PiB'],
        ] as [$bytes, $precision, $minimum, $expected]) {
            $this->assertSame($expected, \pmssFormatBytes($bytes, $precision, $minimum));
        }
    }

    public function testShellConstructionKeepsScalarAndAccountArgumentBytes(): void
    {
        $this->assertSame("'' '0' '' '1' 'a b' 'quote'\\''end'",
            \pmssCommandArgvShellQuote([null, 0, false, true, 'a b', "quote'end"]));
        $this->assertSame('printf', \pmssBuildCommand('printf'));
        $this->assertSame("printf '' '0'", \pmssBuildCommand('printf', ['', 0]));
        $this->assertSame("su 'fixtur01' -c 'printf \"a b\"'",
            \pmssBuildUserShellCommand('fixtur01', 'printf "a b"'));
        $this->assertSame("su -s '/bin/bash' -c 'printf \"a b\"' 'fixtur01'",
            \pmssBuildUserShellCommand('fixtur01', 'printf "a b"', '/bin/bash'));
    }

    public function testProcessPollingKeepsModeSpecificBoundariesAndExitFallbacks(): void
    {
        $exited = ['running' => false, 'exitcode' => 7];
        $running = ['running' => true, 'exitcode' => -1];
        foreach ([
            // A terminal polls past equality; a bounded piped child times out there.
            [false, 5, [$running, $exited], [10, 15], false, 124, true, [['status'], ['timeout']]],
            [true, 5, [$running, $exited], [10, 15], false, 7, false, [['status'], ['sleep', 200000], ['status'], ['close']]],
            [false, 5, [$running, $exited], [10, 14], false, 7, false, [['status'], ['sleep', 100000], ['status'], ['close']]],
            [true, 5, [$running], [10, 16], false, 124, true, [['status'], ['timeout']]],
            // Failed status reads keep the inherited and piped policies distinct.
            [false, 5, [false], [10], false, 124, true, [['status'], ['timeout']]],
            [true, 5, [false], [10], false, -1, false, [['status'], ['close']]],
            [false, 5, [$exited], [10], false, 7, false, [['status'], ['close']]],
            [true, 5, [$exited], [10], false, 7, false, [['status'], ['close']]],
            [false, 0, [], [10], false, -1, false, [['close']]],
            [true, 0, [$running, $exited], [10], false, 7, false, [['status'], ['sleep', 200000], ['status'], ['close']]],
            [false, 5, [], [10], true, 124, true, [['timeout']]],
        ] as [$inherited, $timeout, $statuses, $times, $drainTimeout, $rc, $timedOut, $tail]) {
            $script = $this->pmssReadRepoFile('scripts/lib/tests/fixtures/runtimeProcessCharacterization.php');
            $script = substr($script, 5).$this->pmssInlinePhpLibraryInNamespace(
                'scripts/lib/runtime/commandProcess.php', 'RuntimeProcessCharacterization');
            $script .= <<<'PHP'
$GLOBALS['case'] = json_decode(getenv('PMSS_TEST_PROCESS_CASE'), true);
$GLOBALS['trace'] = [];
$result = pmssCommandProcessCapture('fixture-command', 'fixture-command', $GLOBALS['case']['timeout'],
    0, false, '', 1, false, '', null, null, $GLOBALS['case']['inherited']);
echo json_encode([$result, $GLOBALS['trace']]);
PHP;
            $expected = ['rc' => $rc, 'stdout' => $inherited ? '' : 'out', 'stderr' => $inherited ? '' : 'err',
                'timed_out' => $timedOut, 'launch_failed' => false, 'pipe_failed' => false];
            $trace = array_merge([['open', $inherited]], $inherited ? [] : [['drain']], $tail);
            $this->assertSame([$expected, $trace], $this->pmssRunInlinePhpJson($script,
                ['PMSS_TEST_PROCESS_CASE' => json_encode(['inherited' => $inherited, 'timeout' => $timeout,
                    'statuses' => $statuses, 'times' => $times, 'drain_timeout' => $drainTimeout])]));
        }
    }
}
