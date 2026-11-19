<?php
/** Verify representable memory counters in scripts/lib/runtime/commandDiagnostics.php. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RuntimeForkDiagnosticsCounterSafetyTest extends TestCase
{
    public function testMemoryCountersRejectOverflowWithoutDiscardingValidSiblings(): void
    {
        $load = $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandDiagnostics.php', 'ForkCounterFixture', ['values.php']);
        $rows = $this->pmssRunInlinePhpJson(<<<'PHP'
namespace ForkCounterFixture;
// Intercept all host observations; the test reads no real proc or cgroup files.
function opendir($path) { return false; }
function is_readable($path) { return $path === '/proc/meminfo'; }
function file_get_contents($path, $includePath, $context, $offset, $length) {
    $GLOBALS['reads'][] = [$path, $offset, $length];
    return $GLOBALS['meminfo'];
}
function pmssCgroupSelfPath() { return ''; }
PHP
            .$load.<<<'PHP'
$fields = ['MemAvailable', 'MemTotal', 'SwapFree', 'SwapTotal', 'Committed_AS', 'CommitLimit'];
$firstOverflow = PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648';
$cases = ['valid' => '2048', 'zero' => '0', 'padded' => '00002048',
    'maximum' => (string) PHP_INT_MAX, 'padded-maximum' => '000'.PHP_INT_MAX,
    'first-overflow' => $firstOverflow, 'overflow' => PHP_INT_MAX.'0',
    'long' => str_repeat('9', 1000), 'padded-overflow' => '000'.$firstOverflow,
    'negative' => '-1', 'signed' => '+1', 'fraction' => '1.5', 'exponent' => '1e3', 'empty' => '',
    'all-overflow' => $firstOverflow];
$rows = [];
foreach ($cases as $case => $raw) {
    foreach ($fields as $field) {
        $GLOBALS['meminfo'] = '';
        // Reverse input order so the assertions also lock the output catalog order.
        foreach (array_reverse($fields) as $key) {
            $GLOBALS['meminfo'] .= $key.': '.($key === $field || $case === 'all-overflow' ? $raw : '1024')." kB\n";
        }
        unset($GLOBALS['PMSS_LAST_FORK_DIAG_AT'], $GLOBALS['PMSS_LAST_FORK_DIAG_CONTEXT']);
        $GLOBALS['reads'] = [];
        $logs = [];
        pmssDumpForkDiagnostics('counter-fixture', static function ($line) use (&$logs) { $logs[] = $line; });
        // Discard only the identity line, whose process metadata is unrelated to parsing.
        $rows[] = [$case, $field, array_slice($logs, 1), $GLOBALS['reads']];
    }
}
echo json_encode($rows);
PHP
        );

        $fields = ['MemAvailable' => 'avail', 'MemTotal' => 'total', 'SwapFree' => 'swap_free',
            'SwapTotal' => 'swap_total', 'Committed_AS' => 'committed', 'CommitLimit' => 'commit_limit'];
        $valid = ['valid' => '2.0MiB', 'zero' => '0.0MiB', 'padded' => '2.0MiB',
            'maximum' => sprintf('%0.1fMiB', PHP_INT_MAX / 1024.0),
            'padded-maximum' => sprintf('%0.1fMiB', PHP_INT_MAX / 1024.0)];
        $this->assertSame(90, count($rows));
        foreach ($rows as [$case, $field, $logs, $reads]) {
            $expected = ['[FORK] diag: kernel procs=n/a pid_max=n/a threads_max=n/a loadavg=n/a'];
            $values = [];
            foreach ($fields as $key => $label) {
                if ($case === 'all-overflow' || ($key === $field && !isset($valid[$case]))) continue;
                $values[] = $label.'='.($key === $field ? $valid[$case] : '1.0MiB');
            }
            if ($values !== []) $expected[] = '[FORK] diag: mem '.implode(' ', $values);
            $this->assertSame($expected, $logs, $case.':'.$field);
            $this->assertSame([['/proc/meminfo', 0, 16384]], $reads, $case.':'.$field);
        }
    }
}
