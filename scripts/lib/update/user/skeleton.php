<?php
/**
 * Skeleton file maintenance for user accounts.
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

    $quotaFiles = glob(pmssSkeletonBase().'/www/rutorrent/plugins/hddquota/*');
    if ($quotaFiles !== false) {
        foreach ($quotaFiles as $file) {
            $relative = str_replace('/etc/skel/', '', $file);
            updateUserFile($relative, $user);
        }
    }
}
