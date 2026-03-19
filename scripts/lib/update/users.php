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
 * Apply skeleton file refreshes and tenant-side compatibility patches.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserApplySkeletonFiles(array $ctx): void
{
    $user = $ctx['user'];
    $patchTorrentFrontends = static function (string $path, string $requireLine, string $legacyCommand, string $patchedCommand): void {
        if (!is_file($path)
            || is_link($path)
            || !is_string($content = @file_get_contents($path))
            || $content === '') {
            return;
        }

        $updated = $content;
        if (strpos($updated, $requireLine) === false) {
            $updated = preg_replace('/^<\?php\s*/', $requireLine, $updated, 1, $count);
            if (!is_string($updated) || $count !== 1) {
                return;
            }
        }
        $updated = str_replace($legacyCommand, $patchedCommand, $updated);

        if ($updated !== $content) {
            @file_put_contents($path, $updated);
        }
    };

    $files = [
        '.rtorrentExecute.php',
        '.rtorrentRestart.php',
        '.bashrc',
        'install-media-stack.sh',
        'install-ai-tools.sh',
        'bin/docker-install-wireguard.sh',
        'bin/linuxserverInstall.sh',
        '.qbittorrentPort.py',
        '.delugePort.py',
        '.scriptsInc.php',
        '.lighttpd/php.ini',
        'radarr-sonarr.txt',
        'www/deluge.php',
        'www/filemanager.php',
        'www/openvpn-config.tgz',
        'www/qbittorrent.php',
        'www/rutorrent/js/content.js',
        'www/rutorrent/php/settings.php',
        'www/rutorrent/plugins/theme/conf.php',
    ];
    foreach ($files as $file) {
        updateUserFile($file, $user);
    }

    if (file_exists("/home/{$user}/www/phpXplorer")) {
        unlink("/home/{$user}/www/phpXplorer");
    }

    // Patch tenant copies until the frozen skeleton filemanager source can be
    // updated upstream without touching the locked tree.
    $filemanagerPath = $ctx['home'].'/www/filemanager.php';
    if (is_file($filemanagerPath)
        && !is_link($filemanagerPath)
        && is_string($content = @file_get_contents($filemanagerPath))
        && $content !== ''
        && strpos($content, '        @ob_flush();') === false) {
        if (($updated = str_replace('        ob_flush();', '        @ob_flush();', $content, $replacements)) !== $content && $replacements > 0) {
            @file_put_contents($filemanagerPath, $updated);
        }
    }

    // Keep tenant torrent frontend copies off the legacy Python port helpers
    // until the frozen /etc/skel/www sources can be updated directly.
    $patchTorrentFrontends($ctx['home'].'/www/deluge.php', "<?php\nrequire_once '/scripts/lib/user/torrentPort.php';\n", "shell_exec('nohup python3 /home/\$(whoami)/.delugePort.py; deluged -l /home/\$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/\$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');", "if (function_exists('pmssDelugePortEnsureCurrentUser')) {\n        pmssDelugePortEnsureCurrentUser();\n    }\n    shell_exec('nohup deluged -l /home/\$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/\$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &');");
    $patchTorrentFrontends($ctx['home'].'/www/qbittorrent.php', "<?php\nrequire_once '/scripts/lib/user/torrentPort.php';\n", "passthru('python3 /home/\$(whoami)/.qbittorrentPort.py; zsh -c \"qbittorrent-nox -d\" >> /dev/null 2>&1 &');", "if (function_exists('pmssQbittorrentPortEnsureCurrentUser')) {\n        pmssQbittorrentPortEnsureCurrentUser();\n    }\n    passthru('zsh -c \"qbittorrent-nox -d\" >> /dev/null 2>&1 &');");

    $skelBase = pmssSkeletonBase();
    foreach (glob($skelBase.'/www/rutorrent/plugins/hddquota/*') ?: [] as $file) {
        $relative = strpos($file, $skelBase.'/') === 0 ? substr($file, strlen($skelBase) + 1) : str_replace('/etc/skel/', '', $file);
        updateUserFile($relative, $user);
    }
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
 * Apply compatibility patches for legacy ruTorrent PHP files.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserMaintainRutorrentPhpCompatibility(array $ctx): void
{
    // Keep these compatibility shims local to the maintenance boundary so the
    // frozen ruTorrent tenant patches do not leak extra exported helpers.
    foreach ([
        [
            'path' => $ctx['home'].'/www/rutorrent/php/settings.php',
            'legacy' => '((integer)($tm["minutes"]/$interval))*$interval+$interval,',
            'patched' => '((integer)($tm["minutes"]/((int)$interval)))*((int)$interval)+((int)$interval),',
        ],
        [
            'path' => $ctx['home'].'/www/rutorrent/plugins/rss/action.php',
            'legacy' => 'ob_flush();',
            'patched' => '@ob_flush();',
        ],
    ] as $patch) {
        if (!is_file($patch['path'])
            || is_link($patch['path'])
            || !is_string($content = @file_get_contents($patch['path']))
            || $content === ''
            || strpos($content, $patch['patched']) !== false) {
            continue;
        }

        if (($updated = str_replace($patch['legacy'], $patch['patched'], $content, $replacements)) === $content || $replacements < 1) {
            continue;
        }

        @file_put_contents($patch['path'], $updated);
    }
}

/**
 * Refresh bundled ruTorrent theme assets from the skeleton tree.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserUpdateThemes(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];

    $themesPath = "{$home}/www/rutorrent/plugins/theme/themes/";
    $skelThemesPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent/plugins/theme/themes/';
    foreach (['Agent34','Agent46','OblivionBlue','FlatUI_Dark','FlatUI_Light','FlatUI_Material','MaterialDesign','club-QuickBox'] as $theme) {
        if (file_exists($themesPath.$theme)) {
            continue;
        }

        runUserStep(
            $user,
            "Installing ruTorrent theme {$theme}",
            sprintf('cp -r %s %s',
                escapeshellarg($skelThemesPath.$theme),
                escapeshellarg($themesPath)
            )
        );
        runUserStep($user, "Adjusting theme {$theme} ownership", sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($themesPath.$theme)));
    }
}

/**
 * Replace stale per-user ruTorrent trees with the current skeleton copy.
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserUpgradeRutorrent(array $ctx): void
{
    $user          = $ctx['user'];
    $home          = $ctx['home'];
    $userEsc       = $ctx['user_esc'];
    $expectedSha   = $ctx['rutorrent_index_sha'];
    $rutorrentPath = "{$home}/www/rutorrent";
    $legacyPath    = "{$home}/www/oldRutorrent-3";

    if ($expectedSha === ''
        || !file_exists($rutorrentPath.'/index.html')
        || file_exists($legacyPath)
        || $expectedSha === sha1(file_get_contents($rutorrentPath.'/index.html'))) {
        return;
    }

    echo "****** Updating ruTorrent\n";
    echo "******* Backing up old as 'oldRutorrent-3'\n";
    runUserStep($user, 'Backing up existing ruTorrent', sprintf('mv %s %s', escapeshellarg($rutorrentPath), escapeshellarg($legacyPath)));
    echo "******* Copying new ruTorrent from skel\n";
    runUserStep(
        $user,
        'Copying new ruTorrent from skel',
        sprintf('cp -Rp %s %s',
            escapeshellarg(pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/www/rutorrent'),
            escapeshellarg("{$home}/www/")
        )
    );
    echo "******* Configuring\n";
    runUserStep(
        $user,
        'Restoring ruTorrent config.php',
        sprintf('cp -p %s %s', escapeshellarg($legacyPath.'/conf/config.php'), escapeshellarg($rutorrentPath.'/conf/'))
    );
    runUserStep(
        $user,
        'Restoring ruTorrent share directory',
        sprintf('bash -lc %s',
            escapeshellarg("cp -rp {$legacyPath}/share/* {$rutorrentPath}/share/")
        )
    );
    updateRutorrentConfig($user, 1);
    runUserStep($user, 'Setting ruTorrent ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($rutorrentPath)));
    runUserStep($user, 'Setting ruTorrent permissions', sprintf('chmod -R 751 %s', escapeshellarg($rutorrentPath)));
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
