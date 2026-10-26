<?php
/** Account-only restoration and private notices, shared by all customer starts. */
require_once __DIR__.'/qbittorrentRestore.php';
require_once __DIR__.'/qbittorrentAccountProcess.php';
require_once __DIR__.'/qbittorrent.php';
require_once __DIR__.'/../torrentClients/identity.php';

/** Account writes allow redirected parents, but never a redirected or special leaf. */
function pmssQbittorrentFileSafe(string $path): bool
{
    if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) return false;
    $root = rtrim(getenv('PMSS_HOME_DIR') ?: '/home', '/');
    $real = realpath($path);
    if ($real === false) {
        $parent = realpath(dirname($path));
        if ($parent === false) return false;
        $real = $parent.'/'.basename($path);
    }
    if (is_link($path) || strpos($real, $root.'/') !== 0) return false;
    $stat = @lstat($path);
    return $stat === false || ($stat['mode'] & 0170000) === 0100000;
}

/** Record a plain failure without copying configuration values or credentials. */
function pmssQbittorrentNotice(string $home, string $message): void
{
    error_log('PMSS qBittorrent: '.$message);
    $dir = $home.'/.config/pmss/torrentClients';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return;
    if (!pmssQbittorrentFileSafe($dir.'/qbittorrent.notice')) return;
    pmssReplaceUserFilePreservingMetadata(realpath($dir).'/qbittorrent.notice', $message."\n", 0600);
}

/** Prefer a nonempty _new config exactly as SettingsStorage does, then restore old names. */
function pmssQbittorrentRestore(string $home, array $ops = []): bool
{
    $notice = $ops['notice'] ?? 'pmssQbittorrentNotice';
    $stopped = $ops['stopped'] ?? static function (string $home) use ($ops): bool {
        return pmssQbittorrentAccountPids($ops['proc'] ?? '/proc', $ops['uid'] ?? null) === [];
    };
    if (!$stopped($home)) {
        $notice($home, 'qBittorrent is already running; configuration restore deferred until it stops.');
        return false;
    }
    $path = $home.'/.config/qBittorrent/qBittorrent.conf';
    $pending = dirname($path).'/qBittorrent_new.conf';
    foreach ([$path, $pending] as $file) if (!pmssQbittorrentFileSafe($file)) {
        $notice($home, is_link($path) ? 'qBittorrent.conf is a link; PMSS will not write through it'
            : 'qBittorrent start refused: the configuration path is unavailable.');
        return false;
    }
    if (file_exists($pending)) {
        $raw = @file_get_contents($pending);
        if (!is_string($raw)) { $notice($home, 'qBittorrent pending save could not be read.'); return false; }
        // A comments-only/empty fallback has no QSettings keys and is ignored upstream.
        if (pmssQbittorrentConfigValues($raw) !== [] && !@rename($pending, $path)) {
            $notice($home, 'qBittorrent pending save could not be restored.'); return false;
        }
    }
    $config = @file_get_contents($path);
    if (!is_string($config)) { $notice($home, 'qBittorrent.conf could not be read.'); return false; }
    $version = $ops['build'] ?? pmssQbittorrentBuildVersion($home, $ops['binary'] ?? null, $ops);
    $updated = pmssQbittorrentRestoreContent($config, $version, pmssQbittorrentLastBuild($home));
    if ($updated === $config) return true;
    $write = $ops['write'] ?? static function (string $path, string $content): bool {
        return pmssReplaceUserFilePreservingMetadata(realpath($path), $content, (int) @fileperms($path) & 0777);
    };
    if ($write($path, $updated)) return true;
    $notice($home, 'qBittorrent did not start: its settings file could not be updated. Check that ~/.config/qBittorrent is writable, then start it again.');
    return false;
}

require_once __DIR__.'/qbittorrentPasswordStopped.php';

/** Hold the account switch lock across stopped configuration work. */
function pmssQbittorrentConfigLocked(string $home, callable $work, array $ops = []): bool
{
    $dir = $home.'/.config/pmss/torrentClients';
    $path = $dir.'/qbittorrent.lock';
    $notice = $ops['notice'] ?? 'pmssQbittorrentNotice';
    if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || !pmssQbittorrentFileSafe($path)) {
        $notice($home, 'qBittorrent operation refused: the operation lock is unavailable.'); return false;
    }
    $lock = @fopen($path, 'ce');
    if (!is_resource($lock)) { $notice($home, 'qBittorrent operation refused: the operation lock could not be opened.'); return false; }
    try {
        $pause = $ops['pause'] ?? static function (): void { usleep(100000); };
        for ($attempt = 0; $attempt < ($ops['attempts'] ?? 1); $attempt++) {
            if (@flock($lock, LOCK_EX | LOCK_NB)) return $work();
            if ($attempt + 1 < ($ops['attempts'] ?? 1)) $pause();
        }
        $notice($home, !empty($ops['passwordSync'])
            ? 'qBittorrent password synchronization failed: lock timeout; another operation is busy.'
            : 'qBittorrent is busy with another operation; try again shortly.');
        return false;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
