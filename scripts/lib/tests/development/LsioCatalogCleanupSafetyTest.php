<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Catalog publication faults use simulated files, without changing a real snapshot. */
class LsioCatalogCleanupSafetyTest extends TestCase
{
    public function testUnpublishedFilesAreRemovedBeforeReturningOrPropagating(): void
    {
        $script = <<<'PHP'
namespace LsioCatalogCleanupFixture;
function observe($stage, array $arguments) {
    $GLOBALS['events'][] = array_merge([$stage], $arguments);
    if ($stage === 'unlink') {
        if ($GLOBALS['cleanupFailure'] !== null) throw $GLOBALS['cleanupFailure'];
        return $GLOBALS['cleanup'] !== 'false';
    }
    if ($stage !== $GLOBALS['stage']) return true;
    if ($GLOBALS['failure'] !== null) throw $GLOBALS['failure'];
    return $GLOBALS['outcome'] !== 'false';
}
// Intercept every publication operation; only the source loader reads real files.
function tempnam($directory, $prefix) {
    if (!observe('tempnam', [$directory, $prefix])) return false;
    $GLOBALS['temporaryExists'] = true;
    return '/fixture/.pmss-lsio-tmp';
}
function pmssFileWriteComplete($path, $output) {
    return observe('write', [$path, $output]);
}
function chmod($path, $mode) { return observe('chmod', [$path, $mode]); }
function rename($source, $target) {
    if (!observe('rename', [$source, $target])) return false;
    $GLOBALS['temporaryExists'] = false;
    $GLOBALS['snapshot'] = $GLOBALS['body'];
    return true;
}
function unlink($path) {
    if (!observe('unlink', [$path])) return false;
    $GLOBALS['temporaryExists'] = false;
    return true;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/dev/appsLsioCatalogBuild.php', 'LsioCatalogCleanupFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_LSIO_CLEANUP'), true) as [$stage, $outcome, $cleanup, $body]) {
    $GLOBALS['stage'] = $stage; $GLOBALS['outcome'] = $outcome;
    $GLOBALS['cleanup'] = $cleanup; $GLOBALS['body'] = $body;
    $GLOBALS['failure'] = $outcome === 'exception' ? new \RuntimeException('publication failure')
        : ($outcome === 'error' ? new \Error('publication failure') : null);
    $GLOBALS['cleanupFailure'] = $cleanup === 'exception' ? new \RuntimeException('cleanup failure')
        : ($cleanup === 'error' ? new \Error('cleanup failure') : null);
    $GLOBALS['events'] = []; $GLOBALS['temporaryExists'] = false; $GLOBALS['snapshot'] = 'previous';
    $result = $caught = null;
    try { $result = pmssLsioCatalogWrite('/fixture/catalog.php', $body); }
    catch (\Throwable $error) { $caught = $error; }
    $results[] = [$result, $caught === ($GLOBALS['cleanupFailure'] ?? $GLOBALS['failure']),
        $caught === null ? null : $caught->getMessage(), $GLOBALS['temporaryExists'],
        $GLOBALS['snapshot'], $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $cases = [['', 'ok', '', 'catalog bytes'], ['', 'ok', '', '']];
        foreach (['tempnam', 'write', 'chmod', 'rename'] as $stage) {
            foreach (['false', 'exception', 'error'] as $outcome) {
                $cases[] = [$stage, $outcome, '', 'catalog bytes'];
            }
        }
        // Failed cleanup remains best effort; cleanup throwables retain PHP's precedence.
        foreach (['false', 'exception', 'error'] as $cleanup) {
            foreach (['false', 'exception', 'error'] as $outcome) {
                $cases[] = ['write', $outcome, $cleanup, 'catalog bytes'];
            }
        }
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_LSIO_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($results));
        $temporary = '/fixture/.pmss-lsio-tmp';
        foreach ($cases as $index => [$stage, $outcome, $cleanup, $body]) {
            $events = [['tempnam', '/fixture', '.pmss-lsio-'], ['write', $temporary, $body],
                ['chmod', $temporary, 0644], ['rename', $temporary, '/fixture/catalog.php']];
            if ($stage !== '') {
                $events = array_slice($events, 0, array_search($stage, ['tempnam', 'write', 'chmod', 'rename'], true) + 1);
                if ($stage !== 'tempnam') $events[] = ['unlink', $temporary];
            }
            $message = in_array($cleanup, ['exception', 'error'], true) ? 'cleanup failure'
                : (in_array($outcome, ['exception', 'error'], true) ? 'publication failure' : null);
            $expected = [$message !== null ? null : $stage === '', true, $message,
                $cleanup !== '', $stage === '' ? $body : 'previous', $events];
            $this->assertSame($expected, $results[$index], implode(':', $cases[$index]));
        }
    }
}
