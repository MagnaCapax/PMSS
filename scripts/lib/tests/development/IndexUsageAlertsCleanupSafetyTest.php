<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Memory-only preference-write faults in etc/skel/www/index.php. */
class IndexUsageAlertsCleanupSafetyTest extends TestCase
{
    public function testNewMarkerStreamClosesBeforeWriteFaultsPropagate(): void
    {
        // Load just the writer: rendering the panel could fetch remote frame data.
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');
        $start = strpos($source, 'function pmssUsageAlertsPreferenceApply(');
        $end = strpos($source, "\nif (isset(\$_POST['guiFramesMode']))");
        $this->assertTrue($start !== false && $end !== false && $end > $start);
        // Preserve ordinary results, including the existing acceptance of short writes.
        $cases = [
            ['valid', 8, [], true, 'open write close chmod clear enabled'],
            ['write-zero', 0, [], true, 'open write close chmod clear enabled'],
            ['write-short', 1, [], true, 'open write close chmod clear enabled'],
            ['write-false', false, [], false, 'open write close unlink'],
            ['open-false', 8, [], false, 'open'],
            ['close-false', 8, [], true, 'open write close chmod clear enabled'],
            ['chmod-false', 8, [], false, 'open write close chmod unlink'],
            ['unlink-false', false, [], false, 'open write close unlink'],
            ['enabled-false', 8, [], false, 'open write close chmod clear enabled'],
            ['open-throw', 8, ['open'], null, 'open'],
            ['write-throw', 8, ['write'], null, 'open write close'],
            ['close-throw', 8, ['close'], null, 'open write close'],
            ['write-close-throw', 8, ['write', 'close'], null, 'open write close'],
            ['chmod-throw', 8, ['chmod'], null, 'open write close chmod'],
            ['unlink-throw', false, ['unlink'], null, 'open write close unlink'],
            ['clear-throw', 8, ['clear'], null, 'open write close chmod clear'],
            ['enabled-throw', 8, ['enabled'], null, 'open write close chmod clear enabled'],
        ];
        $script = <<<'PHP'
namespace IndexUsageAlertsFixture;
function observe($step) {
    $GLOBALS['events'][] = $step;
    if (in_array($step, $GLOBALS['faults'], true)) throw $GLOBALS['failures'][$step];
}
function is_link($path) { return false; }
function file_exists($path) { return false; }
function fopen($path, $mode) {
    observe('open');
    $GLOBALS['openArgs'] = [$path, $mode];
    if ($GLOBALS['case'] === 'open-false') return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function fwrite($handle, $bytes) {
    $GLOBALS['bytes'] = $bytes;
    observe('write');
    return $GLOBALS['written'];
}
function fclose($handle) {
    \fclose($handle);
    observe('close');
    return $GLOBALS['case'] !== 'close-false';
}
function chmod($path, $mode) {
    $GLOBALS['chmodArgs'] = [$path, $mode];
    observe('chmod');
    return $GLOBALS['case'] !== 'chmod-false';
}
function unlink($path) { observe('unlink'); return $GLOBALS['case'] !== 'unlink-false'; }
function clearstatcache($clear, $path) { observe('clear'); }
function pmssUsageAlertsEnabled($path) { observe('enabled'); return $GLOBALS['case'] !== 'enabled-false'; }
PHP;
        $script .= substr($source, $start, $end - $start);
        $script .= '$cases = '.var_export($cases, true).';';
        $script .= <<<'PHP'
$rows = [];
foreach ($cases as [$case, $written, $faults]) {
    foreach (['RuntimeException', 'Error'] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['written'] = $written; $GLOBALS['faults'] = $faults;
        $GLOBALS['events'] = []; $GLOBALS['handle'] = null; $GLOBALS['failures'] = [];
        $GLOBALS['bytes'] = $GLOBALS['openArgs'] = $GLOBALS['chmodArgs'] = null;
        foreach ($faults as $step) $GLOBALS['failures'][$step] = new $kind($step.' fault');
        $result = $caught = null;
        try { $result = pmssUsageAlertsPreferenceApply('enabled', '/fixture/.usageAlertsEnabled'); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedFailure = $faults === [] ? null : $GLOBALS['failures'][end($faults)];
        // Detect leaks before fixture cleanup; no real marker or network is touched.
        $live = is_resource($GLOBALS['handle']);
        if ($live) \fclose($GLOBALS['handle']);
        $rows[] = [$case, $result, $caught === $expectedFailure, $live, $GLOBALS['events'],
            $GLOBALS['bytes'], $GLOBALS['openArgs'], $GLOBALS['chmodArgs']];
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(count($cases) * 2, count($rows));
        foreach ($rows as $index => [$case, $result, $sameFailure, $live, $events, $bytes, $openArgs, $chmodArgs]) {
            [, , , $expectedResult, $trace] = $cases[intdiv($index, 2)];
            $expectedEvents = explode(' ', $trace);
            $path = '/fixture/.usageAlertsEnabled';
            $this->assertSame([$expectedResult, true, false, $expectedEvents],
                [$result, $sameFailure, $live, $events], $case);
            $this->assertSame(in_array('write', $expectedEvents, true) ? "enabled\n" : null, $bytes, $case);
            $this->assertSame($case === 'open-throw' ? null : [$path, 'x'], $openArgs, $case);
            $this->assertSame(in_array('chmod', $expectedEvents, true) ? [$path, 0600] : null, $chmodArgs, $case);
        }
    }
}
