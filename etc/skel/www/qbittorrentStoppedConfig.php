<?php
/** Account-only restoration and private notices, shared by all customer starts. */
require_once __DIR__.'/qbittorrentRestore.php';
require_once __DIR__.'/qbittorrentAccountProcess.php';
require_once __DIR__.'/configureFileWrite.php';
require_once __DIR__.'/scriptsInc.php';

/** Account writes allow redirected parents, but never a redirected or special leaf. */
function pmssQbittorrentFrontendFileSafe(string $path): bool
{
    if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) return false;
    if (!pmssCustomerPathIsSafe($path)) return false;
    $stat = @lstat($path);
    return $stat === false || ($stat['mode'] & 0170000) === 0100000;
}

/** Record a plain failure without copying configuration values or credentials. */
function pmssQbittorrentFrontendNotice(string $home, string $message): void
{
    error_log('PMSS qBittorrent: '.$message);
    $dir = $home.'/.config/pmss/torrentClients';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return;
    if (!pmssQbittorrentFrontendFileSafe($dir.'/qbittorrent.notice')) return;
    pmssConfigureFileWrite($dir.'/qbittorrent.notice', $message."\n");
}

/** A successful start supersedes an older operation's failure notice. */
function pmssQbittorrentFrontendNoticeClear(string $home): void
{
    $path = $home.'/.config/pmss/torrentClients/qbittorrent.notice';
    if (is_file($path) && pmssQbittorrentFrontendFileSafe($path)) pmssConfigureFileWrite($path, '');
}

/** Refusals always replace any older operation's notice. */
function pmssQbittorrentFrontendStartRefused(string $home, string $message): bool
{
    pmssQbittorrentFrontendNotice($home, $message);
    return false;
}

/** Persist an unsafe-start refusal until the customer explicitly requests another start. */
function pmssQbittorrentFrontendRefuse(string $home): bool
{
    $path = $home.'/.config/pmss/torrentClients/qbittorrent.refused';
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true)) return false;
    return pmssQbittorrentFrontendFileSafe($path) && pmssConfigureFileWrite($path, "Unsafe WebUI listener observed.\n");
}

/** A panel Start or Configure choice is an explicit retry, unlike a watchdog cycle. */
function pmssQbittorrentFrontendRefusalClear(string $home): bool
{
    $path = $home.'/.config/pmss/torrentClients/qbittorrent.refused';
    if (!file_exists($path) && !is_link($path)) return true;
    return pmssQbittorrentFrontendFileSafe($path) && @unlink($path);
}

/** Prefer a nonempty _new config exactly as SettingsStorage does, then restore old names. */
function pmssQbittorrentFrontendRestore(string $home, array $ops = []): bool
{
    $stopped = $ops['stopped'] ?? static function (string $home) use ($ops): bool {
        return pmssQbittorrentFrontendAccountPids($ops['proc'] ?? '/proc', $ops['uid'] ?? null) === [];
    };
    $notice = $ops['notice'] ?? 'pmssQbittorrentFrontendNotice';
    if (!$stopped($home)) {
        $notice($home, 'qBittorrent is already running; configuration restore deferred until it stops.');
        return false;
    }
    $path = $home.'/.config/qBittorrent/qBittorrent.conf';
    $pending = dirname($path).'/qBittorrent_new.conf';
    foreach ([$path, $pending] as $file) if (!pmssQbittorrentFrontendFileSafe($file)) {
        $notice($home, is_link($path) ? 'qBittorrent.conf is a link; PMSS will not write through it'
            : 'qBittorrent start refused: the configuration path is unavailable.');
        return false;
    }
    if (file_exists($pending)) {
        $raw = @file_get_contents($pending);
        if (!is_string($raw)) { $notice($home, 'qBittorrent pending save could not be read.'); return false; }
        // A comments-only/empty fallback has no QSettings keys and is ignored upstream.
        if (pmssQbittorrentFrontendConfigValues($raw) !== [] && !@rename($pending, $path)) {
            $notice($home, 'qBittorrent pending save could not be restored.'); return false;
        }
    }
    $config = @file_get_contents($path);
    if (!is_string($config)) { $notice($home, 'qBittorrent.conf could not be read.'); return false; }
    $version = $ops['build'] ?? pmssQbittorrentFrontendBuildVersion($home, $ops['binary'] ?? null, $ops);
    $updated = pmssQbittorrentFrontendRestoreContent($config, $version, pmssQbittorrentFrontendLastBuild($home));
    if ($updated === $config) return true;
    $write = $ops['write'] ?? 'pmssConfigureFileWrite';
    if ($write($path, $updated)) return true;
    $notice($home, 'qBittorrent did not start: its settings file could not be updated. Check that ~/.config/qBittorrent is writable, then start it again.');
    return false;
}

/** Repair links while a daemon runs; defer config restoration to the next real start. */
function pmssQbittorrentFrontendRestoreForRepair(string $home, array $ops = []): bool
{
    $pids = $ops['accountPids'] ?? 'pmssQbittorrentFrontendAccountPids';
    $restore = $ops['restore'] ?? 'pmssQbittorrentFrontendRestore';
    return $pids() !== [] || $restore($home);
}

/** Hold the account switch lock across stopped configuration work. */
function pmssQbittorrentFrontendConfigLocked(string $home, callable $work): bool
{
    $dir = $home.'/.config/pmss/torrentClients';
    $path = $dir.'/qbittorrent.lock';
    if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || !pmssQbittorrentFrontendFileSafe($path))
        return pmssQbittorrentFrontendStartRefused($home, 'qBittorrent start refused: the operation lock is unavailable.');
    $lock = @fopen($path, 'ce');
    if (!is_resource($lock)) return pmssQbittorrentFrontendStartRefused($home, 'qBittorrent start refused: the operation lock could not be opened.');
    try {
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            pmssQbittorrentFrontendNotice($home, 'qBittorrent is busy with another operation; try again shortly.');
            return false;
        }
        return $work();
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
