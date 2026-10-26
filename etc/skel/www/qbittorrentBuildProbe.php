<?php
/** Bounded --version capture; a descendant holding stdout cannot extend the deadline. */

/** Start a separate process group and kill the whole probe on deadline or excess output. */
function pmssQbittorrentFrontendBuildProbe(string $binary, array $ops = []): ?string
{
    // --version still loads and saves the profile's qBittorrent.conf, so the probe gets an empty private profile.
    $profile = rtrim(sys_get_temp_dir(), '/').'/pmss-qbt-probe-'.bin2hex(random_bytes(8));
    if (!@mkdir($profile, 0700)) return null;
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $profile, 'XDG_CONFIG_HOME' => $profile.'/.config',
        'XDG_DATA_HOME' => $profile.'/.local/share', 'XDG_CACHE_HOME' => $profile.'/.cache'];
    $process = @proc_open('exec setsid '.escapeshellarg($binary).' --version',
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $profile, $env);
    if (!is_resource($process)) { pmssQbittorrentFrontendBuildProbeClean($profile); return null; }
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
        pmssQbittorrentFrontendBuildProbeClean($profile);
    }
    $rc = $exitCode ?? $closed;
    return $complete && $rc === 0 && strlen($output) <= 4096 ? $output : null;
}

/** Remove the probe's private profile without following links it may contain. */
function pmssQbittorrentFrontendBuildProbeClean(string $profile): void
{
    try {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($profile, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $path = $item->getPathname();
            if (!is_link($path) && is_dir($path)) @rmdir($path); else @unlink($path);
        }
    } catch (UnexpectedValueException $e) {
        // An unreadable leftover stays private (0700) to this account.
    }
    @rmdir($profile);
}
