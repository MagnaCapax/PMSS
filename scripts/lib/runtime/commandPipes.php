<?php
/** Nonblocking output collection for command capture. */

function pmssProcessPipeDescriptorSpec(string $stdinMode = 'r', string $stdoutMode = 'w', string $stderrMode = 'w'): array
{
    return [0 => ['pipe', $stdinMode], 1 => ['pipe', $stdoutMode], 2 => ['pipe', $stderrMode]];
}

/**
 * @return array{stdout:string,stderr:string,timed_out:bool}
 */
function pmssCommandOutputPipesDrain(array $pipes, int $timeoutSec, float $startedAt, int $maxBuffer = 0, bool $mirrorOutput = false, string $streamSelectError = ''): array
{
    $output = ['stdout' => '', 'stderr' => ''];
    $timedOut = false;

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        $read = [];
        foreach ([1, 2] as $index) {
            if (!feof($pipes[$index])) {
                $read[] = $pipes[$index];
            }
        }
        if ($read === []) {
            break;
        }

        $write = $except = [];
        $ready = stream_select($read, $write, $except, 0, 200000);
        if ($ready === false) {
            if ($streamSelectError !== '') {
                $output['stderr'] .= ($output['stderr'] !== '' ? "\n" : '').$streamSelectError;
            }
            break;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            // Both channels retain the same tail; mirroring preserves their original sinks.
            $channel = $stream === $pipes[1] ? 'stdout' : 'stderr';
            $output[$channel] .= $chunk;
            if ($maxBuffer > 0 && strlen($output[$channel]) > $maxBuffer) {
                $output[$channel] = substr($output[$channel], -$maxBuffer);
            }
            if ($mirrorOutput) {
                if ($channel === 'stdout') {
                    echo $chunk;
                    fflush(STDOUT);
                } else {
                    fwrite(STDERR, $chunk);
                    fflush(STDERR);
                }
            }
        }

        if ($timeoutSec > 0 && (microtime(true) - $startedAt) > $timeoutSec) {
            $timedOut = true;
            break;
        }
    }

    return $output + ['timed_out' => $timedOut];
}
