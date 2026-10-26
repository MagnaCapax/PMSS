<?php
require_once __DIR__.'/qbittorrentWebUi.php';
require_once __DIR__.'/qbittorrentBuild.php';
/** Ask the owner for a WebUI password when the stored value needs replacing. */
function pmssQbittorrentFrontendPasswordRequired($configPath, $pathSafe = null, $build = null) {
    $pathSafe = $pathSafe ?: 'pmssCustomerPathIsSafe';
    if (!$pathSafe($configPath) || !is_file($configPath) || is_link($configPath)) return false;
    $config = @file_get_contents($configPath);
    // Mirror of pmssQbittorrentWebUiAuthSentinel(); customer PHP cannot require /scripts (ADR 0016).
    // QbittorrentWebUiAuthConfigTest keeps this literal identical to the writer's value.
    $sentinel = '@ByteArray(' . base64_encode(str_repeat("\0", 16)) . ':' . base64_encode(str_repeat("\0", 64)) . ')';
    $home = dirname(dirname(dirname($configPath)));
    $password = is_string($config) ? pmssQbittorrentFrontendWebUiValue($config, 'Password_PBKDF2', $build ?? pmssQbittorrentFrontendBuildVersion($home)) : null;
    return is_string($config) && ($password === null || trim($password, " \t\"") === $sentinel);
}
