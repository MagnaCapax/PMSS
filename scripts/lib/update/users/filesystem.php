<?php
/**
 * @domain user home maintenance — skeleton, files, permissions
 *
 * User home maintenance helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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
    $ionicePath = is_executable('/usr/bin/ionice') ? '/usr/bin/ionice' : (is_executable('/bin/ionice') ? '/bin/ionice' : '');
    if ($ionicePath !== '') {
        $permissionsCommand = pmssBuildCommand($ionicePath, ['-c3', '/scripts/util/userPermissions.php', $user]);
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
