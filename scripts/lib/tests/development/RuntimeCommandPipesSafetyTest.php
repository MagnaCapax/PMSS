<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/runtime/commandPipes.php';

class RuntimeCommandPipesSafetyTest extends TestCase
{
    public function testPipeCleanupSkipsAlreadyClosedAndInvalidHandles(): void
    {
        $live = tmpfile();
        $closed = tmpfile();
        fclose($closed);
        $this->pmssAssertNoPhpWarnings(function () use ($live, $closed): void {
            \pmssProcessPipesClose([$live, $closed, null]);
            \pmssProcessPipesClose([$live]);
        });
        $this->assertFalse(is_resource($live));
    }

    public function testPipeCleanupAttemptsEveryCloseAndRethrowsFirstFailure(): void
    {
        $script = <<<'PHP'
namespace CommandPipeCloseFixture;
function fclose($handle) {
    $index = array_search($handle, $GLOBALS['handles'], true);
    $GLOBALS['events'][] = $index;
    \fclose($handle);
    if (isset($GLOBALS['failures'][$index])) throw $GLOBALS['failures'][$index];
    return !$GLOBALS['returnFalse'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandPipes.php', 'CommandPipeCloseFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([[], [0], [1], [2], [0, 1], [1, 2], [2, 0, 1]] as $slots) {
    foreach ([false, true] as $errorFirst) {
        foreach ([false, true] as $returnFalse) {
            $GLOBALS['handles'] = [\fopen('php://memory', 'w+'), \fopen('php://memory', 'w+'), \fopen('php://memory', 'w+')];
            $GLOBALS['events'] = []; $GLOBALS['failures'] = []; $GLOBALS['returnFalse'] = $returnFalse;
            foreach ($slots as $index) {
                // Mix throwable families so later failures cannot replace the first.
                $GLOBALS['failures'][$index] = ($errorFirst !== ($index % 2 === 1))
                    ? new \Error('close failure') : new \RuntimeException('close failure');
            }
            $handles = $GLOBALS['handles'];
            $closed = \fopen('php://memory', 'w+');
            \fclose($closed);
            $context = stream_context_create();
            $caught = null; $result = null;
            try {
                // Invalid entries and aliases stay harmless even after a close throws.
                $result = pmssProcessPipesClose([$handles[0], null, $closed, $context, $handles[1],
                    false, [], new \stdClass(), $handles[2], $handles[0], $handles[1], $handles[2]]);
            } catch (\Throwable $error) { $caught = $error; }
            $live = array_filter($handles, 'is_resource');
            foreach ($live as $handle) \fclose($handle); // Observe leaks before fixture cleanup.
            $expected = $slots === [] ? null : $GLOBALS['failures'][min($slots)];
            $results[] = [$slots, $errorFirst, $returnFalse, $caught === $expected, count($live), $GLOBALS['events'], $result];
        }
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(28, count($results));
        foreach ($results as [$slots, $errorFirst, $returnFalse, $sameFailure, $live, $events, $result]) {
            $this->assertSame([true, 0, [0, 1, 2], null], [$sameFailure, $live, $events, $result],
                json_encode([$slots, $errorFirst, $returnFalse]));
        }
    }

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
