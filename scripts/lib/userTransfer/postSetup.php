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
require_once dirname(__DIR__).'/torrentClients/postSetup.php';
require_once dirname(__DIR__).'/user/trafficLimit.php';
require_once __DIR__.'/rtorrentRestart.php';
require_once __DIR__.'/qbittorrentRestart.php';

function pmssUserTransferPostSetup(array $cfg, string $home, array $scratchPaths): void
{
    // Recovery adds files under the live home; its existing setup must stay intact.
    if (isset($cfg['into'])) {
        return;
    }
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
    // The source's root user store is not carried over: the pull runs as the account's own login,
    // which cannot read it, and a value delivered from the account's files is never adopted
    // (ADR 0213). The target's store holds what the provisioning backend sends for the new account.

    runStep(
        'Normalising user permissions',
        pmssBuildCommand('php', [dirname(__DIR__, 2).'/util/userPermissions.php', $localUser])
    );
    pmssTorrentClientsPostSetupConverge($localUser, $home);
    // rsync -a can leave the imported share owned by the source UID. Repair it
    // before asking the local account to rename that directory.
    if ($remoteUser !== $localUser) {
        pmssUserTransferRenameRutorrentShare($home, $remoteUser, $localUser);
    }
    if ($remoteUser !== $localUser) {
        pmssUserTransferVerifyPayloadOwnership($localUser, $home);
    }
    pmssUserTransferRequestRtorrentRestart($home, $localUser);
    pmssUserTransferRequestQbittorrentRestart($home, $localUser);

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
    if (!pmssPathExistsOrLink($src)) return;
    if (!pmssPathTargetIsSafe($src, true, true) || !is_dir($src)
        || !pmssPathTargetIsSafe($dst, true, true) || pmssPathExistsOrLink($dst)
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
