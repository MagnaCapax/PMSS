<?php
/**
 * Helpers for per-user maintenance during update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime/commands.php';
pmssRequireRelativeFiles(__DIR__, [
    '../user/directories.php', '../user/delugeManagedConfig.php', '../user/log.php', '../user/qbittorrent.php',
    'users/context.php', 'users/http.php', 'users/filesystem.php', 'users/permissions.php', 'users/rutorrent.php',
]);

function pmssUserEnvironmentHandlers(): array { return ['pmssUserConfigureHttp', 'pmssUserApplySkeletonFiles', 'pmssUserUpdateThemes', 'pmssUserUpgradeRutorrent', 'pmssUserMaintainRutorrentPhpCompatibility', 'pmssUserEnsurePlugins', 'pmssUserRefreshPermissions']; }

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

    foreach (pmssUserEnvironmentHandlers() as $handler) {
        $handler($ctx);
    }
}
