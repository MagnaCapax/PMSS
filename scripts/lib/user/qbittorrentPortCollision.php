<?php
/** Read-only evidence of another account occupying a native qBittorrent WebUI port.
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../userLifecycle.php';

/** Match only IPv4 listeners reachable through the existing loopback proxy. */
function pmssQbittorrentPortHasForeignListener(string $table, int $port, int $uid): bool
{
    if (!pmssNetworkPortInRange($port, 1024) || $uid < 1) return false;
    foreach (explode("\n", $table) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) < 10 || $fields[3] !== '0A'
            || !ctype_digit($fields[7]) || !ctype_digit($fields[9]) || $fields[9] === '0') continue;
        if (preg_match('/\A(?:0100007F|00000000):([0-9A-Fa-f]{4})\z/', $fields[1], $match) !== 1) continue;
        // Same-UID launchers and stray instances are not cross-account collisions.
        if (hexdec($match[1]) === $port && (int) $fields[7] !== $uid) return true;
    }
    return false;
}

/** One snapshot per watchdog process avoids a kernel table read per account. */
function pmssQbittorrentPortSocketTableRead(): ?string
{
    static $table = null;
    if ($table === null) $table = @file_get_contents('/proc/net/tcp');
    return is_string($table) ? $table : null;
}

/** Log positive foreign-owner evidence without changing ports, configs or process state. */
function pmssQbittorrentPortCollisionObserve(string $user, string $home, array $ops = []): bool
{
    if (!pmssTorrentPortUserHomeContextIsSafe($user, $home)) return false;
    $port = pmssTorrentPortExpectedRead($home.'/.qbittorrentPort');
    $accountRead = $ops['account'] ?? 'posix_getpwnam';
    $account = $accountRead($user);
    if ($port === null || !is_array($account) || !is_int($account['uid'] ?? null) || $account['uid'] < 1) return false;
    $read = $ops['read'] ?? 'pmssQbittorrentPortSocketTableRead';
    $table = $read();
    // Missing proc access (including tenant socket-table privacy) is inconclusive.
    if (!is_string($table) || !pmssQbittorrentPortHasForeignListener($table, $port, $account['uid'])) return false;
    $log = $ops['log'] ?? static function (string $user, int $port): void {
        pmssUserLifecycleContextLog('port', 'collision', $user, [
            'status' => 'WARN', 'service' => 'qbittorrent', 'port' => $port,
            'message' => 'Assigned qBittorrent WebUI port has a foreign IPv4 listener; automatic reassignment is unavailable.',
        ]);
    };
    $log($user, $port);
    return true;
}
