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

    $files = [
        '.rtorrentExecute.php',
        '.rtorrentRestart.php',
        '.bashrc',
        'install-media-stack.sh',
        'bin/docker-install-wireguard.sh',
        '.qbittorrentPort.py',
        '.delugePort.py',
        '.scriptsInc.php',
        '.lighttpd/php.ini',
        'radarr-sonarr.txt',
        'www/filemanager.php',
        'www/openvpn-config.tgz',
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

    $skelBase = pmssSkeletonBase();
    $quotaFiles = glob($skelBase.'/www/rutorrent/plugins/hddquota/*');
    if ($quotaFiles !== false) {
        foreach ($quotaFiles as $file) {
            $relative = strpos($file, $skelBase.'/') === 0 ? substr($file, strlen($skelBase) + 1) : str_replace('/etc/skel/', '', $file);
            updateUserFile($relative, $user);
        }
    }
}
