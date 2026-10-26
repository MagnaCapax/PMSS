<?php
/** Observe only the started native daemon's listeners, never another tenant's port. */

/** A failed safety check must also contain a daemon that ignores its clean shutdown. */
function pmssQbittorrentFrontendSocketStop(string $home, array $ops = []): bool
{
    if (pmssConfigureTorrentClientStop($home, $ops)) return true;
    $pids = $ops['pids'] ?? 'pmssConfigureTorrentClientNativePids';
    $signal = $ops['signal'] ?? static function ($pid, $signal): bool { return @posix_kill($pid, $signal); };
    $pause = $ops['pause'] ?? static function (): void { usleep(100000); };
    foreach ($pids($home) as $pid) {
        if (in_array($pid, $pids($home), true)) $signal($pid, 9);
    }
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if ($pids($home) === []) return true;
        $pause();
    }
    return false;
}

/** Collect listening socket addresses from account-owned process descriptors. */
function pmssQbittorrentFrontendListeners(array $pids, string $proc = '/proc'): ?array
{
    $inodes = [];
    foreach ($pids as $pid) {
        $fds = @scandir($proc.'/'.$pid.'/fd');
        if (!is_array($fds)) return null;
        foreach ($fds as $fd) {
            $link = @readlink($proc.'/'.$pid.'/fd/'.$fd);
            if (is_string($link) && preg_match('/\Asocket:\[([0-9]+)\]\z/', $link, $m) === 1) $inodes[$m[1]] = true;
        }
    }
    $listeners = [];
    foreach (['tcp', 'tcp6'] as $table) {
        if ($table === 'tcp6' && !file_exists($proc.'/net/tcp6')) continue;
        $raw = @file_get_contents($proc.'/net/'.$table);
        if (!is_string($raw)) return null;
        foreach (explode("\n", $raw) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 10 || $parts[3] !== '0A' || !isset($inodes[$parts[9]])) continue;
            list($address, $port) = explode(':', $parts[1]);
            $listeners[] = ['address' => $address, 'port' => hexdec($port)];
        }
    }
    return $listeners;
}

/** Classify only WebUI candidates; public BitTorrent peer listeners remain allowed. */
function pmssQbittorrentFrontendSocketStatus(array $listeners, int $port, ?int $configured = null, ?int $peer = null): string
{
    $ready = false;
    foreach ($listeners as $listener) {
        // Only an unexpected default port proves the wrong-section/default-credential hazard.
        if ($listener['port'] === 8080 && !in_array(8080, [$port, $configured, $peer], true)) return 'unsafe';
        if (in_array($listener['port'], [$port, $configured], true)) $ready = true;
    }
    return $ready ? 'ready' : 'waiting';
}

/** Stop only on positive unsafe evidence; loading resume data can take much longer than this wait. */
function pmssQbittorrentFrontendSocketWait(string $home, array $ops = []): bool
{
    $pids = $ops['pids'] ?? 'pmssConfigureTorrentClientNativePids';
    $listeners = $ops['listeners'] ?? 'pmssQbittorrentFrontendListeners';
    $stop = $ops['stop'] ?? static function (string $home) use ($ops): bool {
        return pmssQbittorrentFrontendSocketStop($home, $ops);
    };
    $notice = $ops['notice'] ?? 'pmssQbittorrentFrontendNotice';
    $refuse = $ops['refuse'] ?? 'pmssQbittorrentFrontendRefuse';
    $pause = $ops['pause'] ?? static function (): void { usleep(100000); };
    $ready = $ops['ready'] ?? static function (): bool { return true; };
    $observed = $ops['observed'] ?? static function (): bool { return true; };
    $read = $ops['read'] ?? static function (string $path) { return @file_get_contents($path); };
    $rawPort = trim((string) $read($home.'/.qbittorrentPort'));
    $port = ctype_digit($rawPort) && strlen($rawPort) <= 5 ? (int) $rawPort : 0;
    $config = (string) $read($home.'/.config/qBittorrent/qBittorrent.conf');
    $version = $ops['build'] ?? pmssQbittorrentFrontendBuildVersion($home, null, $ops);
    $configured = (int) trim((string) pmssQbittorrentFrontendWebUiValue($config, 'Port', $version), " \t\"");
    $values = pmssQbittorrentFrontendConfigValues($config);
    $old = is_string($version) && version_compare($version, '4.4', '<');
    $peer = (int) trim((string) ($old ? ($values['Preferences']['Connection\\PortRangeMin'] ?? '')
        : ($values['BitTorrent']['Session\\Port'] ?? $values['Preferences']['Connection\\PortRangeMin'] ?? '')), " \t\"");
    $status = 'waiting';
    $seen = false;
    $appeared = false;
    for ($attempt = 0; $attempt < ($ops['attempts'] ?? 200); $attempt++) {
        $native = $pids($home);
        if ($native === []) {
            // The launch is asynchronous: allow the process to appear; once seen, a vanished process has exited.
            if ($appeared || $attempt + 1 >= ($ops['appearAttempts'] ?? 50)) return false;
            $pause();
            continue;
        }
        $appeared = true;
        $seen = $observed($home) || $seen;
        $sockets = $listeners($native);
        $status = is_array($sockets) ? pmssQbittorrentFrontendSocketStatus($sockets, $port, $configured, $peer) : 'waiting';
        if ($status === 'unsafe') break;
        if ($status === 'ready' && $seen && $ready($home)) return $pids($home) !== [];
        if ($attempt + 1 < ($ops['attempts'] ?? 200)) $pause();
    }
    // Missing/unreadable sockets are inconclusive. The watchdog observes again each cycle.
    if ($status !== 'unsafe') return $pids($home) !== [] && $seen;
    $stopped = $stop($home);
    $wasRefused = $ops['refused'] ?? static function (string $home): bool {
        return file_exists($home.'/.config/pmss/torrentClients/qbittorrent.refused');
    };
    $reported = $wasRefused($home);
    $refuse($home);
    if (!$reported) $notice($home, $stopped
        ? 'qBittorrent stopped: an unexpected default WebUI listener on port 8080 was observed. Check the WebUI port and password before retrying.'
        : 'qBittorrent WebUI safety check failed and the daemon could not be stopped; manual recovery is needed.');
    return false;
}
