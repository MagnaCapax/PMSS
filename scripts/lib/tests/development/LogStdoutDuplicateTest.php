<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** A cron job whose output is appended to its own log file must not get each Logger line twice. */
class LogStdoutDuplicateTest extends TestCase
{
    public function testMissingAndScalarStreamConstantsReturnFalse(): void
    {
        $script = <<<'PHP'
set_error_handler(static function ($severity, $message) { throw new \ErrorException($message, 0, $severity); });
$results = [];
foreach (['', 'PMSS_TEST_UNDEFINED_LOG_STREAM', 'PHP_INT_MAX', 'PHP_VERSION'] as $stream) {
    $results[] = pmssLogStreamIsFile($stream, '/dev/null');
}
echo json_encode($results);
PHP;
        $this->assertSame([false, false, false, false], $this->pmssRunInlinePhpRequireJson(
            dirname(__DIR__, 2).'/log.php', $script, [], '2>&1'));
    }

    public function testClosedStandardStreamsReturnFalse(): void
    {
        // Only child streams are closed; the suite's own output remains available.
        foreach (['STDOUT' => 'STDERR', 'STDERR' => 'STDOUT'] as $stream => $reportStream) {
            $script = 'set_error_handler(static function ($severity, $message) { throw new \\ErrorException($message, 0, $severity); });'
                .'fclose('.$stream.'); fwrite('.$reportStream.', json_encode([pmssLogStreamIsFile('
                .var_export($stream, true).", '/dev/null')]));";
            $this->assertSame([false], $this->pmssRunInlinePhpRequireJson(
                dirname(__DIR__, 2).'/log.php', $script, [], '2>&1'));
        }
    }

    public function testMalformedPathsReturnFalseBeforeMetadataProbes(): void
    {
        $script = <<<'PHP'
namespace LogStreamPathFixture;
function fstat($handle) { throw new \RuntimeException('unexpected stream probe'); }
function stat($path) { throw new \RuntimeException('unexpected path probe'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/log.php',
            'LogStreamPathFixture', ['log/append.php']);
        $script .= <<<'PHP'
$results = [];
foreach (['', "\0", "\0/unused/log", "/unused/log\0", "/unused/lo\0g", "/unused/log\0suffix"] as $path) {
    $results[] = pmssLogStreamIsFile('STDOUT', $path);
}
echo json_encode($results);
PHP;
        $this->assertSame(array_fill(0, 6, false), $this->pmssRunInlinePhpJson($script));
    }

    public function testOnlyCompleteMatchingMetadataSuppressesConsoleMirroring(): void
    {
        // Intercept both observations; no stream or path is modified by these faults.
        $script = <<<'PHP'
namespace LogStreamIdentityFixture;
function fstat($handle) { $GLOBALS['events'][] = 'stream'; return $GLOBALS['open']; }
function stat($path) { $GLOBALS['events'][] = $path; return $GLOBALS['file']; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/log.php',
            'LogStreamIdentityFixture', ['log/append.php']);
        $script .= <<<'PHP'
set_error_handler(static function ($severity, $message) { throw new \ErrorException($message, 0, $severity); });
$valid = ['dev' => 1, 'ino' => 2];
$results = [];
foreach ([false, [], ['dev' => 1], ['ino' => 2], ['dev' => null, 'ino' => 2],
    ['dev' => 1, 'ino' => null]] as $invalid) {
    foreach ([[$invalid, $valid], [$valid, $invalid], [$invalid, $invalid]] as $pair) {
        [$GLOBALS['open'], $GLOBALS['file']] = $pair;
        $GLOBALS['events'] = [];
        $results[] = [pmssLogStreamIsFile('STDOUT', '/unused/log'), $GLOBALS['events']];
    }
}
// Complete observations retain strict comparison, device zero and the zero-inode refusal.
foreach ([[$valid, $valid], [$valid, ['dev' => 3, 'ino' => 2]],
    [$valid, ['dev' => 1, 'ino' => 3]], [['dev' => 1, 'ino' => 0], ['dev' => 1, 'ino' => 0]],
    [['dev' => 0, 'ino' => 2], ['dev' => 0, 'ino' => 2]], [$valid, ['dev' => '1', 'ino' => 2]]] as $pair) {
    [$GLOBALS['open'], $GLOBALS['file']] = $pair;
    $GLOBALS['events'] = [];
    $results[] = [pmssLogStreamIsFile('STDOUT', '/unused/log'), $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $expected = array_fill(0, 18, [false, ['stream', '/unused/log']]);
        foreach ([true, false, false, false, true, false] as $result) {
            $expected[] = [$result, ['stream', '/unused/log']];
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }

    public function testLineIsWrittenOnceWhenOutputIsAppendedToTheLogFile(): void
    {
        $this->assertSame(1, $this->countLines('STDOUT', true));
    }

    public function testStderrLoggerLineIsWrittenOnceWhenStderrIsTheLogFile(): void
    {
        $this->assertSame(1, $this->countLines('STDERR', true));
    }

    public function testLineIsStillPrintedWhenOutputGoesElsewhere(): void
    {
        $this->assertSame(1, $this->countLines('STDOUT', false));
        $this->assertSame(1, $this->countLines('STDERR', false));
    }

    /** Runs one Logger message in a child PHP; returns how many copies landed in the log file. */
    private function countLines(string $stream, bool $intoLog): int
    {
        $dir = $this->pmssMakeTempDir('pmss-logdup-', 0700);
        $log = $dir.'/job.log';
        $other = $dir.'/other.out';
        touch($log);
        $code = 'require '.var_export(dirname(__DIR__, 2).'/logger.php', true).';'
            .'(new \Logger("job", '.var_export($dir, true).', '.var_export($dir, true).', null, '.($stream === 'STDERR' ? 'true' : 'false').'))->msg("marker-line");';
        $target = $intoLog ? $log : $other;
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $target, 'a'], 2 => ['file', $target, 'a']];
        $proc = proc_open($this->pmssBuildInlinePhpCommand($code), $spec, $pipes);
        $this->assertTrue(is_resource($proc), 'child php did not start');
        proc_close($proc);
        return substr_count((string) file_get_contents($log), 'marker-line');
    }
}
