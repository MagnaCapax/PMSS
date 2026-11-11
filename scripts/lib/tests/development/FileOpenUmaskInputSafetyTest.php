<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../runtime/filesystemStream.php';

/** Check scripts/lib/runtime/filesystemStream.php without opening filesystem paths. */
class FileOpenUmaskInputSafetyTest extends TestCase
{
    public function testMalformedOpensDoNotChangeUmaskOrReachTheOpener(): void
    {
        $cases = [];
        foreach (['', "\0fixture", "fixture\0state", "fixture\0"] as $path) {
            $cases[] = [$path, 'wb', 'success'];
        }
        foreach (['', "\0wb", "w\0b", "wb\0"] as $mode) {
            $cases[] = ['/fixture/state', $mode, 'success'];
        }
        $results = $this->observeOpens($cases);
        $this->assertSame(count($cases), count($results));
        foreach ($results as $result) {
            $this->assertSame([false, false, true, 0022, []], $result);
        }
    }

    public function testValidOpensRetainArgumentsResultsOwnershipAndMaskRestoration(): void
    {
        $cases = $expected = [];
        // Relative paths, spaces and the existing mode modifiers remain caller policy.
        foreach (['/fixture/state', 'relative state', 'php://memory'] as $path) {
            foreach (['r', 'rbn', 'x', 'ce', 'ab'] as $mode) {
                foreach (['success', 'false', 'RuntimeException', 'Error'] as $outcome) {
                    $cases[] = [$path, $mode, $outcome];
                    $expected[] = [$outcome === 'false' ? false : null, $outcome === 'success', true, 0022,
                        [['umask', 0077], ['open', $path, $mode, 0077], ['umask', 0022]]];
                }
            }
        }
        $this->assertSame($expected, $this->observeOpens($cases));
    }

    /** Isolate opener faults and process-mask observations with memory streams only. */
    private function observeOpens(array $cases): array
    {
        $script = <<<'PHP'
namespace FileOpenUmaskFixture;
function umask($mask) {
    $previous = $GLOBALS['mask'];
    $GLOBALS['events'][] = ['umask', $mask];
    $GLOBALS['mask'] = $mask;
    return $previous;
}
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode, $GLOBALS['mask']];
    if ($GLOBALS['failure'] !== null) throw $GLOBALS['failure'];
    if ($GLOBALS['outcome'] === 'false') return false;
    return $GLOBALS['stream'] = \fopen('php://memory', 'w+');
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssFileOpenWithUmask', 'FileOpenUmaskFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_FILE_OPEN_UMASK'), true) as [$path, $mode, $outcome]) {
    $GLOBALS['events'] = [];
    $GLOBALS['mask'] = 0022;
    $GLOBALS['stream'] = null;
    $GLOBALS['outcome'] = $outcome;
    $GLOBALS['failure'] = $outcome === 'RuntimeException' || $outcome === 'Error' ? new $outcome('open failure') : null;
    $result = $caught = null;
    try {
        $result = pmssFileOpenWithUmask($path, $mode, 0077);
    } catch (\Throwable $error) { $caught = $error; }
    $live = is_resource($GLOBALS['stream']);
    $results[] = [is_resource($result) ? null : $result,
        $live && $result === $GLOBALS['stream'], $caught === $GLOBALS['failure'], $GLOBALS['mask'], $GLOBALS['events']];
    if ($live) \fclose($GLOBALS['stream']);
}
echo json_encode($results);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_FILE_OPEN_UMASK' => json_encode($cases)]);
    }
}
