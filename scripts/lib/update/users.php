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
require_once __DIR__.'/user/skeleton.php';
require_once __DIR__.'/user/rutorrent.php';

/**
 * Build the shared per-user context array used by update-step2 user helpers.
 *
 * Returns null when:
 * - The user home is missing
 * - Core rtorrent state is missing (not a PMSS tenant)
 * - The user appears suspended (canonical marker: `www-disabled` directory)
 *
 * @param string $user             Username (validated by callers).
 * @param string $rutorrentIndexSha Current ruTorrent index.html checksum.
 */
function pmssBuildUserContext(string $user, string $rutorrentIndexSha = ''): ?array
{
    // Allow tests and development tooling to override the home root while
    // keeping the default `/home` behaviour for production.
    $home = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')."/{$user}";
    // The shared context only exists for active PMSS tenants; suspended users
    // are intentionally skipped to avoid recreating web roots or restarting
    // services mid-suspension.
    if (!is_dir($home)
        || !file_exists($home.'/.rtorrent.rc')
        || !file_exists($home.'/data')
        || is_dir("{$home}/www-disabled")) {
        return null;
    }

    // rutorrent_index_sha tracks the current skeleton ruTorrent index.html
    // hash so callers can detect when per-user instances are out of date.
    return [
        'user'               => $user,
        'home'               => $home,
        'user_esc'           => escapeshellarg($user),
        'rutorrent_index_sha'=> $rutorrentIndexSha,
    ];
}

/**
 * Configure per-user HTTP stack pieces (lighttpd vhost, ruTorrent temp paths).
 *
 * Suspension handling: callers must only invoke this when the user context is
 * non-null (pmssBuildUserContext filters out suspended users via `www-disabled`).
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserConfigureHttp(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];
    $userLog = function_exists('pmssUserLog') ? static function (string $message) use ($user): void { pmssUserLog($user, $message); } : null;

    runUserStep($user, 'Configuring lighttpd vhost', sprintf('/scripts/util/userConfigLighttpd.php %s', $userEsc));

    // Keep qBittorrent WebUI reverse-proxy compatibility settings disabled.
    $qbittorrentConfig = "{$home}/.config/qBittorrent/qBittorrent.conf";
    if (is_file($qbittorrentConfig) && is_string($config = file_get_contents($qbittorrentConfig))) {
        $originalConfig = $config;
        foreach (['HostHeaderValidation', 'CSRFProtection', 'ClickjackingProtection'] as $setting) {
            $updated = preg_replace('/^WebUI\\\\'.preg_quote($setting, '/').'=.*$/m', 'WebUI\\'.$setting.'=false', $config, 1, $count);
            if ($count < 1 || $updated === null || $updated === $config) {
                continue;
            }

            $config = $updated;
            if ($userLog) {
                $userLog('Updated qBittorrent WebUI '.$setting.' to false');
            }
        }

        if ($config !== $originalConfig) {
            file_put_contents($qbittorrentConfig, $config);
        }
    }

    $phpIniPath = "{$home}/.lighttpd/php.ini";
    if (($phpIni = @parse_ini_file($phpIniPath)) !== false && !isset($phpIni['error_log'])) {
        $phpIni['error_log'] = "{$home}/.lighttpd/error.log";
        $newContent = '';
        foreach ($phpIni as $key => $value) {
            $newContent .= sprintf('%s = "%s"\n', $key, $value);
        }
        file_put_contents($phpIniPath, $newContent);
        echo "Updated php.ini for user {$user}\n";
    }

    if (!is_dir("{$home}/.tmp")) {
        pmssEnsureUserHomeDir($user, $home, '.tmp', 0755, $userLog);
    }

    $irssiDir = "{$home}/.irssi";
    if (!is_dir($irssiDir)) {
        pmssEnsureUserHomeDir($user, $home, '.irssi', 0755, $userLog);
        $skelConfigPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/.irssi/config';
        runUserStep($user, 'Copying irssi skeleton config', sprintf('cp %s %s/', $skelConfigPath === '/etc/skel/.irssi/config' ? $skelConfigPath : escapeshellarg($skelConfigPath), escapeshellarg($irssiDir)));
        runUserStep($user, 'Adjusting irssi configuration ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($irssiDir)));
    }

    if (!is_dir("{$home}/www/recycle")) {
        // Note: parent directory mode should remain legacy defaults; only the recycle dir is 0771.
        pmssEnsureUserHomeDir($user, $home, 'www/recycle', 0771, $userLog, 0755);
    }
}

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
 * Permission refresh routines for user environments.
 */
function pmssUserRefreshPermissions(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];

    $timeoutRaw = (string) getenv('PMSS_USER_PERMISSIONS_TIMEOUT');
    $timeoutSeconds = (ctype_digit($timeoutRaw) && (int) $timeoutRaw > 0) ? (int) $timeoutRaw : 900;
    $previousTimeout = getenv('PMSS_COMMAND_TIMEOUT');
    $permissionsCommand = pmssBuildCommand('/scripts/util/userPermissions.php', [$user]);
    foreach (['/usr/bin/ionice', '/bin/ionice'] as $ionicePath) {
        if (is_executable($ionicePath)) {
            $permissionsCommand = pmssBuildCommand($ionicePath, ['-c3', '/scripts/util/userPermissions.php', $user]);
            break;
        }
    }

    putenv('PMSS_COMMAND_TIMEOUT='.(string) $timeoutSeconds);
    try {
        $rc = runUserStep($user, 'Refreshing user permissions', $permissionsCommand);
    } finally {
        putenv($previousTimeout === false ? 'PMSS_COMMAND_TIMEOUT' : 'PMSS_COMMAND_TIMEOUT='.$previousTimeout);
    }

    if ($rc === 124) {
        if (function_exists('pmssUserLog')) {
            pmssUserLog($user, sprintf('[WARN] userPermissions timed out after %ds', $timeoutSeconds));
        }
        throw new \RuntimeException(sprintf('userPermissions timeout after %ds', $timeoutSeconds));
    }

    $rcCustomPath = "{$home}/.rtorrent.rc.custom";
    if (file_exists($rcCustomPath)
        && in_array(sha1((string) file_get_contents($rcCustomPath)), ['dcf21704d49910d1670b3fdd04b37e640b755889', 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a'], true)) {
        $skelRcCustomPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/.rtorrent.rc.custom';
        runUserStep(
            $user,
            'Updating .rtorrent.rc.custom from skeleton',
            sprintf('cp %s %s/', $skelRcCustomPath === '/etc/skel/.rtorrent.rc.custom' ? $skelRcCustomPath : escapeshellarg($skelRcCustomPath), escapeshellarg($home))
        );
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
