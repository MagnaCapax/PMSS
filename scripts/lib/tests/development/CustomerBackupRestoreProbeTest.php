<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise restore's process observation with in-memory handles and recorded reads. */
class CustomerBackupRestoreProbeTest extends TestCase
{
    public function testProcessScanClosesOnReturnsAndPreservesReadExceptions(): void
    {
        $script = <<<'PHP'
namespace RestoreProcessProbeFixture;
function function_exists($name) { return $name === 'posix_geteuid' && $GLOBALS['case'] !== 'no-posix'; }
function posix_geteuid() { return 1000; }
function failAt($stage) {
    if ($GLOBALS['case'] === $stage) throw $GLOBALS['failure'];
}
function opendir($path) {
    $GLOBALS['events'][] = ['open', $path];
    return $GLOBALS['case'] === 'open' ? false : ($GLOBALS['handle'] = \fopen('php://temp', 'w+'));
}
function readdir($handle) {
    failAt('enumerate');
    return array_shift($GLOBALS['entries']) ?? false;
}
function pmssCustomerFileRead($path, $safe) {
    $GLOBALS['events'][] = ['read', $path, $safe];
    $kind = basename($path);
    failAt($kind);
    if ($GLOBALS['case'] === 'unreadable') return null;
    if ($kind === 'status') return "Uid:\t".($GLOBALS['case'] === 'other-uid' ? '1001' : '1000')."\t1000\t1000\t1000\n";
    return $GLOBALS['case'] === 'other-command' ? "bash\n" : "rtorrent\n";
}
function closedir($handle) {
    $GLOBALS['events'][] = ['close'];
    \fclose($handle);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('etc/skel/www/scriptsIncRestore.php', 'RestoreProcessProbeFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([new \RuntimeException('fixture failure'), new \Error('fixture failure')] as $failure) {
    foreach (['no-posix', 'open', 'empty', 'match', 'other-uid', 'other-command', 'unreadable', 'enumerate', 'status', 'comm'] as $case) {
        $GLOBALS['case'] = $case;
        $GLOBALS['failure'] = $failure;
        $GLOBALS['events'] = [];
        $GLOBALS['entries'] = $case === 'empty' ? ['.', '..', 'self'] : ['self', '123', '456'];
        $GLOBALS['handle'] = null;
        $caught = null;
        $result = null;
        try {
            $result = pmssCustomerBackupRestoreRtorrentProcRunning('/fixture/proc/');
        } catch (\Throwable $error) {
            $caught = $error;
        }
        $results[] = [$case, $result, $caught === $failure, is_resource($GLOBALS['handle']), $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP;
        foreach ($this->pmssRunInlinePhpJson($script) as [$case, $result, $caught, $open, $events]) {
            $throws = in_array($case, ['enumerate', 'status', 'comm'], true);
            $this->assertSame($throws ? null : $case === 'match', $result, $case);
            $this->assertSame($throws, $caught, $case);
            $this->assertFalse($open, $case);
            $expected = $case === 'no-posix' ? [] : [['open', '/fixture/proc/']];
            if (!in_array($case, ['no-posix', 'open'], true)) {
                foreach (in_array($case, ['empty', 'enumerate'], true) ? [] : ['123', '456'] as $pid) {
                    $expected[] = ['read', '/fixture/proc/'.$pid.'/status', true];
                    if ($case === 'status') break;
                    $expected[] = ['read', '/fixture/proc/'.$pid.'/comm', true];
                    if ($case === 'match' || $case === 'comm') break;
                }
                $expected[] = ['close'];
            }
            $this->assertSame($expected, $events, $case);
        }
    }
}
