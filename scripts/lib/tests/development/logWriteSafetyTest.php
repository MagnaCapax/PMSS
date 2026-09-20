<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';

final class LogWriteSafetyTest extends TestCase
{
    public function testJsonLineReaderDistinguishesReadFailureFromEof(): void
    {
        // Inject only the read failure; real files retain native EOF and close behavior.
        $library = var_export(dirname(__DIR__, 2).'/log.php', true);
        $script = <<<'PHP'
namespace LogReadFixture;
function fgets($handle) {
    $GLOBALS['reader'] = $handle;
    if ($GLOBALS['reads']++ === $GLOBALS['failAt']) return false;
    return \fgets($handle);
}
PHP;
        $script .= '$library = '.$library.';';
        $script .= <<<'PHP'
$source = str_replace('__DIR__', var_export(dirname($library), true), file_get_contents($library));
eval('namespace LogReadFixture;'.substr($source, 5));
[$path, $GLOBALS['failAt']] = json_decode(getenv('PMSS_TEST_LOG_READ'), true);
$GLOBALS['reads'] = 0;
$seen = [];
$result = pmssJsonLineFileEach($path, static function (array $entry) use (&$seen): void {
    $seen[] = $entry;
});
$closed = !is_resource($GLOBALS['reader']);
$GLOBALS['reads'] = 0;
$entries = pmssJsonLineFileRead($path);
$closed = $closed && !is_resource($GLOBALS['reader']);
$GLOBALS['reads'] = 0;
$last = pmssJsonLineFileLast($path);
echo json_encode([$result, $seen, $entries, $last, $closed && !is_resource($GLOBALS['reader'])]);
PHP;
        $path = $this->pmssMakeTempFile('pmss-log-read-failure-');
        $first = ['event' => 'first'];
        $last = ['event' => 'last'];
        $lines = "{\"event\":\"first\"}\ninvalid\nnull\n{\"event\":\"last\"}\n";
        foreach ([
            [$lines, 0, false, []],
            [$lines, 1, false, [$first]],
            [$lines, 2, false, [$first]],
            [$lines, 3, false, [$first]],
            [$lines, null, true, [$first, $last]],
            [rtrim($lines, "\n"), null, true, [$first, $last]],
            ['', null, true, []],
            ["invalid\nnull\n", null, true, []],
        ] as [$contents, $failAt, $success, $entries]) {
            file_put_contents($path, $contents);
            $this->assertSame([$success, $entries, $entries,
                $entries === [] ? null : $entries[count($entries) - 1], true],
                $this->pmssRunInlinePhpJson($script, [
                    'PMSS_TEST_LOG_READ' => json_encode([$path, $failAt]),
                ]));
            $this->assertSame($contents, file_get_contents($path));
        }
    }

    public function testAppendResultsRequireCompleteRecordsAndPreserveFallback(): void
    {
        // Replace only the write boundary in an isolated child; use real temp files.
        $library = var_export(dirname(__DIR__, 2).'/log.php', true);
        $script = <<<'PHP'
namespace LogAppendFixture;
function file_put_contents($path, $data, $flags) {
    $GLOBALS['requests'][] = [$path, $data, $flags];
    $limit = $path === ($GLOBALS['fallback'] ?? null) ? null : $GLOBALS['limit'];
    if ($limit === false) return false;
    if ($limit !== null) $data = substr($data, 0, $limit);
    return \file_put_contents($path, $data, $flags);
}
PHP;
        $script .= '$library = '.$library.';';
        $script .= <<<'PHP'
$source = str_replace('__DIR__', var_export(dirname($library), true), file_get_contents($library));
eval('namespace LogAppendFixture;'.substr($source, 5));
[$path, $kind, $GLOBALS['limit']] = json_decode(getenv('PMSS_TEST_LOG_APPEND'), true);
$GLOBALS['requests'] = [];
if ($kind === 'mirror') {
    $GLOBALS['fallback'] = $path.'.fallback';
    ob_start();
    pmssLogWriteMessage($path, $GLOBALS['fallback'], 'ready');
    $output = ob_get_clean();
    echo json_encode([$output, file_get_contents($GLOBALS['fallback']), $GLOBALS['requests']]);
    return;
}
if ($kind === 'json') {
    $result = pmssJsonLineAppend($path, ['event' => "ready\0", 'path' => '/data/test']);
} else {
    $result = pmssLogAppendTimestampedLine($path, "ready\0", '', '[INFO] ', 0644);
}
clearstatcache(true, $path);
echo json_encode([$result, file_get_contents($path), fileperms($path) & 0777, $GLOBALS['requests']]);
PHP;
        $path = $this->pmssMakeTempDir('pmss-log-short-write-').'/events.log';
        foreach (['json' => '{"event":"ready\\u0000","path":"/data/test"}'.PHP_EOL,
            'text' => "[INFO] ready\0".PHP_EOL] as $kind => $line) {
            foreach ([false, 0, 1, strlen($line) - 1, strlen($line), null] as $limit) {
                file_put_contents($path, 'previous'.PHP_EOL);
                chmod($path, 0600);
                $complete = $limit === null || $limit === strlen($line);
                $bytes = $limit === false ? '' : ($limit === null ? $line : substr($line, 0, $limit));
                $this->assertSame([$complete, 'previous'.PHP_EOL.$bytes,
                    $kind === 'text' && $complete ? 0644 : 0600,
                    [[$path, $line, FILE_APPEND | LOCK_EX]]], $this->pmssRunInlinePhpJson($script, [
                        'PMSS_TEST_LOG_APPEND' => json_encode([$path, $kind, $limit]),
                    ]));
            }
        }
        foreach ([false, 0, 1] as $limit) {
            file_put_contents($path, 'previous'.PHP_EOL);
            file_put_contents($path.'.fallback', '');
            [$output, $fallback, $requests] = $this->pmssRunInlinePhpJson($script, [
                'PMSS_TEST_LOG_APPEND' => json_encode([$path, 'mirror', $limit]),
            ]);
            $this->assertSame('ready'.PHP_EOL, $output);
            $this->assertSame(2, count($requests));
            $this->assertSame($path, $requests[0][0]);
            $this->assertSame([$path.'.fallback', $fallback, FILE_APPEND | LOCK_EX], $requests[1]);
            $this->assertTrue((bool) preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] ready\n$/D', $fallback));
        }
    }

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

    public function testJsonLineReaderClosesStreamAndPreservesCallbackOutcome(): void
    {
        $path = $this->pmssMakeTempFile('pmss-log-jsonl-cleanup-');
        $contents = "invalid\nnull\n{\"event\":\"first\"}\n{\"event\":\"last\"}";
        file_put_contents($path, $contents);

        foreach ([null, new \RuntimeException('first'), new \Error('first'),
            new \RuntimeException('last'), new \Error('last')] as $failure) {
            $reader = null;
            $seen = [];
            $caught = null;
            try {
                $this->assertTrue(\pmssJsonLineFileEach($path, static function (array $entry) use (
                    $path, $failure, &$reader, &$seen
                ): void {
                    // Retain the actual reader to verify explicit close, not GC cleanup.
                    foreach (get_resources('stream') as $stream) {
                        if ((stream_get_meta_data($stream)['uri'] ?? null) === $path) {
                            $reader = $stream;
                        }
                    }
                    $seen[] = $entry['event'];
                    if ($failure !== null && $entry['event'] === $failure->getMessage()) {
                        throw $failure;
                    }
                }));
            } catch (\Throwable $error) {
                $caught = $error;
            }
            try {
                $this->assertSame($failure, $caught);
                $this->assertSame($failure !== null && $failure->getMessage() === 'first'
                    ? ['first'] : ['first', 'last'], $seen);
                $this->assertSame('resource (closed)', gettype($reader));
                $this->assertSame($contents, file_get_contents($path));
            } finally {
                // Keep a failing regression test from leaking its retained stream.
                if (is_resource($reader)) {
                    fclose($reader);
                }
            }
        }
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
