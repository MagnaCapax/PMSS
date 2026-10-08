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
    // rsync -a can leave the imported share owned by the source UID. Repair it
    // before asking the local account to rename that directory.
    if ($remoteUser !== $localUser) {
        pmssUserTransferRenameRutorrentShare($home, $remoteUser, $localUser);
    }
    if ($remoteUser !== $localUser) {
        pmssUserTransferVerifyPayloadOwnership($localUser, $home);
    }
    pmssUserTransferRequestRtorrentRestart($home, $localUser);

    // Advisory only: keep exit-code semantics while surfacing suspiciously incomplete copies.
    pmssUserTransferVerifyCompleteness($cfg, $home, $scratchPaths['expect'], $scratchPaths['remoteSizeScript']);
}

function pmssUserTransferRenameRutorrentShare(string $home, string $remoteUser, string $localUser): void
{
    $share = $home.'/www/rutorrent/share';
    clearstatcache(true, $share);
    if (is_link($share)) {
        // ADR 0041's managed link is a normal customer layout. Address its
        // durable target directly so no root or account command traverses it.
        if (@readlink($share) !== '../../.local/share/pmss/rutorrent/share'
            || !pmssPathTargetIsSafe(dirname($share), true)) {
            logMessage('[WARN] Skipping ruTorrent rename (unexpected share link)');
            return;
        }
        $share = $home.'/.local/share/pmss/rutorrent/share';
    }
    $src = $share.'/users/'.$remoteUser;
    $dst = $share.'/users/'.$localUser;
    clearstatcache(true, $src);
    clearstatcache(true, $dst);
    if (!file_exists($src) && !is_link($src)) {
        return;
    }
    if (!pmssPathTargetIsSafe($src, true, true) || !is_dir($src)
        || !pmssPathTargetIsSafe($dst, true, true) || file_exists($dst) || is_link($dst)
        || !pmssUserTransferIsPathWithinHome($src, $home)
        || !pmssUserTransferIsPathWithinHome(dirname($dst), $home)) {
        logMessage('[WARN] Skipping ruTorrent rename (unsafe source or destination)');
        return;
    }
    $command = 'mv -T -- '.escapeshellarg($src).' '.escapeshellarg($dst);
    if (pmssAccountPathRun($localUser, $home, [$src, $dst], $command)) {
        logMessage('[OK] Renamed ruTorrent user directory');
        return;
    }
    logMessage('[WARN] Unable to rename ruTorrent user directory as account');
}

function pmssUserTransferRequestRtorrentRestart(string $home, string $localUser): void
{
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
function pmssUserTransferRunRtorrentRestart(string $home, string $localUser): void
{
    $restartScript = $home.'/.rtorrentRestart.php';
    if (!pmssRegularFilePathIsReadable($restartScript) || !pmssUserTransferIsPathWithinHome($restartScript, $home)) {
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

    logMessage('[WARN] rTorrent restart verification could not confirm migrated session reload for '.$localUser.' (session files='.$sessionCount.', loaded='.(string) ($loadedCount ?? 'unknown').')');
}
