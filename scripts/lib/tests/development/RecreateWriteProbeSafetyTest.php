<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/recreateRestoreSafety.php';

/** Fault coverage for scripts/lib/user/recreateRestoreSafety.php without real probe files. */
class RecreateWriteProbeSafetyTest extends TestCase
{
    public function testProbeReleasesResourcesAndPreservesResults(): void
    {
        $script = <<<'PHP'
namespace RecreateProbeFixture;
function observe($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['phase'] === $phase) throw $GLOBALS['failure'];
    return $value;
}
function is_dir($path) { return observe('directory', $GLOBALS['case'] !== 'missing'); }
function is_link($path) { return observe('link', $GLOBALS['case'] === 'linked'); }
function random_bytes($length) { return observe('random', str_repeat('a', $length)); }
function fopen($path, $mode) {
    $GLOBALS['opens'][] = [$path, $mode, umask()];
    observe('open', null);
    if ($GLOBALS['case'] === 'open-failed') return false;
    $GLOBALS['exists'] = true;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function fwrite($handle, $payload) {
    $GLOBALS['payloads'][] = [$payload, umask()];
    return observe('write', ['write-failed' => false, 'write-short' => 0][$GLOBALS['case']] ?? 1);
}
function fflush($handle) { return observe('flush', $GLOBALS['case'] !== 'flush-failed'); }
function fclose($handle) {
    \fclose($handle);
    return observe('close', $GLOBALS['case'] !== 'close-failed');
}
function unlink($path) {
    $GLOBALS['unlinks'][] = $path;
    $removed = observe('unlink', $GLOBALS['case'] !== 'unlink-failed');
    if ($removed) $GLOBALS['exists'] = false;
    return $removed;
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssFileOpenWithUmask', 'RecreateProbeFixture');
        $script .= \pmssTestingFunctionExport('pmssRecreateRequireWritableDirectories', 'RecreateProbeFixture',
            ['Throwable' => '\\Throwable', 'RuntimeException' => '\\RuntimeException']);
        $script .= <<<'PHP'
$results = [];
$cases = ['success', 'missing', 'linked', 'open-failed', 'write-failed', 'write-short',
    'flush-failed', 'close-failed', 'unlink-failed'];
foreach (array_merge($cases, ['open', 'write', 'flush', 'close', 'unlink']) as $case) {
    $phase = in_array($case, $cases, true) ? null : $case;
    foreach ($phase === null ? [null] : [new \RuntimeException($case), new \Error($case)] as $failure) {
        $GLOBALS['case'] = $case; $GLOBALS['phase'] = $phase; $GLOBALS['failure'] = $failure;
        $GLOBALS['events'] = []; $GLOBALS['opens'] = []; $GLOBALS['payloads'] = []; $GLOBALS['unlinks'] = [];
        $GLOBALS['handle'] = null; $GLOBALS['exists'] = false;
        $caught = null; $oldUmask = umask(0022);
        try {
            try { pmssRecreateRequireWritableDirectories(['/fixture/first', '/fixture/second']); }
            catch (\Throwable $error) { $caught = $error; }
            // Inspect cleanup before the fixture can conceal a leak or changed umask.
            $live = is_resource($GLOBALS['handle']);
            $results[] = [$case, $caught === $failure, $caught === null ? null : $caught->getMessage(),
                $live, $GLOBALS['exists'], umask(), $GLOBALS['events'], $GLOBALS['opens'],
                $GLOBALS['payloads'], $GLOBALS['unlinks']];
            if ($live) \fclose($GLOBALS['handle']);
        } finally { umask($oldUmask); }
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $trace = ['directory', 'link', 'random', 'open', 'write', 'flush', 'close', 'unlink'];
        $index = 0;
        foreach (['success', 'missing', 'linked', 'open-failed', 'write-failed', 'write-short',
            'flush-failed', 'close-failed', 'unlink-failed', 'open', 'write', 'flush', 'close', 'unlink'] as $case) {
            $throws = in_array($case, ['open', 'write', 'flush', 'close', 'unlink'], true);
            $success = $case === 'success';
            $events = $trace;
            if ($case === 'missing') $events = ['directory'];
            elseif ($case === 'linked') $events = ['directory', 'link'];
            elseif ($case === 'open' || $case === 'open-failed') $events = array_slice($trace, 0, 4);
            elseif (in_array($case, ['write', 'write-failed', 'write-short'], true)) {
                $events = array_merge(array_slice($trace, 0, 5), ['close', 'unlink']);
            }
            if ($success) $events = array_merge($trace, $trace);
            $opens = []; $payloads = []; $unlinks = [];
            foreach ($success ? ['first', 'second'] : ['first'] as $directory) {
                $path = '/fixture/'.$directory.'/.pmss-recreate-write-probe-'.str_repeat('61', 16);
                if (in_array('open', $events, true)) $opens[] = [$path, 'x+b', 0077];
                if (in_array('write', $events, true)) $payloads[] = ['1', 0022];
                if (in_array('unlink', $events, true)) $unlinks[] = $path;
            }
            $message = $success ? null : ($throws ? $case : 'Rebuild write pre-flight failed for /fixture/first');
            for ($kind = 0; $kind < ($throws ? 2 : 1); $kind++) {
                $this->assertSame([$case, $success || $throws, $message, false,
                    in_array($case, ['unlink', 'unlink-failed'], true), 0022, $events, $opens, $payloads, $unlinks],
                    $results[$index++], $case);
            }
        }
        $this->assertSame($index, count($results));
    }
}
