<?php
/**
 * Shell execution helper for storage health.
 *
 * This intentionally captures output instead of streaming: cron logs should not
 * be flooded with raw smartctl output.
 */

if (!function_exists('pmssStorageHealthExecCapture')) {
    /**
     * Execute a shell command with captured output (no streaming).
     *
     * @return array{rc:int,stdout:string,stderr:string}
     */
    function pmssStorageHealthExecCapture(string $cmd, int $timeoutSec = 20): array
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $bash = '/bin/bash -lc '.escapeshellarg($cmd);
        $process = proc_open($bash, $descriptor, $pipes);
        if (!is_resource($process)) {
            return ['rc' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $timedOut = false;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if (empty($read)) {
                break;
            }
            $write = $except = [];
            $ready = stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            if ((microtime(true) - $startedAt) > $timeoutSec) {
                $timedOut = true;
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        if ($timedOut) {
            if (function_exists('proc_terminate')) {
                @proc_terminate($process);
            }
            @proc_close($process);
            return ['rc' => 124, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        $rc = proc_close($process);
        return ['rc' => (int) $rc, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}

