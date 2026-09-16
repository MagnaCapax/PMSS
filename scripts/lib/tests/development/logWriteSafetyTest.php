<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';

final class LogWriteSafetyTest extends TestCase
{
    public function testLogTextPrimitivesPreserveDistinctSpacingPolicies(): void
    {
        foreach ([
            ['', '', ''], ['  x  ', ' x ', '  x  '],
            ["a\r\n\t b", 'a b', 'a  b'], ["a\0\x1bb", "a\0\x1bb", 'a b'],
            ["a\v\fb", 'a b', 'a b'], ["ä\xc2\xa0b", "ä\xc2\xa0b", "ä\xc2\xa0b"],
        ] as [$input, $whitespace, $controls]) {
            $this->assertSame($whitespace, \pmssLogWhitespaceCollapse($input));
            $this->assertSame($controls, \pmssLogControlCharactersReplace($input));
        }
    }

    public function testLogWritePathIsSafeAcceptsRegularFileTarget(): void
    {
        $path = $this->pmssMakeTempDir('pmss-log-dir-').'/events.log';

        $this->assertTrue(\pmssLogWritePathIsSafe($path));
    }

    public function testLogWritePathIsSafeRejectsControlCharacters(): void
    {
        $this->assertFalse(\pmssLogWritePathIsSafe("/tmp/pmss-log\nunsafe.log"));
    }

    public function testLogBoundariesRejectRawControlBytesWithoutIo(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-log-boundary-');
        $path = $directory.'/events.jsonl';
        $original = "{\"event\":\"original\"}\n";
        file_put_contents($path, $original);
        $handled = false;
        $handler = static function () use (&$handled): void { $handled = true; };

        foreach (["\0", "\r", "\n", "\r\n"] as $bytes) {
            foreach ([$bytes.$path, $path.$bytes, $directory.'/events'.$bytes.'.jsonl', " \t".$path.$bytes."\t "] as $unsafe) {
                $this->assertFalse(\pmssLogWritePathIsSafe($unsafe));
                $this->assertFalse(\pmssJsonLineAppend($unsafe, ['event' => 'blocked']));
                $this->assertFalse(\pmssLogAppendTimestampedLine($unsafe, 'blocked'));
                $this->assertFalse(\pmssJsonLineFileEach($unsafe, $handler));
                $this->assertSame([], \pmssJsonLineFileRead($unsafe));
                $this->assertSame(null, \pmssJsonLineFileLast($unsafe));
            }
        }

        $this->assertFalse($handled);
        $this->assertSame($original, file_get_contents($path));
        $this->assertSame(['.', '..', 'events.jsonl'], scandir($directory));
    }

    public function testLogBoundaryPreservesOrdinaryPathsAndPayloadBytes(): void
    {
        $path = $this->pmssMakeTempDir('pmss-log-compatible-').'/events with spaces.jsonl';
        // Preserve the predicate's existing whitespace normalization policy.
        foreach ([$path, ' '.$path.' ', "\t".$path."\t"] as $candidate) {
            $this->assertTrue(\pmssLogWritePathIsSafe($candidate));
        }
        foreach (['', ' ', "\t"] as $empty) {
            $this->assertFalse(\pmssLogWritePathIsSafe($empty));
        }

        $payload = ['event' => "ready\0\r\n", 'path' => '/data/test'];
        $this->assertTrue(\pmssJsonLineAppend($path, $payload));
        $this->assertSame("{\"event\":\"ready\\u0000\\r\\n\",\"path\":\"/data/test\"}".PHP_EOL, file_get_contents($path));
        $this->assertSame([$payload], \pmssJsonLineFileRead($path));

        $linePath = dirname($path).'/events.log';
        $this->assertTrue(\pmssLogAppendTimestampedLine($linePath, "ready\0", '', '[INFO] '));
        $this->assertSame("[INFO] ready\0".PHP_EOL, file_get_contents($linePath));
    }

    public function testJsonLineAppendRejectsSymlinkTarget(): void
    {
        $target = $this->pmssMakeTempFile('pmss-log-target-');
        $link = $this->pmssMakeTempDir('pmss-log-link-dir-').'/events.jsonl';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertFalse(\pmssJsonLineAppend($link, ['event' => 'blocked']));
        $this->assertEquals('', (string) @file_get_contents($target));
    }

    public function testJsonLineAppendRejectsDirectoryTarget(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-log-directory-');

        $this->assertFalse(\pmssJsonLineAppend($directory, ['event' => 'blocked']));
    }

    public function testJsonLineReadRejectsSymlinkTarget(): void
    {
        $target = $this->pmssMakeTempFile('pmss-log-jsonl-target-');
        file_put_contents($target, "{\"event\":\"blocked\"}\n");
        $link = $this->pmssMakeTempDir('pmss-log-jsonl-link-dir-').'/events.jsonl';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $handled = false;
        $this->assertFalse(\pmssJsonLineFileEach($link, static function () use (&$handled): void {
            $handled = true;
        }));
        $this->assertFalse($handled);
        $this->assertEquals([], \pmssJsonLineFileRead($link));
        $this->assertEquals(null, \pmssJsonLineFileLast($link));
    }

    public function testJsonLineReadAcceptsRegularFileTarget(): void
    {
        $path = $this->pmssMakeTempFile('pmss-log-jsonl-read-');
        file_put_contents($path, "{\"event\":\"ready\"}\nnot-json\n{\"event\":\"done\"}\n");

        $this->assertEquals([['event' => 'ready'], ['event' => 'done']], \pmssJsonLineFileRead($path));
        $this->assertEquals(['event' => 'done'], \pmssJsonLineFileLast($path));
    }

    public function testLogAppendTimestampedLineRejectsSymlinkedParentDirectory(): void
    {
        $targetDir = $this->pmssMakeTempDir('pmss-log-parent-real-');
        $linkRoot = $this->pmssMakeTempDir('pmss-log-parent-link-');
        $linkDir = $linkRoot.'/redirected';
        $this->pmssCreateSymlinkOrSkip($targetDir, $linkDir);

        $this->assertFalse(\pmssLogAppendTimestampedLine($linkDir.'/events.log', 'blocked'));
        $this->assertFalse(is_file($targetDir.'/events.log'));
    }

    public function testLogAppendTimestampedLineRejectsNestedSymlinkedAncestorDirectory(): void
    {
        $targetDir = $this->pmssMakeTempDir('pmss-log-parent-real-');
        mkdir($targetDir.'/nested');
        $linkRoot = $this->pmssMakeTempDir('pmss-log-parent-link-');
        $linkDir = $linkRoot.'/redirected';
        $this->pmssCreateSymlinkOrSkip($targetDir, $linkDir);

        $this->assertFalse(\pmssLogAppendTimestampedLine($linkDir.'/nested/events.log', 'blocked'));
        $this->assertFalse(is_file($targetDir.'/nested/events.log'));
    }

    public function testLogWritePathIsSafeRejectsTraversalSegments(): void
    {
        $baseDir = $this->pmssMakeTempDir('pmss-log-traversal-');

        $this->assertFalse(\pmssLogWritePathIsSafe($baseDir.'/../events.log'));
    }

    public function testLogAppendTimestampedLineWritesRegularFileTargets(): void
    {
        $path = $this->pmssMakeTempDir('pmss-log-write-dir-').'/events.log';

        $this->assertTrue(\pmssLogAppendTimestampedLine($path, 'ready', '[Y-m-d H:i:s] ', '[INFO] '));
        $this->assertStringContainsString('[INFO] ready', (string) @file_get_contents($path));
    }
}
