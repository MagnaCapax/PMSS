<?php
/**
 * Post-transfer convergence steps for user transfers.
 * Owns local account cleanup after remote data has landed.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/completenessVerify.php';
require_once __DIR__.'/localUserSafety.php';
require_once __DIR__.'/qbittorrentCategories.php';
require_once __DIR__.'/sessionRewrite.php';
require_once dirname(__DIR__).'/user/trafficLimit.php';
require_once dirname(__DIR__).'/rtorrent/scgi.php';

function pmssUserTransferPostSetup(array $cfg, string $home, array $scratchPaths): void
{
    $localUser = $cfg['localUser'];
    $remoteUser = $cfg['remoteUser'];

    if ($remoteUser !== $localUser) {
        pmssUserTransferRenameRutorrentShare($home, $remoteUser, $localUser);
    }

    // Keep migrated torrent clients usable without copying server-specific config wholesale.
    pmssUserTransferRewriteRtorrentSessionPaths($cfg, $home);
    pmssUserTransferPreserveQbittorrentCategories(
        $cfg,
        $home,
        $scratchPaths['expect'],
        $scratchPaths['qbittorrentProbeScript'],
        $scratchPaths['qbittorrentConfig'],
        $scratchPaths['qbittorrentCategories']
    );
    pmssTrafficLimitHomeArtifactReconcile($localUser, dirname(rtrim($home, '/')), null, 'logMessage');

    runStep(
        'Normalising user permissions',
        pmssBuildCommand('php', [dirname(__DIR__, 2).'/util/userPermissions.php', $localUser])
    );
    pmssUserTransferRequestRtorrentRestart($home, $localUser);

    // Advisory only: keep exit-code semantics while surfacing suspiciously incomplete copies.
    pmssUserTransferVerifyCompleteness($cfg, $home, $scratchPaths['expect'], $scratchPaths['remoteSizeScript']);
}

function pmssUserTransferRenameRutorrentShare(string $home, string $remoteUser, string $localUser): void
{
    $src = $home.'/www/rutorrent/share/users/'.$remoteUser;
    $dst = $home.'/www/rutorrent/share/users/'.$localUser;
    if (!file_exists($src) || file_exists($dst)) {
        return;
    }
    if (!pmssUserTransferIsPathWithinHome($src, $home) || !pmssUserTransferIsPathWithinHome(dirname($dst), $home)) {
        logMessage('[WARN] Skipping ruTorrent rename (path escapes home)');
        return;
    }
    if (@rename($src, $dst)) {
        logMessage('[OK] Renamed ruTorrent user directory');
        return;
    }
    runStep('Renaming ruTorrent user directory', pmssBuildCommand('mv', [$src, $dst]));
}

function pmssUserTransferRequestRtorrentRestart(string $home, string $localUser): void
{
    $wwwDir = $home.'/www';
    if (!is_dir($wwwDir) || is_link($wwwDir) || !pmssUserTransferIsPathWithinHome($wwwDir, $home)) {
        logMessage('[WARN] Skipping rTorrent restart marker (www dir missing or unsafe)');
        return;
    }

    $marker = $wwwDir.'/.rtorrentRestart';
    runStep('Requesting rTorrent restart marker', pmssBuildCommand('touch', [$marker]));
    runStep('Setting rTorrent restart marker owner', pmssBuildCommand('chown', [$localUser.':'.$localUser, $marker]));
    pmssUserTransferRunRtorrentRestart($home, $localUser);
}

/**
 * Execute the marker-driven restart immediately so migration is not cron-latent.
 */
function pmssUserTransferRunRtorrentRestart(string $home, string $localUser): void
{
    $restartScript = $home.'/.rtorrentRestart.php';
    if (!is_file($restartScript) || is_link($restartScript) || !pmssUserTransferIsPathWithinHome($restartScript, $home)) {
        logMessage('[WARN] Skipping rTorrent restart execution (restart script missing or unsafe)');
        return;
    }

    $requestedAt = time();
    runStep(
        'Running rTorrent restart request',
        pmssBuildUserShellCommand($localUser, 'cd '.escapeshellarg($home).' && php ./.rtorrentRestart.php')
    );
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

    logMessage('[WARN] rTorrent restart verification could not confirm migrated session reload for '.$localUser.' (session files='.$sessionCount.', loaded='.($loadedCount === null ? 'unknown' : (string) $loadedCount).')');
}
