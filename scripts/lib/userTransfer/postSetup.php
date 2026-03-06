<?php
/**
 * Post-transfer fixups for user transfers.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/update/runtime/commands.php';
require_once __DIR__.'/localUserSafety.php';
require_once __DIR__.'/sessionRewrite.php';

/**
 * Apply post-transfer steps (rename ruTorrent user dir, normalise permissions, restart marker).
 */
function pmssUserTransferPostSetup(array $cfg, string $home): void
{
    $localUser = $cfg['localUser'];
    $remoteUser = $cfg['remoteUser'];

    // Rename ruTorrent user directory when remote/local user differs.
    if ($remoteUser !== $localUser) {
        $src = $home.'/www/rutorrent/share/users/'.$remoteUser;
        $dst = $home.'/www/rutorrent/share/users/'.$localUser;
        if (file_exists($src) && !file_exists($dst)) {
            $srcSafe = pmssUserTransferIsPathWithinHome($src, $home);
            $dstParentSafe = pmssUserTransferIsPathWithinHome(dirname($dst), $home);
            if ($srcSafe && $dstParentSafe) {
                if (!@rename($src, $dst)) {
                    runStep(
                        'Renaming ruTorrent user directory',
                        pmssBuildCommand('mv', [$src, $dst])
                    );
                } else {
                    logMessage('[OK] Renamed ruTorrent user directory');
                }
            } else {
                logMessage('[WARN] Skipping ruTorrent rename (path escapes home)');
            }
        }
    }

    // Keep migrated rTorrent sessions usable when usernames differ between
    // source and destination accounts.
    pmssUserTransferRewriteRtorrentSessionPaths($cfg, $home);

    // Normalise ownership/permissions via the shared helper, which avoids unsafe
    // recursive chown dereferencing symlinks into the host filesystem.
    runStep(
        'Normalising user permissions',
        pmssBuildCommand('php', [dirname(__DIR__).'/../util/userPermissions.php', $localUser])
    );

    // Request rTorrent restart (best effort) so migrated data is picked up.
    $wwwDir = $home.'/www';
    if (is_dir($wwwDir) && !is_link($wwwDir) && pmssUserTransferIsPathWithinHome($wwwDir, $home)) {
        $marker = $wwwDir.'/.rtorrentRestart';
        runStep('Requesting rTorrent restart marker', pmssBuildCommand('touch', [$marker]));
        runStep('Setting rTorrent restart marker owner', pmssBuildCommand('chown', [$localUser.':'.$localUser, $marker]));
    } else {
        logMessage('[WARN] Skipping rTorrent restart marker (www dir missing or unsafe)');
    }
}
