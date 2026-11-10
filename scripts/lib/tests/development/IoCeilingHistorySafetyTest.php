<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise log acquisition with memory streams, without opening host logs. */
class IoCeilingHistorySafetyTest extends TestCase
{
    public function testHistoryValidatesOpenedInodesAndClosesEveryAcquiredStream(): void
    {
        $script = <<<'PHP'
namespace IoCeilingHistoryFixture;
function observe($phase, $value) {
    if ($GLOBALS['rotation']) {
        $GLOBALS['events'][] = $phase;
        if ($GLOBALS['case'] === $phase.'-throw') throw $GLOBALS['failure'];
    }
    return $value;
}
function pmssPathTargetIsSafe($path, $directory, $parent) {
    $GLOBALS['rotation'] = substr($path, -2) === '.1';
    return observe('guard', !$GLOBALS['rotation'] || $GLOBALS['case'] !== 'unsafe');
}
function lstat($path) {
    $stat = ['dev' => 1, 'ino' => 2, 'mode' => 0100644, 'nlink' => 1];
    if ($GLOBALS['rotation']) {
        if ($GLOBALS['case'] === 'missing') $stat = false;
        if ($GLOBALS['case'] === 'not-regular') $stat['mode'] = 0010644;
        if ($GLOBALS['case'] === 'hardlink') $stat['nlink'] = 2;
    }
    return observe('lstat', $stat);
}
function fopen($path, $mode) {
    observe('open', null);
    $GLOBALS['modes'][] = $mode;
    if ($GLOBALS['rotation'] && $GLOBALS['case'] === 'open-failed') return false;
    $handle = \fopen('php://memory', 'w+');
    $GLOBALS['handles'][] = $handle;
    $row = ['time' => $GLOBALS['rotation'] ? 1 : 2];
    $raw = '1999-01-01 00:00:00 || '.serialize($row)."\n";
    if ($GLOBALS['rotation'] && $GLOBALS['case'] === 'partial') $raw = substr($raw, 0, -1);
    if ($GLOBALS['rotation'] && $GLOBALS['case'] === 'empty') $raw = '';
    \fwrite($handle, $raw);
    \rewind($handle);
    return $handle;
}
function fstat($handle) {
    $stat = \fstat($handle);
    $stat['dev'] = 1; $stat['ino'] = 2;
    if ($GLOBALS['rotation']) {
        if ($GLOBALS['case'] === 'inode-changed') $stat['ino'] = 3;
        if ($GLOBALS['case'] === 'device-changed') $stat['dev'] = 3;
        if ($GLOBALS['case'] === 'fifo-substituted') { $stat['ino'] = 3; $stat['mode'] = 0010644; }
        if ($GLOBALS['case'] === 'stat-failed') $stat = false;
    }
    return observe('stat', $stat);
}
function fseek($handle, $offset) {
    return observe('seek', $GLOBALS['rotation'] && $GLOBALS['case'] === 'seek-failed' ? -1 : \fseek($handle, $offset));
}
function fgets($handle, $length) {
    return observe('read', $GLOBALS['rotation'] && $GLOBALS['case'] === 'read-failed' ? false : \fgets($handle, $length));
}
function fclose($handle) {
    \fclose($handle);
    return observe('close', true);
}
PHP;
        // Use the shared inode matcher itself, rather than a fixture acceptance stub.
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystem.php', 'IoCeilingHistoryFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystemStream.php', 'IoCeilingHistoryFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/ioCeilingHistory.php', 'IoCeilingHistoryFixture');
        $script .= <<<'PHP'
$results = [];
$cases = ['valid', 'hardlink', 'unsafe', 'missing', 'not-regular', 'open-failed', 'stat-failed',
    'inode-changed', 'device-changed', 'fifo-substituted', 'seek-failed', 'read-failed', 'empty', 'partial'];
foreach (['lstat', 'open', 'stat', 'seek', 'read', 'close'] as $phase) $cases[] = $phase.'-throw';
foreach ($cases as $case) {
    foreach (substr($case, -6) === '-throw' ? [new \RuntimeException($case), new \Error($case)] : [null] as $failure) {
        $GLOBALS['case'] = $case; $GLOBALS['failure'] = $failure;
        $GLOBALS['events'] = []; $GLOBALS['handles'] = []; $GLOBALS['modes'] = [];
        $rows = []; $caught = null;
        try {
            foreach (pmssIoCeilingHistorySamples('/fixture/history') as $row) $rows[] = $row;
        } catch (\Throwable $error) { $caught = $error; }
        $live = array_filter($GLOBALS['handles'], 'is_resource');
        foreach ($live as $handle) \fclose($handle); // Observe leaks before fixture cleanup.
        $results[] = [$case, $rows, $caught === $failure, count($live), $GLOBALS['events'], $GLOBALS['modes']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(26, count($results));
        $trace = ['guard', 'lstat', 'open', 'stat', 'seek', 'read', 'close'];
        $stop = ['unsafe' => 1, 'missing' => 2, 'not-regular' => 2, 'open-failed' => 3,
            'stat-failed' => 4, 'inode-changed' => 4, 'device-changed' => 4, 'fifo-substituted' => 4];
        foreach ($results as [$case, $rows, $sameFailure, $live, $events, $modes]) {
            $this->assertSame([true, 0], [$sameFailure, $live], $case);
            // Every acquired source must request non-blocking mode, including the live fallback.
            $this->assertSame(array_fill(0, count($modes), 'rbn'), $modes, $case);
            if (substr($case, -6) === '-throw') {
                $phase = substr($case, 0, -6);
                $expected = array_slice($trace, 0, array_search($phase, $trace, true) + 1);
                if (in_array($phase, ['stat', 'seek', 'read'], true)) $expected[] = 'close';
                $this->assertSame($expected, $events, $case);
                $this->assertSame($phase === 'close' ? [['time' => 1]] : [], $rows, $case);
                continue;
            }
            $expected = isset($stop[$case]) ? array_slice($trace, 0, $stop[$case]) : $trace;
            if (in_array($case, ['stat-failed', 'inode-changed', 'device-changed', 'fifo-substituted'], true)) $expected[] = 'close';
            if (in_array($case, ['seek-failed', 'empty'], true)) $expected = ['guard', 'lstat', 'open', 'stat', 'seek', 'close'];
            $this->assertSame($expected, $events, $case);
            $this->assertSame(in_array($case, ['valid', 'hardlink'], true) ? [['time' => 1], ['time' => 2]] : [['time' => 2]], $rows, $case);
            $opens = in_array($case, ['unsafe', 'missing', 'not-regular'], true) ? 1 : 2;
            $this->assertSame($opens, count($modes), $case);
        }
    }
}
