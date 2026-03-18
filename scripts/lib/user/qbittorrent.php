<?php
/**
 * qBittorrent configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/traffic.php';

/**
 * Apply the upload throttle setting to an existing qBittorrent config.
 */
function pmssQbittorrentApplyUploadThrottle(string $username, ?int $throttle = null): bool
{
    $configFile = sprintf('/home/%s/.config/qBittorrent/qBittorrent.conf', $username);
    if (!is_file($configFile) || is_link($configFile)) {
        return false;
    }

    $config = file_get_contents($configFile);
    if ($config === false) {
        return false;
    }

    if ($throttle === null) {
        $throttle = pmssReadTorrentThrottle($username);
    }
    $hasThrottle = ($throttle !== null && $throttle > 0);
    $replacement = 'Connection\\GlobalUPLimit='.(int) $throttle;

    if (preg_match('/^Connection\\\\GlobalUPLimit=.*/m', $config)) {
        if ($hasThrottle) {
            $newConfig = preg_replace('/^Connection\\\\GlobalUPLimit=.*/m', $replacement, $config, 1);
        } else {
            $newConfig = preg_replace('/^Connection\\\\GlobalUPLimit=.*\\n?/m', '', $config, 1);
        }
    } elseif ($hasThrottle) {
        $newConfig = preg_replace('/(\\[Preferences\\][^\\[]*)/s', '$1'.$replacement."\n", $config, 1);
    } else {
        return false;
    }

    return $newConfig !== null
        && $newConfig !== $config
        && file_put_contents($configFile, $newConfig) !== false;
}
