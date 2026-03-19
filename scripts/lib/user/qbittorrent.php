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

    $throttle = $throttle ?? pmssReadTorrentThrottle($username);
    $hasThrottle = ($throttle !== null && $throttle > 0);
    $replacement = 'Connection\\GlobalUPLimit='.(int) $throttle;

    if (preg_match('/^Connection\\\\GlobalUPLimit=.*/m', $config)) {
        $newConfig = preg_replace(
            $hasThrottle ? '/^Connection\\\\GlobalUPLimit=.*/m' : '/^Connection\\\\GlobalUPLimit=.*\\n?/m',
            $hasThrottle ? $replacement : '',
            $config,
            1
        );
    } elseif (!$hasThrottle) {
        return false;
    } else {
        $newConfig = preg_replace('/(\\[Preferences\\][^\\[]*)/s', '$1'.$replacement."\n", $config, 1);
    }

    return $newConfig !== null
        && $newConfig !== $config
        && file_put_contents($configFile, $newConfig) !== false;
}
