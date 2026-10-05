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
require_once __DIR__.'/../lighttpd/userFileWrite.php';

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
    $configRoot = pmssRutorrentConfigResolveBasePath('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $homeRoot = pmssRutorrentConfigResolveBasePath('PMSS_HOME_DIR', '/home');
    $templateConfigPath = $configRoot.'/template.rutorrent.config';
    $templateAccessPath = $configRoot.'/template.rutorrent.access';

    $rutorrentConfig = file_get_contents($templateConfigPath);
    $accessIni = file_get_contents($templateAccessPath);
    if ($rutorrentConfig === false || $accessIni === false) {
        echo "Failed to read ruTorrent template files.\n";
        return;
    }

    $homeDir = ($homeRoot === '/' ? '' : $homeRoot)."/{$username}";
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
    if (!pmssWriteUserFile($configPath, $rutorrentConfig, $username, 0750)) {
        echo "Failed to write ruTorrent config to {$configPath}\n";
        return;
    }
    if (!pmssWriteUserFile($accessPath, $accessIni, $username, 0750)) {
        echo "Failed to write ruTorrent access config to {$accessPath}\n";
        return;
    }
}

/**
 * Resolve a configurable base path while preserving the production default.
 *
 * @param string $envName Environment variable to inspect.
 * @param string $default Production path used when the variable is empty.
 *
 * @return string
 */
function pmssRutorrentConfigResolveBasePath($envName, $default)
{
    $value = getenv($envName);
    if (!is_string($value) || trim($value) === '') {
        return $default;
    }

    $path = rtrim($value, '/');
    return $path === '' ? '/' : $path;
}
