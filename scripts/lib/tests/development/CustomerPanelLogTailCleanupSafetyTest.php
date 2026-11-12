<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/appsRuntime.php';

/** Stream cleanup in etc/skel/www/appsRuntime.php and userMediaStackPanel.php. */
class CustomerPanelLogTailCleanupSafetyTest extends TestCase
{
    public function testTailIoFaultsCloseStreamsAndPreserveResults(): void
    {
        // Export just the two readers; no customer files or processes are touched.
        $script = <<<'PHP'
namespace CustomerPanelLogTailFixture;
function pmssCustomerHomePath($home, $relative) { return $home.'/'.$relative; }
function pmssCustomerAppsMediaIdsRead() { return ['jellyfin']; }
function is_file($path) { return $GLOBALS['case'] !== 'missing'; }
function is_link($path) { return $GLOBALS['case'] === 'symlink'; }
function filesize($path) {
    return $GLOBALS['case'] === 'size-false' ? false : ($GLOBALS['case'] === 'small' ? 10 : 20000);
}
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    if ($GLOBALS['case'] === 'open-throw') throw $GLOBALS['failure'];
    if ($GLOBALS['case'] === 'open-false') return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function fseek($handle, $offset, $whence) {
    $GLOBALS['events'][] = ['seek', $offset, $whence];
    if ($GLOBALS['case'] === 'seek-throw') throw $GLOBALS['failure'];
    return $GLOBALS['case'] === 'seek-false' ? -1 : 0;
}
function stream_get_contents($handle, $length = -1) {
    $GLOBALS['events'][] = ['read', $length];
    if (in_array($GLOBALS['case'], ['read-throw', 'read-close-throw'], true)) throw $GLOBALS['failure'];
    if ($GLOBALS['case'] === 'read-false') return false;
    return $GLOBALS['case'] === 'empty' ? '' : " \t<tail>&\nsecond\n";
}
function fclose($handle) {
    $GLOBALS['events'][] = ['close'];
    \fclose($handle);
    if (in_array($GLOBALS['case'], ['close-throw', 'read-close-throw'], true)) throw $GLOBALS['closeFailure'];
    return true;
}
PHP;
        foreach (['pmssMediaStackPanelLogTailRead', 'pmssAppsLogTailRead'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'CustomerPanelLogTailFixture');
        }
        $script .= <<<'PHP'
$results = [];
foreach (['panel', 'apps'] as $reader) {
    foreach (['valid', 'small', 'empty', 'missing', 'symlink', 'size-false', 'open-false', 'seek-false',
        'read-false', 'open-throw', 'seek-throw', 'read-throw', 'close-throw', 'read-close-throw'] as $case) {
        foreach (strpos($case, 'throw') === false ? [null] : ['RuntimeException', 'Error'] as $kind) {
            $GLOBALS['case'] = $case; $GLOBALS['events'] = []; $GLOBALS['handle'] = null;
            $GLOBALS['failure'] = $kind === null ? null : new $kind('I/O fault');
            $GLOBALS['closeFailure'] = $kind === null ? null : new $kind('close fault');
            $caught = $result = null;
            try {
                $result = $reader === 'panel' ? pmssMediaStackPanelLogTailRead('/fixture')
                    : pmssAppsLogTailRead('/fixture', 'jellyfin');
            } catch (\Throwable $error) { $caught = $error; }
            $expectedFailure = in_array($case, ['close-throw', 'read-close-throw'], true)
                ? $GLOBALS['closeFailure'] : $GLOBALS['failure'];
            $live = is_resource($GLOBALS['handle']);
            // Measure leaks before releasing a stream left by a failing implementation.
            if ($live) \fclose($GLOBALS['handle']);
            $results[] = [$reader, $case, $result, $caught === $expectedFailure, $live, $GLOBALS['events']];
        }
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(38, count($results));
        foreach ($results as [$reader, $case, $result, $sameFailure, $live, $events]) {
            $refused = $case === 'missing' || ($reader === 'panel' && $case === 'size-false')
                || ($reader === 'apps' && $case === 'symlink');
            $expected = [];
            if (!$refused) {
                $expected[] = ['open', '/fixture/'.($reader === 'panel' ? '.install-media-stack.log'
                    : '.config/jellyfin/log/jellyfin.log'), 'rb'];
                if (!in_array($case, ['open-false', 'open-throw'], true)) {
                    if (!in_array($case, ['small', 'size-false'], true)) {
                        $expected[] = ['seek', $reader === 'panel' ? -6000 : -16384, SEEK_END];
                    }
                    if ($case !== 'seek-throw') $expected[] = ['read', $reader === 'panel' ? -1 : 16384];
                    $expected[] = ['close'];
                }
            }
            $expectedResult = strpos($case, 'throw') !== false ? null
                : ($refused || in_array($case, ['open-false', 'read-false', 'empty'], true) ? ''
                    : ($reader === 'panel' ? "<tail>&\nsecond\n" : "&lt;tail&gt;&amp;\nsecond"));
            $this->assertSame([$expectedResult, true, false, $expected],
                [$result, $sameFailure, $live, $events], $reader.': '.$case);
        }
    }
}
