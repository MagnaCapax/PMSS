<?php
/**
 * Helpers for per-user maintenance during update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/user/context.php';
require_once __DIR__.'/user/http.php';
require_once __DIR__.'/user/skeleton.php';
require_once __DIR__.'/user/rutorrent.php';
require_once __DIR__.'/user/permissions.php';

/**
 * Keep ruTorrent plugin maintenance in the main user update module.
 */
function pmssUserEnsurePlugins(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    if (file_exists("{$home}/www/rutorrent/plugins/cpuload")) {
        runUserStep($user, 'Removing deprecated cpuload plugin', sprintf('rm -rf %s', escapeshellarg("{$home}/www/rutorrent/plugins/cpuload")));
    }

    $unpackPath = "{$home}/www/rutorrent/plugins/unpack";
    if (!file_exists($unpackPath)) {
        $source = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent/plugins/unpack';
        $unpackArg = escapeshellarg($unpackPath);
        runUserStep($user, 'Installing unpack plugin', sprintf('cp -Rp %s %s', escapeshellarg($source), $unpackArg));
        runUserStep($user, 'Adjusting unpack plugin ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, $unpackArg));
        runUserStep($user, 'Setting unpack plugin permissions', sprintf('chmod -R 755 %s', $unpackArg));
    }

    $userShareDir = "{$home}/www/rutorrent/share/users/{$user}";
    $retrackerConfigPath = "{$userShareDir}/settings";
    $retrackerFile = "{$retrackerConfigPath}/retrackers.dat";
    if (file_exists($retrackerFile)
        && in_array(sha1(trim((string) file_get_contents($retrackerFile))), ['9958caa274c2df67ea6702772821856365bc1201', 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a'], true)) {
        unlink($retrackerFile);
    }

    if (!file_exists($userShareDir.'/torrents') && file_exists($userShareDir)) {
        runUserStep($user, 'Creating ruTorrent torrents directory', sprintf('mkdir -p %s', escapeshellarg($userShareDir.'/torrents')));
        runUserStep($user, 'Adjusting retracker ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($retrackerConfigPath)));
    }

    $rssDir = "{$home}/www/rutorrent/share/settings/rss";
    if (!file_exists($rssDir)) {
        runUserStep($user, 'Creating ruTorrent RSS settings directory', sprintf('mkdir -p %s', escapeshellarg($rssDir)));
        runUserStep($user, 'Adjusting RSS settings ownership', sprintf('chown %1$s:%1$s %2$s', $userEsc, escapeshellarg($rssDir)));
        echo "\t*** Created RSS Settings folder\n";
    }
}

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

    $steps = [
        'HTTP services'       => 'pmssUserConfigureHttp',
        'Skeleton files'      => 'pmssUserApplySkeletonFiles',
        'ruTorrent themes'    => 'pmssUserUpdateThemes',
        'ruTorrent refresh'   => 'pmssUserUpgradeRutorrent',
        'ruTorrent PHP compatibility' => 'pmssUserMaintainRutorrentPhpCompatibility',
        'Plugin maintenance'  => 'pmssUserEnsurePlugins',
        'Permission refresh'  => 'pmssUserRefreshPermissions',
    ];

    foreach ($steps as $label => $handler) {
        if (!function_exists($handler)) {
            logmsg("[WARN] Missing handler {$handler} for {$label}");
            continue;
        }

        $handler($ctx);
    }

    // Keep linger/systemd/rootless Docker wiring inside the main user flow.
    if (function_exists('pmssEnsureLingerAndDocker')) {
        pmssEnsureLingerAndDocker($user);
    }
}
