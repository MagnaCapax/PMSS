<?php
/**
 * Restart migrated rTorrent sessions after account permissions are repaired.
 * @license GPL-3.0-only
 */
require_once dirname(__DIR__).'/rtorrent/scgi.php';


function pmssUserTransferRequestRtorrentRestart(string $home, string $localUser): void
{
    // Respect the per-user rTorrent opt-out (GH#470, GH#869): `.rtorrentDisable`
    // means "stop and stay stopped". checkRtorrent honours it on every cron
    // cycle, so restarting here would only be undone within a few minutes while
    // needlessly reloading the migrated session against the user's wishes. Skip.
    if (is_file(rtrim($home, '/').'/.rtorrentDisable')) {
        logMessage('[INFO] Skipping rTorrent restart: user opted out via .rtorrentDisable');
        return;
    }

    $wwwDir = $home.'/www';
    if (!is_dir($wwwDir) || is_link($wwwDir) || !pmssUserTransferIsPathWithinHome($wwwDir, $home)) {
        logMessage('[WARN] Skipping rTorrent restart marker (www dir missing or unsafe)');
        return;
    }

    $marker = $wwwDir.'/.rtorrentRestart';
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        logMessage('[SKIP] Requesting rTorrent restart marker (dry run)');
    } elseif (!pmssUserTransferCreateRestartMarker($marker, $localUser)) {
        logMessage('[WARN] Skipping rTorrent restart marker (could not create it safely)');
        return;
    }
    pmssUserTransferRunRtorrentRestart($home, $localUser);
}

/**
 * Ensure the restart marker exists without changing an existing regular file.
 * The consumer checks only for presence and then removes the marker. A missing
 * marker is created as the account; linked or non-file entries are refused.
 */
function pmssUserTransferCreateRestartMarker(string $marker, string $localUser): bool
{
    if (!pmssUserFilePathIsSafe($marker)) {
        return false;
    }
    $home = dirname(dirname($marker));
    if (!is_file($marker) && !pmssReplaceAccountFile($localUser, $home, $marker, '', 0644)) {
        return false;
    }
    logMessage('[OK] Requested rTorrent restart marker');
    return true;
}

/**
 * Execute the marker-driven restart immediately so migration is not cron-latent.
 */
function pmssUserTransferRunRtorrentRestart(string $home, string $localUser, ?callable $runner = null): void
{
    $restartScript = $home.'/.rtorrentRestart.php';
    if (!pmssRegularFilePathIsReadable($restartScript) || !pmssUserTransferIsPathWithinHome($restartScript, $home)) {
        logMessage('[WARN] Skipping rTorrent restart execution (restart script missing or unsafe)');
        return;
    }

    $requestedAt = time();
    $runner = $runner ?? 'runStep';
    if ($runner(
        'Running rTorrent restart request',
        pmssBuildUserShellCommand($localUser, 'cd '.escapeshellarg($home).' && php ./.rtorrentRestart.php')
    ) !== 0) {
        // A failed command cannot establish a reload, even if the socket looks live.
        logMessage('[WARN] rTorrent restart command failed; skipping reload verification');
        return;
    }
    pmssUserTransferVerifyRtorrentRestart($home, $localUser, $requestedAt);
}

/**
 * Count migrated rTorrent session payloads that should be loaded after restart.
 */
function pmssUserTransferRtorrentSessionTorrentCount(string $home): int
{
    $matches = glob(rtrim($home, '/').'/session/*.torrent');
    return is_array($matches) ? count($matches) : 0;
}

/**
 * Return the live rTorrent download_list count, or null when SCGI is unavailable.
 */
function pmssUserTransferRtorrentDownloadListCount(string $home, ?callable $caller = null): ?int
{
    $caller = $caller ?? 'rtorrentScgiCall';
    $downloads = $caller(rtrim($home, '/').'/.rtorrent.socket', 'download_list', [], 3);
    return is_array($downloads) ? count($downloads) : null;
}

/**
 * Wait briefly for rTorrent SCGI to answer after the restart request.
 */
function pmssUserTransferWaitForRtorrentDownloadListCount(string $home, int $timeoutSeconds = 30, ?callable $caller = null): ?int
{
    $deadline = time() + max(0, $timeoutSeconds);
    do {
        $count = pmssUserTransferRtorrentDownloadListCount($home, $caller);
        if ($count !== null) {
            return $count;
        }
        if (time() >= $deadline) {
            break;
        }
        sleep(min(5, max(1, $deadline - time())));
    } while (true);

    return null;
}

/**
 * Surface migration restart failures without changing transfer exit semantics.
 */
function pmssUserTransferVerifyRtorrentRestart(string $home, string $localUser, int $requestedAt): void
{
    $sessionCount = pmssUserTransferRtorrentSessionTorrentCount($home);
    if ($sessionCount === 0) {
        logMessage('[INFO] rTorrent restart verification skipped: no session torrent files found');
        return;
    }

    $socketPath = rtrim($home, '/').'/.rtorrent.socket';
    $loadedCount = pmssUserTransferWaitForRtorrentDownloadListCount($home);
    $socketMtime = file_exists($socketPath) ? (int) @filemtime($socketPath) : 0;
    if ($loadedCount === null && $socketMtime >= $requestedAt) {
        logMessage('[OK] rTorrent restart verification: socket refreshed after restart request');
        return;
    }
    if ($loadedCount !== null && $loadedCount > 0) {
        logMessage('[OK] rTorrent restart verification: live session loaded '.$loadedCount.' torrents for '.$localUser);
        return;
    }

    logMessage('[WARN] rTorrent restart verification could not confirm migrated session reload for '.$localUser.' (session files='.$sessionCount.', loaded='.(string) ($loadedCount ?? 'unknown').')');
}
