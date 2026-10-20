<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** A cron job whose output is appended to its own log file must not get each Logger line twice. */
class LogStdoutDuplicateTest extends TestCase
{
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
        $proc = proc_open(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code), $spec, $pipes);
        $this->assertTrue(is_resource($proc), 'child php did not start');
        proc_close($proc);
        return substr_count((string) file_get_contents($log), 'marker-line');
    }
}
