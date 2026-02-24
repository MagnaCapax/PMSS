<?php
/**
 * qBittorrent configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
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

function userConfigureQbittorrent(array $user): void
{
    $configDir  = sprintf('/home/%s/.config/qBittorrent', $user['name']);
    $configFile = $configDir.'/qBittorrent.conf';
    $throttle = pmssReadTorrentThrottle($user['name']);
    $throttleLine = ($throttle !== null && $throttle > 0)
        ? 'Connection\\GlobalUPLimit='.(int) $throttle
        : '';

    if (!file_exists($configFile)) {
        $template = file_get_contents('/etc/seedbox/config/template.qbittorrent.conf');
        $port = (int) round(rand(1500, 65500));
        if (!file_exists($configDir)) {
            mkdir($configDir, 0770, true);
        }
        $config = str_replace(
            ['##username', '##port', '##uploadThrottleLine'],
            [$user['name'], $port, $throttleLine],
            $template
        );
        file_put_contents($configFile, $config);
        file_put_contents(sprintf('/home/%s/.qbittorrentPort', $user['name']), $port);
    }

    pmssQbittorrentApplyUploadThrottle($user['name'], $throttle);
}
