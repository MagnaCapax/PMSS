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
