<?php
/**
 * Helpers for per-user maintenance during update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/../user/directories.php';
require_once __DIR__.'/users/context.php';
require_once __DIR__.'/users/http.php';
require_once __DIR__.'/users/filesystem.php';
require_once __DIR__.'/users/permissions.php';
require_once __DIR__.'/users/rutorrent.php';

/**
 * Refresh a single user's environment (HTTP, skeleton, ruTorrent, plugins, permissions).
 *
 * The second parameter carries the current ruTorrent index checksum so helpers
 * can detect when per-user assets are stale. Keep this function narrow—if more
 * inputs are ever needed, refactor the per-user flow instead of growing the
 * signature or adding generic option bags.
 */
function pmssUpdateUserEnvironment(string $user, string $rutorrentIndexSha = ''): void
{
    $ctx = pmssBuildUserContext($user, $rutorrentIndexSha);
    if ($ctx === null) {
        return;
    }

    echo "***** Updating user {$user}\n";
    logmsg("Updating user {$user}");

    foreach ([
        'pmssUserConfigureHttp',
        'pmssUserApplySkeletonFiles',
        'pmssUserUpdateThemes',
        'pmssUserUpgradeRutorrent',
        'pmssUserMaintainRutorrentPhpCompatibility',
        'pmssUserEnsurePlugins',
        'pmssUserRefreshPermissions',
    ] as $handler) {
        $handler($ctx);
    }

    // Keep linger/systemd/rootless Docker wiring inside the main user flow.
    if (function_exists('pmssEnsureLingerAndDocker')) {
        pmssEnsureLingerAndDocker($user);
    }
}
