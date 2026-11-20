<?php
/** Verify regular-file read completion and metadata publication with isolated stream faults. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RuntimeFileReadCompletionSafetyTest extends TestCase
{
    public function testContentsAndMetadataRequireSuccessfulClosure(): void
    {
        // Intercept every path probe and acquisition; only private memory streams are opened.
        $script = "namespace RuntimeReadCompletionFixture;\nrequire_once ".
            var_export($this->pmssRepoPath('scripts/lib/runtime/filesystem.php'), true).";\n";
        $script .= <<<'PHP'
function clearstatcache($clear, $path) {}
function lstat($path) { return $GLOBALS['stat']; }
function pmssRegularFileOpenVerified($path, $entry, &$opened) {
    $opened = $GLOBALS['stat'];
    $GLOBALS['events'][] = 'open';
    $handle = \fopen('php://memory', 'w+');
    \fwrite($handle, $GLOBALS['bytes']);
    \rewind($handle);
    return $GLOBALS['handle'] = $handle;
}
function stream_get_contents($handle, $length = -1) {
    $GLOBALS['events'][] = 'read';
    if ($GLOBALS['readResult'] instanceof \Throwable) throw $GLOBALS['readResult'];
    if ($GLOBALS['readResult'] !== true) return $GLOBALS['readResult'];
    return \stream_get_contents($handle, $length);
}
function fclose($handle) {
    $GLOBALS['events'][] = 'close';
    \fclose($handle);
    if ($GLOBALS['closeResult'] instanceof \Throwable) throw $GLOBALS['closeResult'];
    return $GLOBALS['closeResult'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystemRead.php', 'RuntimeReadCompletionFixture');
        $script .= <<<'PHP'
$rows = [];
foreach ([false, true] as $bounded) {
    foreach (['kept', '', "raw\0bytes\n"] as $bytes) {
        foreach ([true, false, new \RuntimeException('close'), new \Error('close')] as $closeResult) {
            foreach ([true, false, 'ke', 'keptmore', new \RuntimeException('read'), new \Error('read')] as $readResult) {
                $GLOBALS['bytes'] = $bytes;
                $GLOBALS['stat'] = ['mode' => 0100600, 'nlink' => 1, 'dev' => 7, 'ino' => 19,
                    'uid' => 17, 'size' => strlen($bytes)];
                $GLOBALS['readResult'] = $readResult;
                $GLOBALS['closeResult'] = $closeResult;
                $GLOBALS['events'] = [];
                $metadata = ['stale' => true];
                $caught = $value = null;
                try {
                    $value = pmssRegularFileRead('/fixture/record', null, $bounded ? 16 : null, $metadata);
                } catch (\Throwable $failure) { $caught = $failure; }
                $expectedFailure = $closeResult instanceof \Throwable ? $closeResult
                    : ($readResult instanceof \Throwable ? $readResult : null);
                $rows[] = [$value, $metadata, $caught === $expectedFailure,
                    $GLOBALS['events'], is_resource($GLOBALS['handle'])];
                // Record a leaked stream before releasing it if a regression left it open.
                if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
            }
        }
    }
}
echo json_encode($rows);
PHP;
        $expected = [];
        foreach ([false, true] as $bounded) {
            foreach (['kept', '', "raw\0bytes\n"] as $bytes) {
                foreach (['true', 'false', 'exception', 'error'] as $close) {
                    foreach ([true, false, 'ke', 'keptmore', 'exception', 'error'] as $read) {
                        $contents = $read === true ? $bytes : $read;
                        $valid = $close === 'true' && !in_array($read, [false, 'exception', 'error'], true)
                            && (!$bounded || strlen($contents) === strlen($bytes));
                        $stat = ['mode' => 0100600, 'nlink' => 1, 'dev' => 7, 'ino' => 19,
                            'uid' => 17, 'size' => strlen($bytes)];
                        $expected[] = [$valid ? $contents : null, $valid ? $stat : null, true,
                            ['open', 'read', 'close'], false];
                    }
                }
            }
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }
}
