<?php
/**
 * ruTorrent configuration rendering helpers.
 *
 * Keeps ruTorrent template writes in a narrow library so callers do not need
 * the wider updater bootstrap just to rewrite per-user config files.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Update ruTorrent configuration for a given user.
 *
 * This function reads ruTorrent configuration template files, replaces the
 * user-specific placeholders, and writes the resulting config into the user's
 * ruTorrent tree.
 *
 * @param string $username Username whose ruTorrent config should be written.
 * @param int    $scgiPort Legacy signature placeholder kept for compatibility.
 *
 * @return void
 */
function updateRutorrentConfig($username, $scgiPort)
{
    $templateConfigPath = '/etc/seedbox/config/template.rutorrent.config';
    $templateAccessPath = '/etc/seedbox/config/template.rutorrent.access';

    $rutorrentConfig = file_get_contents($templateConfigPath);
    $accessIni = file_get_contents($templateAccessPath);
    if ($rutorrentConfig === false || $accessIni === false) {
        echo "Failed to read ruTorrent template files.\n";
        return;
    }

    $homeDir = "/home/{$username}";
    $rutorrentDir = $homeDir.'/www/rutorrent';
    foreach ([
        '$scgi_host = "";' => '$scgi_host = "unix://'.$homeDir.'/.rtorrent.socket";',
        '$tempDirectory = null;' => "\$tempDirectory = '{$homeDir}/.tmp/';",
        '$topDirectory = \'/\';' => "\$topDirectory = '{$homeDir}/';",
        '$log_file = \'/tmp/errors.log\';' => "\$log_file = '{$rutorrentDir}/errors.log';",
    ] as $search => $replace) {
        $rutorrentConfig = str_replace($search, $replace, $rutorrentConfig);
    }

    $configPath = $rutorrentDir.'/conf/config.php';
    $accessPath = $rutorrentDir.'/conf/access.ini';
    if (file_put_contents($configPath, $rutorrentConfig) === false) {
        echo "Failed to write ruTorrent config to {$configPath}\n";
        return;
    }
    if (file_put_contents($accessPath, $accessIni) === false) {
        echo "Failed to write ruTorrent access config to {$accessPath}\n";
        return;
    }
}
