<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Raw acquisition guards run before any filesystem call, even with supplied metadata. */
class RegularFileAcquisitionSafetyTest extends TestCase
{
    public function testInvalidPathsSkipAcquisitionAndValidPathsKeepTheirBytes(): void
    {
        // Only memory streams are opened; record the original path at each boundary.
        $script = <<<'PHP'
namespace RegularFileAcquisitionFixture;
function clearstatcache($clear, $path) { $GLOBALS['events'][] = ['cache', $clear, $path]; }
function lstat($path) {
    $GLOBALS['events'][] = ['lstat', $path];
    return $GLOBALS['snapshot'];
}
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function fstat($handle) {
    $GLOBALS['events'][] = ['fstat'];
    return $GLOBALS['snapshot'];
}
PHP;
        // Exercise the real path predicate and inode matcher through the existing facade.
        foreach (['filesystem.php', 'filesystemStream.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/'.$module, 'RegularFileAcquisitionFixture');
        }
        $script .= <<<'PHP'
$results = [];
$GLOBALS['snapshot'] = ['mode' => 0100644, 'dev' => 1, 'ino' => 2];
foreach (json_decode(getenv('PMSS_TEST_REGULAR_ACQUISITION'), true) as $path) {
    foreach ([false, true] as $supplied) {
        $GLOBALS['events'] = []; $GLOBALS['handle'] = null;
        $metadata = ['stale' => true];
        $result = null;
        try {
            $result = pmssRegularFileOpenVerified($path, $supplied ? $GLOBALS['snapshot'] : null, $metadata);
            // Successful acquisition transfers a live stream to the caller.
            $results[] = [$result === false, $result === $GLOBALS['handle'],
                is_resource($GLOBALS['handle']), $metadata, $GLOBALS['events']];
        } finally {
            if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
        }
    }
}
echo json_encode($results);
PHP;
        $invalid = ['', "\0", "\0/fixture/file", "/fixture/fi\0le", "/fixture/file\0", "/fixture/file\0suffix"];
        // Relative paths, literal zero and whitespace remain caller-owned policy.
        $valid = ['/fixture/file', 'relative-file', '0', '/fixture/file with spaces', " \t/fixture/file \r\n"];
        $paths = array_merge($invalid, $valid);
        $results = $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_REGULAR_ACQUISITION' => json_encode($paths),
        ]);
        $this->assertSame(count($paths) * 2, count($results));
        $index = 0;
        foreach ($paths as $path) {
            foreach ([false, true] as $supplied) {
                $accepted = in_array($path, $valid, true);
                $events = [];
                if ($accepted) {
                    if (!$supplied) $events = [['cache', true, $path], ['lstat', $path]];
                    $events[] = ['open', $path, 'rbn'];
                    $events[] = ['fstat'];
                }
                $this->assertSame([!$accepted, $accepted, $accepted,
                    $accepted ? ['mode' => 0100644, 'dev' => 1, 'ino' => 2] : null, $events],
                    $results[$index++], 'path case '.($index - 1));
            }
        }
    }
}
