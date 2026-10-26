<?php
/** Optional bounded credential subprocess; legacy callers keep their existing contract. */

/** Drain the requested nonblocking capture until exit/EOF or the caller's hard deadline. */
function pmssCredentialProcessRunTimed(string $command, ?string $credential, ?array &$output, bool $stderrOnly, float $timeout, array $ops = []): int
{
    if ($timeout <= 0 || !is_finite($timeout) || strpos($command, "\0") !== false
        || ($credential !== null && preg_match('/[\r\n]/', $credential))) return 1;
    $capture = $output !== null;
    if ($capture) $output = [];
    $outputFd = $stderrOnly ? 2 : 1;
    // Omitted console descriptors are inherited without seeking redirected logs.
    $descriptors = $capture ? [0 => ['pipe', 'r'], $outputFd => ['pipe', 'w']] : [0 => ['pipe', 'r']];
    $process = @proc_open('exec setsid '.$command.($capture && !$stderrOnly ? ' 2>&1' : ''), $descriptors, $pipes);
    if (!is_resource($process)) return 1;
    $clock = $ops['clock'] ?? static function (): float { return microtime(true); };
    $pause = $ops['pause'] ?? static function (): void { usleep(10000); };
    $deadline = $clock() + $timeout;
    $payload = $credential === null ? '' : $credential."\n";
    stream_set_blocking($pipes[0], false);
    if ($capture) stream_set_blocking($pipes[$outputFd], false);
    $offset = 0;
    $text = '';
    $status = proc_get_status($process);
    $pid = $status['pid'];
    $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
    $complete = false;
    try {
        do {
            if (is_resource($pipes[0])) {
                $written = @fwrite($pipes[0], substr($payload, $offset));
                if ($written === false) break;
                $offset += $written;
                if ($offset === strlen($payload)) { fclose($pipes[0]); $pipes[0] = null; }
            }
            if ($capture) {
                $chunk = @stream_get_contents($pipes[$outputFd], 8192);
                if (is_string($chunk)) $text .= substr($chunk, 0, max(0, 65536 - strlen($text)));
            }
            $status = proc_get_status($process);
            if ($status['exitcode'] >= 0) $exitCode = $status['exitcode'];
            if (!$status['running'] && (!$capture || feof($pipes[$outputFd]))) { $complete = true; break; }
            if ($clock() >= $deadline) break;
            $pause();
        } while (true);
    } finally {
        if (!$complete) {
            @posix_kill(-$pid, 9);
            @proc_terminate($process, 9);
        }
        if (is_resource($pipes[0])) fclose($pipes[0]);
        if ($capture) fclose($pipes[$outputFd]);
        $closed = proc_close($process);
    }
    if ($capture && trim($text) !== '') $output = explode("\n", trim($text));
    $rc = $exitCode ?? $closed;
    return $complete && $offset === strlen($payload) ? $rc : 124;
}
