<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RuntimeForkDiagnosticsTest extends TestCase
{
    public function testProcessScanClosesDirectoryAndPreservesResults(): void
    {
        $load = $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandDiagnostics.php', 'ForkScanFixture');
        $results = $this->pmssRunInlinePhpJson(<<<'PHP'
namespace ForkScanFixture;
function opendir($path) { return $GLOBALS['scanOpen'] ? 'directory-handle' : false; }
function readdir($handle) {
    if ($GLOBALS['scanEntries'] !== []) return array_shift($GLOBALS['scanEntries']);
    if ($GLOBALS['scanFailure'] !== null) throw $GLOBALS['scanFailure'];
    return false;
}
function closedir($handle) { $GLOBALS['scanClosed'][] = $handle; }
// Keep diagnostics hermetic: no host reads or cgroup traversal.
function is_readable($path) { return false; }
function pmssCgroupSelfPath() { return ''; }
PHP
            .$load.<<<'PHP'
$results = [];
foreach (['success', 'empty', 'open-failure', 'exception', 'error'] as $case) {
    $GLOBALS['scanOpen'] = $case !== 'open-failure';
    $GLOBALS['scanEntries'] = $case === 'empty' ? [] : ['.', '..', 'self', '1', '12', '12x'];
    $GLOBALS['scanFailure'] = $case === 'exception' ? new \RuntimeException('scan failed')
        : ($case === 'error' ? new \Error('scan failed') : null);
    $GLOBALS['scanClosed'] = [];
    $logs = [];
    $caught = null;
    try {
        pmssDumpForkDiagnostics($case, static function ($line) use (&$logs) { $logs[] = $line; });
    } catch (\Throwable $error) {
        $caught = $error;
    }
    $results[$case] = [
        'closed' => $GLOBALS['scanClosed'],
        'sameThrowable' => $caught === $GLOBALS['scanFailure'],
        'logs' => $logs,
    ];
}
echo json_encode($results);
PHP
        );

        foreach ($results as $case => $result) {
            $this->assertSame($case === 'open-failure' ? [] : ['directory-handle'], $result['closed'], $case);
            $this->assertTrue($result['sameThrowable'], $case);
            if ($case === 'exception' || $case === 'error') {
                $this->assertSame(1, count($result['logs']), 'Failed scans must not report a partial count');
            } else {
                $count = $case === 'open-failure' ? 'n/a' : ($case === 'empty' ? '0' : '2');
                $this->assertSame(2, count($result['logs']), $case);
                $this->assertSame('[FORK] diag: kernel procs='.$count.' pid_max=n/a threads_max=n/a loadavg=n/a', $result['logs'][1]);
            }
        }
    }
}
