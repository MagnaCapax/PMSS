<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/runtime/commandPipes.php';

class RuntimeCommandPipesSafetyTest extends TestCase
{
    public function testInvalidChannelsLeaveTheOtherStreamUnreadAndOpen(): void
    {
        $valid = tmpfile();
        $closed = tmpfile();
        fclose($closed);
        $context = stream_context_create();
        fwrite($valid, 'retained output');
        rewind($valid);
        try {
            foreach ([null, false, 0, 'pipe', [], new \stdClass(), $closed, $context] as $invalid) {
                foreach ([1, 2] as $index) {
                    foreach (['', 'stream_select failed'] as $diagnostic) {
                        $pipes = [1 => $valid, 2 => $valid];
                        $pipes[$index] = $invalid;
                        $this->pmssAssertNoPhpWarnings(function () use ($pipes, $diagnostic): void {
                            $this->assertSame(
                                ['stdout' => '', 'stderr' => $diagnostic, 'timed_out' => false],
                                \pmssCommandOutputPipesDrain($pipes, 0, microtime(true), 0, false, $diagnostic)
                            );
                        });
                        $this->assertTrue(is_resource($valid));
                        $this->assertSame(0, ftell($valid));
                    }
                }
            }
            foreach ([[], [1 => $valid], [2 => $valid]] as $pipes) {
                $this->pmssAssertNoPhpWarnings(function () use ($pipes): void {
                    $this->assertSame(
                        ['stdout' => '', 'stderr' => '', 'timed_out' => false],
                        \pmssCommandOutputPipesDrain($pipes, 0, microtime(true))
                    );
                });
                $this->assertSame(0, ftell($valid));
            }
        } finally {
            fclose($valid);
        }
    }

    public function testValidChannelsPreserveBinaryOutputAndBufferTails(): void
    {
        foreach ([0, 3] as $limit) {
            foreach ([['', ''], ["out\0data\n", "err\0data\n"], ['', 'error only']] as $contents) {
                $pipes = [1 => tmpfile(), 2 => tmpfile()];
                try {
                    foreach ([1, 2] as $index) {
                        fwrite($pipes[$index], $contents[$index - 1]);
                        rewind($pipes[$index]);
                    }
                    $this->assertSame([
                        'stdout' => $limit > 0 ? substr($contents[0], -$limit) : $contents[0],
                        'stderr' => $limit > 0 ? substr($contents[1], -$limit) : $contents[1],
                        'timed_out' => false,
                    ], \pmssCommandOutputPipesDrain($pipes, 0, microtime(true), $limit));
                    // The caller still owns closing both capture streams.
                    $this->assertTrue(is_resource($pipes[1]) && is_resource($pipes[2]));
                } finally {
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                }
            }
        }
    }
}
