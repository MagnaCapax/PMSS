<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/pathSafety.php';

/** Corrupt rewrite paths must be refused before probing or mutating any file. */
class InodeSafeRewriteInputSafetyTest extends TestCase
{
    public function testNulInputsSkipFilesystemAccessAndRewriteCallback(): void
    {
        $script = <<<'PHP'
namespace InodeSafeRewriteInputFixture;
function clearstatcache($clear, $path) { throw new \RuntimeException('unexpected filesystem access'); }
PHP;
        foreach (['runtime/filesystem.php', 'pathSafety.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/'.$module, 'InodeSafeRewriteInputFixture');
        }
        $script .= <<<'PHP'
$results = [];
$invalid = ["\0", "\0/fixture/state", "/fixture/sta\0te", "/fixture/state\0", "/fixture/state\0suffix"];
foreach ($invalid as $value) {
    foreach ([[$value, '/fixture/state'], ['/fixture', $value], [$value, $value]] as [$root, $path]) {
        $called = false;
        $result = pmssInodeSafeRewriteRegularFile($root, $path, static function () use (&$called) {
            $called = true;
            return 'replacement';
        });
        $results[] = [$result, $called];
    }
}
echo json_encode($results);
PHP;
        $this->assertSame(array_fill(0, 15, [false, false]), $this->pmssRunInlinePhpJson($script));
    }

    public function testValidRewritesRetainBytesInodeAndMetadata(): void
    {
        $root = $this->pmssMakeTempDir('pmss-inode-rewrite-input-');
        $path = $this->pmssWriteFile($root.'/state with spaces.dat', 'original payload');
        $identity = stat($path);
        // Unchanged, shorter, empty and binary payloads keep the same opened inode.
        foreach (['original payload', 'short', '', "binary\0payload", 'longer replacement payload'] as $replacement) {
            $before = file_get_contents($path);
            $seen = null;
            $this->assertTrue(\pmssInodeSafeRewriteRegularFile($root, $path,
                static function (string $raw) use (&$seen, $replacement): string {
                    $seen = $raw;
                    return $replacement;
                }));
            $this->assertSame($before, $seen);
            $this->assertSame($replacement, file_get_contents($path));
            clearstatcache(true, $path);
            $after = stat($path);
            foreach (['dev', 'ino', 'uid', 'gid', 'mode'] as $field) {
                $this->assertSame($identity[$field], $after[$field], $field);
            }
        }
    }

    public function testRefusedReplacementLeavesOriginalFileIntact(): void
    {
        $root = $this->pmssMakeTempDir('pmss-inode-rewrite-refusal-');
        $path = $this->pmssWriteFile($root.'/state.dat', 'unchanged');
        foreach ([null, false, 0, [], new \stdClass()] as $replacement) {
            $this->assertFalse(\pmssInodeSafeRewriteRegularFile($root, $path,
                static function () use ($replacement) { return $replacement; }));
            $this->assertSame('unchanged', file_get_contents($path));
        }
    }
}
