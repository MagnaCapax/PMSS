<?php
/** Customer-only WebUI password collection and stopped config writes. */
require_once __DIR__.'/qbittorrentPasswordRequired.php';
require_once __DIR__.'/configureTorrentClients.php';

function pmssQbittorrentFrontendPasswordApplyStopped($configPath, $password) {
    // This nightly helper can arrive beside old heal files; do not assume their functions loaded.
    if (!function_exists('pmssQbittorrentFrontendPasswordHash')) return false;
    if ($password === '' || strpos($password, "\0") !== false || !pmssCustomerPathIsSafe($configPath) || !is_file($configPath) || is_link($configPath)) return false;
    $home = dirname(dirname(dirname($configPath)));
    return pmssQbittorrentFrontendConfigLocked($home, static function () use ($home, $configPath, $password): bool {
        // A clean shutdown saves the whole file; promote any pending save before the password write.
        if (!pmssConfigureTorrentClientStop($home) || !pmssQbittorrentFrontendRestore($home)) return false;
        $config = @file_get_contents($configPath);
        if (!is_string($config)) return false;
        $updated = pmssQbittorrentFrontendConfigUpsert($config, 'Preferences', 'WebUI\\Password_PBKDF2', pmssQbittorrentFrontendPasswordHash($password));
        return $updated === $config || pmssConfigureFileWrite($configPath, $updated);
    });
}
