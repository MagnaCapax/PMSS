<?php
/**
 * Helpers for per-user maintenance during update-step2.
 */

require_once __DIR__.'/user/utils.php';
require_once __DIR__.'/user/context.php';
require_once __DIR__.'/user/http.php';
require_once __DIR__.'/user/skeleton.php';
require_once __DIR__.'/user/rutorrent.php';
require_once __DIR__.'/user/plugins.php';
require_once __DIR__.'/user/permissions.php';

/**
 * Refresh a single user's environment (HTTP, skeleton, ruTorrent, plugins, permissions).
 *
 * The second parameter carries the current ruTorrent index checksum so helpers
 * can detect when per-user assets are stale. It intentionally stays a simple
 * scalar instead of a generic "options" bag to keep the call site readable.
 */
function pmssUpdateUserEnvironment(string $user, string $rutorrentIndexSha = ''): void
{
    $ctx = pmssBuildUserContext($user, $rutorrentIndexSha);
    if ($ctx === null) {
        return;
    }

    echo "***** Updating user {$user}\n";
    logmsg("Updating user {$user}");

    $steps = [
        'HTTP services'       => 'pmssUserConfigureHttp',
        'Skeleton files'      => 'pmssUserApplySkeletonFiles',
        'ruTorrent themes'    => 'pmssUserUpdateThemes',
        'ruTorrent refresh'   => 'pmssUserUpgradeRutorrent',
        'Plugin maintenance'  => 'pmssUserEnsurePlugins',
        'Retracker cleanup'   => 'pmssUserMaintainRetracker',
        'Permission refresh'  => 'pmssUserRefreshPermissions',
    ];

    foreach ($steps as $label => $handler) {
        if (function_exists($handler)) {
            $handler($ctx);
        } else {
            logmsg("[WARN] Missing handler {$handler} for {$label}");
        }
    }
}
