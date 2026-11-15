<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Publication faults must release the acquired child before diagnostic capture. */
class RootStagePublishCleanupSafetyTest extends TestCase
{
    public function testReapingFaultsAttemptOwnedChildCleanupBeforeStderrInspection(): void
    {
        $script = <<<'PHP'
namespace RootStagePublishCleanupFixture;
function observe($phase, $value = null) {
    $GLOBALS['events'][] = $phase;
    $GLOBALS['counts'][$phase] = ($GLOBALS['counts'][$phase] ?? 0) + 1;
    if (isset($GLOBALS['faults'][$phase]) && $GLOBALS['counts'][$phase] === 1) throw $GLOBALS['faults'][$phase];
    return $value;
}
// All process operations are intercepted; only memory streams are acquired.
function function_exists($name) { return $name !== 'proc_terminate' || $GLOBALS['case'] !== 'reap+terminate-unavailable'; }
function tmpfile() { return $GLOBALS['capture'] = \fopen('php://memory', 'w+'); }
function stream_get_meta_data($handle) { return observe('metadata', ['uri' => '/fixture/stderr']); }
function unlink($path) { return observe('unlink', $GLOBALS['case'] !== 'unlink-false'); }
function proc_open($command, $descriptors, &$pipes) {
    observe('launch');
    $GLOBALS['launch'] = [$command, $descriptors[0], $descriptors[1], $descriptors[2] === $GLOBALS['capture']];
    if ($GLOBALS['case'] === 'launch-false') return false;
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function proc_terminate($process) {
    if ($process !== $GLOBALS['process']) throw new \LogicException('Unowned process');
    return observe('terminate', $GLOBALS['case'] !== 'reap+terminate-false');
}
function proc_close($process) {
    if ($process !== $GLOBALS['process']) throw new \LogicException('Unowned process');
    observe('reap');
    // A second reap failure must still allow the outer diagnostic cleanup.
    if ($GLOBALS['counts']['reap'] === 2 && isset($GLOBALS['faults']['reap-retry'])) throw $GLOBALS['faults']['reap-retry'];
    \fclose($process);
    observe('reaped');
    return $GLOBALS['case'] === 'child-false' ? 23 : 0;
}
function rewind($handle) { return observe('rewind', true); }
function fread($handle, $length) { $GLOBALS['readLength'] = $length; return observe('read', ' diagnostic '); }
function pmssLogControlCharactersReplace($value) { return observe('sanitize', $value); }
function fclose($handle) { observe('capture-close'); return \fclose($handle); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/rootStagePublish.php', 'RootStagePublishCleanupFixture');
        $script .= <<<'PHP'
$results = [];
$cases = ['success', 'child-false', 'unlink-false', 'launch-false', 'metadata', 'unlink', 'launch',
    'reap', 'reaped', 'rewind', 'read', 'sanitize', 'capture-close', 'reap+terminate',
    'reap+terminate-false', 'reap+terminate-unavailable', 'reap+reaped', 'reap+rewind',
    'reap+read', 'reap+sanitize', 'reap+capture-close', 'reap+terminate+capture-close',
    'reap+reap-retry', 'reap+reap-retry+capture-close'];
foreach ($cases as $case) {
    foreach ([\RuntimeException::class, \Error::class] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['events'] = $GLOBALS['counts'] = $GLOBALS['faults'] = [];
        $GLOBALS['capture'] = $GLOBALS['process'] = $GLOBALS['launch'] = $GLOBALS['readLength'] = null;
        // Distinct throwable objects expose which combined fault took precedence.
        foreach (explode('+', $case) as $phase) {
            if (!in_array($phase, ['success', 'child-false', 'unlink-false', 'launch-false', 'terminate-false', 'terminate-unavailable'], true))
                $GLOBALS['faults'][$phase] = new $kind($phase);
        }
        $ok = null; $caught = null; $failure = 'stale';
        try { $ok = pmssRootStagePublishRun('fixture-command', $failure); }
        catch (\Throwable $error) { $caught = $error; }
        $results[] = [$case, $ok, $caught === ($GLOBALS['faults'] === [] ? null : end($GLOBALS['faults'])),
            array_map('is_resource', [$GLOBALS['process'], $GLOBALS['capture']]), $failure,
            $GLOBALS['events'], $GLOBALS['launch'], $GLOBALS['readLength']];
        // Even a failing implementation must not retain the fixture's memory streams.
        foreach ([$GLOBALS['process'], $GLOBALS['capture']] as $handle) if (is_resource($handle)) \fclose($handle);
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(48, count($results));
        foreach ($results as [$case, $ok, $sameFault, $open, $failure, $events, $launch, $readLength]) {
            $ordinary = in_array($case, ['success', 'child-false', 'unlink-false', 'launch-false'], true);
            $this->assertSame($ordinary ? $case === 'success' : null, $ok, $case);
            $this->assertTrue($sameFault, $case);
            // Persistent cleanup faults are bounded; every later cleanup is still attempted.
            $this->assertSame([strpos($case, 'reap-retry') !== false,
                $case === 'capture-close' || strpos($case, '+capture-close') !== false], $open, $case);
            $early = in_array($case, ['metadata', 'unlink', 'unlink-false', 'launch', 'launch-false'], true);
            $cleanup = strpos($case, 'reap+') === 0 || $case === 'reap';
            $expected = ['metadata'];
            if ($case !== 'metadata') $expected[] = 'unlink';
            if (!in_array($case, ['metadata', 'unlink', 'unlink-false'], true)) $expected[] = 'launch';
            if (!$early) {
                $expected[] = 'reap';
                if ($cleanup) {
                    if ($case !== 'reap+terminate-unavailable') $expected[] = 'terminate';
                    $expected[] = 'reap';
                }
                if (strpos($case, 'reap-retry') === false) $expected[] = 'reaped';
            }
            $expected[] = 'rewind';
            if ($case !== 'rewind' && $case !== 'reap+rewind') {
                $expected[] = 'read';
                if ($case !== 'read' && $case !== 'reap+read') $expected[] = 'sanitize';
            }
            $expected[] = 'capture-close';
            $this->assertSame($expected, $events, $case);
            // Preserve legacy stage text and the diagnostic byte budget on every path.
            $stage = in_array($case, ['metadata', 'unlink', 'unlink-false'], true) ? 'publish-stderr' : 'publish-launch';
            $reason = 'stage='.$stage.' rc=not-started';
            if ($case === 'success') $reason = '';
            elseif ($case === 'child-false') $reason = 'stage=publish rc=23';
            elseif (in_array($case, ['rewind', 'read', 'sanitize', 'capture-close'], true)) $reason = '';
            if ($reason !== '' && !array_intersect(explode('+', $case), ['rewind', 'read', 'sanitize', 'capture-close']))
                $reason .= ' stderr=diagnostic';
            $this->assertSame($reason, $failure, $case);
            $this->assertSame(in_array($case, ['rewind', 'reap+rewind'], true) ? null : 300, $readLength, $case);
            if ($launch !== null) $this->assertSame(['fixture-command', ['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], true], $launch, $case);
        }
    }
}
