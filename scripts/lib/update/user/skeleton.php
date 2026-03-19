<?php
/**
 * Skeleton file maintenance for user accounts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
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
