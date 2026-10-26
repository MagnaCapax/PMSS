<?php
/** Bounded --version capture; a descendant holding stdout cannot extend the deadline. */

/** Start a separate process group and kill the whole probe on deadline or excess output. */
function pmssQbittorrentFrontendBuildProbe(string $binary, array $ops = []): ?string
{
    $process = @proc_open('exec setsid '.escapeshellarg($binary).' --version',
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) return null;
    stream_set_blocking($pipes[1], false);
    $clock = $ops['clock'] ?? static function (): float { return microtime(true); };
    $pause = $ops['pause'] ?? static function (): void { usleep(10000); };
    $deadline = $clock() + 2;
    $output = '';
    $status = proc_get_status($process);
    $pid = $status['pid'];
    $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
    $complete = false;
    try {
        do {
            $chunk = @stream_get_contents($pipes[1], 4097 - strlen($output));
            if (is_string($chunk)) $output .= $chunk;
            $status = proc_get_status($process);
            if ($status['exitcode'] >= 0) $exitCode = $status['exitcode'];
            if (!$status['running'] && feof($pipes[1])) { $complete = true; break; }
            if (strlen($output) > 4096 || $clock() >= $deadline) break;
            $pause();
        } while (true);
    } finally {
        // setsid makes pid the group leader; terminate descendants as well as the direct child.
        if (!$complete) {
            @posix_kill(-$pid, 9);
            @proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        $closed = proc_close($process);
    }
    $rc = $exitCode ?? $closed;
    return $complete && $rc === 0 && strlen($output) <= 4096 ? $output : null;
}
