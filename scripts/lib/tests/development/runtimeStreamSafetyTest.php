<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime/system.php';
require_once dirname(__DIR__, 2).'/runtime/commandProcess.php';

class RuntimeStreamSafetyTest extends TestCase
{
    public function testProcessCloseRejectsOtherHandlesWithoutConsumingThem(): void
    {
        $stream = fopen('php://memory', 'w+');
        $closed = fopen('php://memory', 'w+');
        fclose($closed);
        $context = stream_context_create();
        fwrite($stream, 'sentinel');
        try {
            foreach ([null, false, 0, '', [], new \stdClass(), $closed, $stream, $context] as $invalid) {
                foreach ([null, false, 'status', [], ['exitcode' => -1], ['exitcode' => '7'], ['exitcode' => true]] as $status) {
                    $this->pmssAssertNoPhpWarnings(function () use ($invalid, $status): void {
                        $this->assertSame(-1, \pmssProcessCloseExitCode($invalid, $status));
                    });
                }
                foreach ([0, 7, 255] as $exitCode) {
                    $this->assertSame($exitCode, \pmssProcessCloseExitCode($invalid, ['exitcode' => $exitCode]));
                }
            }
            // Failed cleanup must not consume or close a caller-owned stream.
            $this->assertSame(8, ftell($stream));
            rewind($stream);
            $this->assertSame('sentinel', stream_get_contents($stream));
            $this->assertSame('stream-context', get_resource_type($context));
        } finally {
            fclose($stream);
        }
    }

    public function testProcessClosePreservesNativeExitCodesAndClosedHandleFallback(): void
    {
        foreach ([0, 7, 255] as $exitCode) {
            $process = proc_open('exit '.$exitCode, [], $pipes);
            $this->assertTrue(is_resource($process));
            try {
                $this->assertSame($exitCode, \pmssProcessCloseExitCode($process, ['exitcode' => 42]));
                $this->assertSame(false, is_resource($process));
                $this->assertSame(-1, \pmssProcessCloseExitCode($process));
                $this->assertSame($exitCode, \pmssProcessCloseExitCode($process, ['exitcode' => $exitCode]));
            } finally {
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
        }
    }

    public function testInvalidValuesRetainTheRequestedFallback(): void
    {
        foreach ([null, false, 0, '', 'STDOUT', [], new \stdClass()] as $value) {
            $this->assertSame(false, \pmssStreamIsTty($value));
            $this->assertSame(true, \pmssStreamIsTty($value, true));
        }
    }

    public function testProcessResourceUsesFallbackWithoutClosingProcess(): void
    {
        $process = proc_open('true', [], $pipes);
        $this->assertTrue(is_resource($process));
        try {
            $this->assertSame(false, \pmssStreamIsTty($process));
            $this->assertSame(true, \pmssStreamIsTty($process, true));
            $this->assertSame('process', get_resource_type($process));
        } finally {
            proc_close($process);
        }
    }

    public function testClosedStreamUsesFallback(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertTrue(is_resource($stream));
        fclose($stream);
        $this->assertSame(false, \pmssStreamIsTty($stream));
        $this->assertSame(true, \pmssStreamIsTty($stream, true));
    }

    public function testOpenStreamsRetainNativeDetectionAndContents(): void
    {
        foreach (['php://memory', 'php://temp'] as $path) {
            $stream = fopen($path, 'w+');
            $this->assertTrue(is_resource($stream));
            try {
                fwrite($stream, 'sentinel');
                $position = ftell($stream);
                foreach ([false, true] as $fallback) {
                    $expected = function_exists('stream_isatty') ? @stream_isatty($stream)
                        : (function_exists('posix_isatty') ? @posix_isatty($stream) : $fallback);
                    $this->assertSame($expected, \pmssStreamIsTty($stream, $fallback));
                    $this->assertSame($position, ftell($stream));
                }
                rewind($stream);
                $this->assertSame('sentinel', stream_get_contents($stream));
            } finally {
                fclose($stream);
            }
        }
    }
}
