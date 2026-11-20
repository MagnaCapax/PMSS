<?php
/** Exercise JSON Lines completion and cleanup faults with isolated memory streams. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LogReaderCompletionSafetyTest extends TestCase
{
    public function testCompletionRequiresEofAndClosureWithoutChangingThrowablePrecedence(): void
    {
        // Intercept acquisition and I/O: never read a host log or invoke a system command.
        $script = <<<'PHP'
namespace LogReaderCompletionFixture;
function observe($stage) {
    $GLOBALS['events'][] = $stage;
    if ($GLOBALS['stage'] === $stage) throw $GLOBALS['failure'];
}
function pmssLogWritePathIsSafe($path) { return true; }
function pmssRegularFilePathIsReadable($path) { return true; }
function pmssRegularFileOpenVerified($path) {
    observe('open');
    if ($GLOBALS['stage'] === 'open-false') return false;
    $handle = \fopen('php://memory', 'w+');
    \fwrite($handle, "{\"event\":\"ready\"}\n");
    \rewind($handle);
    return $GLOBALS['handle'] = $handle;
}
function fgets($handle) {
    observe('read');
    return $GLOBALS['stage'] === 'read-false' ? false : \fgets($handle);
}
function pmssJsonDecodeAssoc($line) { observe('decode'); return \json_decode($line, true); }
function feof($handle) { observe('eof'); return \feof($handle); }
function fclose($handle) {
    $GLOBALS['events'][] = 'close';
    \fclose($handle);
    if ($GLOBALS['closeResult'] instanceof \Throwable) throw $GLOBALS['closeResult'];
    return $GLOBALS['closeResult'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/log/jsonFiles.php', 'LogReaderCompletionFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([true, false, new \RuntimeException('close'), new \Error('close')] as $closeResult) {
    foreach (['valid', 'read-false', 'open-false', 'open', 'read', 'decode', 'handler', 'eof'] as $stage) {
        $throwing = in_array($stage, ['open', 'read', 'decode', 'handler', 'eof'], true);
        foreach ($throwing ? [new \RuntimeException($stage), new \Error($stage)] : [null] as $failure) {
            $GLOBALS['stage'] = $stage; $GLOBALS['failure'] = $failure;
            $GLOBALS['closeResult'] = $closeResult; $GLOBALS['handle'] = null;
            $GLOBALS['events'] = $seen = [];
            $caught = null; $result = null;
            try {
                $result = pmssJsonLineFileEach('/fixture/events.jsonl', static function ($row) use (&$seen) {
                    observe('handler');
                    $seen[] = $row;
                });
            } catch (\Throwable $error) { $caught = $error; }
            $acquired = $stage !== 'open' && $stage !== 'open-false';
            $expectedFailure = $acquired && $closeResult instanceof \Throwable ? $closeResult : $failure;
            $results[] = [$result, $caught === $expectedFailure, is_resource($GLOBALS['handle']),
                $GLOBALS['events'], $seen];
            // Observe a leak before closing any stream left by a regression.
            if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
        }
    }
}
// Collecting wrappers still retain delivered rows even when closure returns false.
$GLOBALS['stage'] = 'valid'; $GLOBALS['failure'] = null; $GLOBALS['closeResult'] = false;
$results[] = [pmssJsonLineFileRead('/fixture/events.jsonl'), pmssJsonLineFileLast('/fixture/events.jsonl')];
echo json_encode($results);
PHP;
        $expected = [];
        $row = ['event' => 'ready'];
        foreach (['true', 'false', 'exception', 'error'] as $close) {
            foreach (['valid', 'read-false', 'open-false', 'open', 'read', 'decode', 'handler', 'eof'] as $stage) {
                $throwing = in_array($stage, ['open', 'read', 'decode', 'handler', 'eof'], true);
                $acquired = $stage !== 'open' && $stage !== 'open-false';
                $events = ['open'];
                if ($acquired) $events[] = 'read';
                if (in_array($stage, ['valid', 'decode', 'handler', 'eof'], true)) $events[] = 'decode';
                if (in_array($stage, ['valid', 'handler', 'eof'], true)) $events[] = 'handler';
                if ($stage === 'valid' || $stage === 'eof') $events[] = 'read';
                if (in_array($stage, ['valid', 'read-false', 'eof'], true)) $events[] = 'eof';
                if ($acquired) $events[] = 'close';
                $closeThrows = $acquired && in_array($close, ['exception', 'error'], true);
                $result = $throwing || $closeThrows ? null : ($stage === 'valid' && $close === 'true');
                $entry = [$result, true, false, $events, in_array($stage, ['valid', 'eof'], true) ? [$row] : []];
                foreach ($throwing ? [0, 1] : [0] as $unused) $expected[] = $entry;
            }
        }
        $expected[] = [[$row], $row];
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }
}
