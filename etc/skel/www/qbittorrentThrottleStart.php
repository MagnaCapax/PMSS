<?php
/** Every customer start converges an optional PMSS upload cap under the held switch lock. */
require_once __DIR__.'/qbittorrentThrottleStopped.php';

/** Only root-owned, non-writable cap files carry operator policy, matching the operator reader. */
function pmssQbittorrentFrontendThrottleRead(string $home): ?int
{
    $raw = \PMSS\Customer\pmssReadRegularFileContentsVerified($home.'/.torrentThrottle', 0, 64, $stat);
    if ($raw === null || ($stat['mode'] & 0022) !== 0) return null;
    $account = posix_getpwuid(posix_geteuid());
    $group = posix_getgrgid($stat['gid']);
    if (!is_array($group) || !in_array($group['name'], ['root', $account['name'] ?? ''], true)) return null;
    return pmssQbittorrentFrontendThrottleNumber($raw);
}

/** Restore already ran and the launch caller owns the lock; do not acquire it twice. */
function pmssQbittorrentFrontendThrottleStart(string $home, array $ops = []): bool
{
    $read = $ops['throttleRead'] ?? 'pmssQbittorrentFrontendThrottleRead';
    $throttle = $read($home);
    // Canonical policy is root:root 0640: unreadable or absent never means removal here.
    if (($throttle ?? 0) <= 0) return true;
    return pmssQbittorrentFrontendThrottleAccount($home, $throttle, true,
        $ops + ['restored' => true, 'lockHeld' => true]) !== null;
}
