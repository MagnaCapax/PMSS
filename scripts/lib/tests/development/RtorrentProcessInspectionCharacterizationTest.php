<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrent/process.php';
require_once dirname(__DIR__, 2).'/testing/filesystem.php';

/** Lock processInspection.php and processRows.php without executing ps or sending signals. */
final class RtorrentProcessInspectionCharacterizationTest extends TestCase
{
    private function probeTrace(array $case): array
    {
        $script = <<<'PHP'
namespace RtorrentInspectionFixture;
function exec($command, &$output, &$rc) {
    $GLOBALS['commands'][] = $command;
    if (!empty($GLOBALS['case']['throw'])) throw new \RuntimeException('probe failure');
    $output = $GLOBALS['case']['lines'];
    $rc = $GLOBALS['case']['rc'];
}
PHP;
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).';';
        // Reflection follows moves; the same fixture also characterizes the original monolith.
        foreach (['rtorrentProcessPidParse', 'rtorrentProcessNormalizePids', 'rtorrentProcessStateFromPsLine',
            'rtorrentProcessStatesFromPsLines', 'rtorrentProcessExecutorPidsFromPsLines', 'rtorrentProcessPsOutput',
            'rtorrentProcessExecutorPids', 'rtorrentProcessSnapshot', 'rtorrentProcessStatesForPids'] as $name) {
            if (function_exists($name)) $script .= \pmssTestingFunctionExport($name, 'RtorrentInspectionFixture');
        }
        $script .= <<<'PHP'
$GLOBALS['case'] = json_decode(getenv('PMSS_TEST_PROCESS_PROBE'), true);
$GLOBALS['commands'] = [];
$rc = 77;
$output = ['untouched'];
try {
    switch ($GLOBALS['case']['action']) {
        case 'executor': $result = rtorrentProcessExecutorPids($GLOBALS['case']['user'], $rc, $output); break;
        case 'executorAlias': $rc = null; $result = rtorrentProcessExecutorPids($GLOBALS['case']['user'], $rc, $rc); break;
        case 'snapshot': $result = rtorrentProcessSnapshot($GLOBALS['case']['user']); break;
        case 'states': $result = rtorrentProcessStatesForPids($GLOBALS['case']['pids']); break;
    }
} catch (\Throwable $error) {
    $result = [get_class($error), $error->getMessage()];
}
echo json_encode([$result, $rc, $output, $GLOBALS['commands']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_PROCESS_PROBE' => json_encode($case)]);
    }

    public function testExecutorClassificationKeepsDuplicatesCaseRulesAndRawRows(): void
    {
        $lines = [' 0017 PHP-CGI /usr/bin/php .rtorrentExecute.php extra ',
            '9 .rtorrentexecute wrapper rtorrentExecute.php', '11 SCREEN rtorrentExecute.php',
            '12 screener rtorrentExecute.php', '14 bash rtorrentExecute.php', '17 PHP rtorrentExecute.php',
            '18 php unrelated.php', '19 php RTORRENTEXECUTE.PHP', '20 php rtorrentExecute.php:extra',
            '0 php rtorrentExecute.php', PHP_INT_MAX.'0 php rtorrentExecute.php',
            '5 rtorrentExecute.php', '', '# 21 php rtorrentExecute.php'];
        $this->assertSame([['php' => [17, 9, 17, 20], 'screen' => [11, 12], 'all' => [17, 9, 11, 12, 14, 17, 20]],
            0, $lines, ["ps -u 'fixtur01' -o pid=,comm=,args="]],
            $this->probeTrace(['action' => 'executor', 'user' => 'fixtur01', 'rc' => 0, 'lines' => $lines]));
    }

    public function testFailedExecutorProbesKeepDiagnosticsButDiscardPartialMatches(): void
    {
        foreach ([1, 2, 124, 255] as $rc) {
            $lines = ['17 php rtorrentExecute.php', 'diagnostic'];
            $this->assertSame([['php' => [], 'screen' => [], 'all' => []], $rc, $lines,
                ["ps -u 'fixtur01' -o pid=,comm=,args="]],
                $this->probeTrace(['action' => 'executor', 'user' => 'fixtur01', 'rc' => $rc, 'lines' => $lines]));
        }
    }

    public function testAliasedOutputReferencesKeepExitStatusAsTheLastAssignment(): void
    {
        foreach ([0, 1] as $rc) {
            $pids = $rc === 0 ? ['php' => [17], 'screen' => [], 'all' => [17]] : ['php' => [], 'screen' => [], 'all' => []];
            $this->assertSame([$pids, $rc, ['untouched'], ["ps -u 'fixtur01' -o pid=,comm=,args="]],
                $this->probeTrace(['action' => 'executorAlias', 'user' => 'fixtur01', 'rc' => $rc,
                    'lines' => ['17 php rtorrentExecute.php']]));
        }
    }

    public function testSnapshotKeepsTrimmedRowsAndExactFailureWarning(): void
    {
        $command = "ps -u 'fixtur01' -o pid=,ppid=,stat=,lstart=,comm=,args=";
        foreach ([0, 1, 124] as $rc) {
            $expected = $rc === 0 ? ['77 cmd', '# heading', '; raw', '0'] : ['[WARN] ps failed (rc='.$rc.')'];
            $this->assertSame([$expected, 77, ['untouched'], [$command]],
                $this->probeTrace(['action' => 'snapshot', 'user' => 'fixtur01', 'rc' => $rc,
                    'lines' => [' 77 cmd ', '', '  ', '# heading', ' ; raw', '0']]));
        }
    }

    public function testStateQueriesKeepNormalizedSelectorAndLastRowPrecedence(): void
    {
        $rows = ['17 Sl+ first', '9 D wait_io', '0017 R', '0 D bad', PHP_INT_MAX.'0 D overflow', 'bad'];
        foreach ([0, 1, 124] as $rc) {
            $expected = $rc === 0 ? [17 => ['pid' => 17, 'stat' => 'R', 'wchan' => ''],
                9 => ['pid' => 9, 'stat' => 'D', 'wchan' => 'wait_io']] : [];
            $this->assertSame([$expected, 77, ['untouched'], ["ps -o pid=,stat=,wchan= -p '17,9'"]],
                $this->probeTrace(['action' => 'states', 'pids' => ['0017', 9, 17, 0, 'bad'], 'rc' => $rc, 'lines' => $rows]));
        }
    }

    public function testInvalidPidSetsNeverLaunchPs(): void
    {
        foreach ([[], [0, -1], ['bad', '+17'], [PHP_INT_MAX.'0'], [null, false, [], "17\0"]] as $pids) {
            $this->assertSame([[], 77, ['untouched'], []],
                $this->probeTrace(['action' => 'states', 'pids' => $pids, 'rc' => 0, 'lines' => []]));
        }
    }

    public function testAccountSelectorsRemainShellQuotedAndExceptionsLeaveReferencesUntouched(): void
    {
        $user = "fixtur01'; echo fixture; '";
        foreach (['executor', 'snapshot'] as $action) {
            $columns = $action === 'executor' ? 'pid=,comm=,args=' : 'pid=,ppid=,stat=,lstart=,comm=,args=';
            $this->assertSame([['RuntimeException', 'probe failure'], 77, ['untouched'],
                ['ps -u '.escapeshellarg($user).' -o '.$columns]],
                $this->probeTrace(['action' => $action, 'user' => $user, 'rc' => 0, 'lines' => [], 'throw' => true]));
        }
    }
}
