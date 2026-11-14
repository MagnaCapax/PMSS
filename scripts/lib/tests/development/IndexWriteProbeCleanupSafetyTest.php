<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Isolated write-probe cleanup in etc/skel/www/index.php; run on testing machines. */
class IndexWriteProbeCleanupSafetyTest extends TestCase
{
    public function testProbeCleanupAttemptsRemovalAfterCloseFaults(): void
    {
        // Extract only this helper, avoiding panel rendering and remote frame loading.
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');
        $start = strpos($source, 'function pmssGuiFramesDirectoryWritable(');
        $end = strpos($source, '/** Apply the customer frame-source preference');
        $this->assertTrue($start !== false && $end !== false && $end > $start);
        $script = <<<'PHP'
namespace IndexWriteProbeFixture;
function uniqid($prefix, $entropy) { return 'fixture'; }
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    if (in_array('open', $GLOBALS['faults'], true)) throw $GLOBALS['ioFailure'];
    if ($GLOBALS['case'] === 'open-false') return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function fwrite($handle, $payload) {
    $GLOBALS['events'][] = ['write', $payload];
    if (in_array('write', $GLOBALS['faults'], true)) throw $GLOBALS['ioFailure'];
    if ($GLOBALS['case'] === 'write-false') return false;
    return $GLOBALS['case'] === 'write-zero' ? 0 : 1;
}
function fflush($handle) {
    $GLOBALS['events'][] = ['flush'];
    if (in_array('flush', $GLOBALS['faults'], true)) throw $GLOBALS['ioFailure'];
    return $GLOBALS['case'] !== 'flush-false';
}
function fclose($handle) {
    $GLOBALS['events'][] = ['close'];
    \fclose($handle);
    if (in_array('close', $GLOBALS['faults'], true)) throw $GLOBALS['closeFailure'];
    return $GLOBALS['case'] !== 'close-false';
}
function unlink($path) {
    $GLOBALS['events'][] = ['unlink', $path];
    if (in_array('unlink', $GLOBALS['faults'], true)) throw $GLOBALS['unlinkFailure'];
    return $GLOBALS['case'] !== 'unlink-false';
}
PHP;
        $script .= substr($source, $start, $end - $start);
        $script .= <<<'PHP'
$results = [];
$cases = ['valid', 'open-false', 'write-false', 'write-zero', 'flush-false', 'close-false',
    'unlink-false', 'open', 'write', 'flush', 'close', 'unlink', 'write-close',
    'flush-close', 'write-unlink', 'close-unlink', 'write-close-unlink'];
foreach ($cases as $case) {
    foreach (['RuntimeException', 'Error'] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['faults'] = explode('-', $case);
        // Ordinary false-result cases do not inject exceptions.
        if (strpos($case, '-false') !== false || $case === 'write-zero') $GLOBALS['faults'] = [];
        $GLOBALS['events'] = []; $GLOBALS['handle'] = null;
        $GLOBALS['ioFailure'] = new $kind('I/O fault');
        $GLOBALS['closeFailure'] = new $kind('close fault');
        $GLOBALS['unlinkFailure'] = new $kind('unlink fault');
        $caught = $result = null;
        try { $result = pmssGuiFramesDirectoryWritable('/fixture/'); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedFailure = null;
        foreach (['open' => 'ioFailure', 'write' => 'ioFailure', 'flush' => 'ioFailure',
            'close' => 'closeFailure', 'unlink' => 'unlinkFailure'] as $step => $failure) {
            if (in_array($step, $GLOBALS['faults'], true)) $expectedFailure = $GLOBALS[$failure];
        }
        $live = is_resource($GLOBALS['handle']);
        // Observe leaked handles before fixture cleanup, including on a failing implementation.
        if ($live) \fclose($GLOBALS['handle']);
        $results[] = [$case, $result, $caught === $expectedFailure, $live, $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(34, count($results));
        foreach ($results as [$case, $result, $sameFailure, $live, $events]) {
            $path = '/fixture/.pmss-gui-write-fixture';
            $expected = [['open', $path, 'x']];
            if (!in_array($case, ['open', 'open-false'], true)) {
                $expected[] = ['write', '1'];
                if (strpos($case, 'write') !== 0) $expected[] = ['flush'];
                $expected[] = ['close'];
                $expected[] = ['unlink', $path];
            }
            $ordinary = in_array($case, ['valid', 'open-false', 'write-false', 'write-zero',
                'flush-false', 'close-false', 'unlink-false'], true);
            $expectedResult = $ordinary
                ? in_array($case, ['valid', 'close-false', 'unlink-false'], true) : null;
            $this->assertSame([$expectedResult, true, false, $expected],
                [$result, $sameFailure, $live, $events], $case);
        }
    }
}
