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

    runStep(
        'Normalising user permissions',
        pmssBuildCommand('php', [dirname(__DIR__, 2).'/../util/userPermissions.php', $localUser])
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
}
