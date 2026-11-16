<?php
/** Metadata-only qBittorrent resume-size observation. @license GPL-3.0-only */
require_once __DIR__.'/identity.php';
require_once __DIR__.'/log.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

// libtorrent 2.0.8 bdecode_token::max_offset is independent of the token-count limit.
const PMSS_QBITTORRENT_RESUME_SIZE_LIMIT = 536870911;

/** Return oversized entries keyed by path digest; null means the scan was inconclusive. */
function pmssQbittorrentResumeOversized(string $home): ?array
{
    if (!pmssPathTargetIsSafe($home, true) || !is_dir($home)) return null;
    $oversized = [];
    foreach (['.local/share/qBittorrent', '.local/share/data/qBittorrent', '.config/qBittorrent'] as $relative) {
        $directory = $home.'/'.$relative.'/BT_backup';
        if (!pmssPathTargetIsSafe($directory, true)) return null;
        if (!is_dir($directory)) continue;
        $entries = pmssDirectoryEntriesRead($directory);
        if ($entries === false) return null;
        foreach ($entries as $entry) {
            if (substr($entry, -11) !== '.fastresume') continue;
            $path = $directory.'/'.$entry;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (!is_array($stat)) return null;
            // Never open resume data or follow a linked/non-regular entry.
            if (($stat['mode'] & 0170000) !== 0100000) continue;
            if ($stat['size'] > PMSS_QBITTORRENT_RESUME_SIZE_LIMIT) {
                $oversized[hash('sha256', $relative.'/'.$entry)] = $stat['size'];
            }
        }
    }
    ksort($oversized);
    return $oversized;
}

/** Record current findings outside the home and warn once per newly oversized file. */
function pmssQbittorrentResumeObserve(string $user, string $home, array $ops = []): bool
{
    if (!pmssValidateUsername($user) || basename($home) !== $user) return false;
    $oversized = pmssQbittorrentResumeOversized($home);
    if ($oversized === null) return false;
    $directory = $ops['stateDir'] ?? '/run/pmss/qbittorrent-resume';
    $path = $directory.'/'.$user.'.json';
    // Healthy accounts need no state directory or writes until a finding exists.
    if ($oversized === [] && !pmssPathExistsOrLink($path)) return true;
    if (!pmssEnsureSafeDir($directory, 0700)) return false;
    if (!pmssPathTargetIsSafe($path, false, true)) return false;
    $previous = pmssJsonFileReadAssoc($path, true) ?? [];
    if ($oversized === $previous) return true;
    // The existing cron lock serializes observations; publish state atomically.
    $recorded = pmssAtomicJsonFileWrite($path, $oversized, 0600);
    $log = $ops['log'] ?? 'pmssUserWatchdogLogTransition';
    foreach (array_diff_key($oversized, $previous) as $size) {
        $log($user, 'qBittorrent resume data exceeds decode size limit: size='.$size
            .' limit='.PMSS_QBITTORRENT_RESUME_SIZE_LIMIT, [
                'event' => 'qbittorrent_resume_oversized', 'level' => 'WARN', 'user' => $user,
                'size' => $size, 'limit' => PMSS_QBITTORRENT_RESUME_SIZE_LIMIT, 'stateRecorded' => $recorded,
            ]);
    }
    return $recorded;
}
