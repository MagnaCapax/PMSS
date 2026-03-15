<?php
/**
 * Skeleton file maintenance for user accounts.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Ensure PHP 8.2+ does not emit notice text into filemanager download streams.
 *
 * We cannot edit the frozen upstream `etc/skel/www/filemanager.php` directly,
 * so update runs patch tenant copies in-place until the upstream file is
 * intentionally updated.
 */
function pmssUserFilemanagerObFlushPatchApply(string $filePath): bool
{
    if (!is_file($filePath) || is_link($filePath)) {
        return false;
    }

    $content = @file_get_contents($filePath);
    if (!is_string($content) || $content === '') {
        return false;
    }

    if (strpos($content, '@ob_flush();') !== false) {
        return true;
    }

    $updated = str_replace('        ob_flush();', '        @ob_flush();', $content, $replacements);
    if ($replacements < 1 || $updated === $content) {
        return false;
    }

    return @file_put_contents($filePath, $updated) !== false;
}

function pmssUserApplySkeletonFiles(array $ctx): void
{
    $user = $ctx['user'];

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

    pmssUserFilemanagerObFlushPatchApply($ctx['home'].'/www/filemanager.php');

    $skelBase = pmssSkeletonBase();
    $quotaFiles = glob($skelBase.'/www/rutorrent/plugins/hddquota/*');
    if ($quotaFiles !== false) {
        foreach ($quotaFiles as $file) {
            $relative = strpos($file, $skelBase.'/') === 0 ? substr($file, strlen($skelBase) + 1) : str_replace('/etc/skel/', '', $file);
            updateUserFile($relative, $user);
        }
    }
}
