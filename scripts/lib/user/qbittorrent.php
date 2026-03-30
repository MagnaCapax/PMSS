<?php
/**
 * qBittorrent configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/traffic.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

/**
 * Resolve the canonical qBittorrent config path for a user.
 */
function pmssQbittorrentConfigPath(string $username): string
{
    $homeRoot = getenv('PMSS_HOME_DIR') ?: '/home';

    return rtrim($homeRoot, '/').'/'.$username.'/.config/qBittorrent/qBittorrent.conf';
}

/**
 * Return the PMSS-managed qBittorrent keys and their enforced values.
 *
 * Keep this list intentionally small: only settings that materially affect
 * shared-host safety or the reverse-proxy contract belong here.
 *
 * @return array<int, array{section:string,key:string,value:string}>
 */
function pmssQbittorrentManagedConfigEntries(): array
{
    return [
        ['section' => 'BitTorrent', 'key' => 'Session\\AsyncIOThreadsCount', 'value' => '4'],
        ['section' => 'BitTorrent', 'key' => 'Session\\DiskCacheSize', 'value' => '128'],
        ['section' => 'BitTorrent', 'key' => 'Session\\DiskCacheTTL', 'value' => '120'],
        ['section' => 'BitTorrent', 'key' => 'Session\\DiskIOType', 'value' => 'Posix'],
        ['section' => 'BitTorrent', 'key' => 'Session\\MaxConnections', 'value' => '300'],
        ['section' => 'BitTorrent', 'key' => 'Session\\MaxConnectionsPerTorrent', 'value' => '75'],
        ['section' => 'BitTorrent', 'key' => 'Session\\Preallocation', 'value' => 'false'],
        ['section' => 'Preferences', 'key' => 'Bittorrent\\MaxConnecs', 'value' => '300'],
        ['section' => 'Preferences', 'key' => 'Bittorrent\\MaxConnecsPerTorrent', 'value' => '75'],
        ['section' => 'Preferences', 'key' => 'Downloads\\DiskWriteCacheSize', 'value' => '128'],
        ['section' => 'Preferences', 'key' => 'Downloads\\DiskWriteCacheTTL', 'value' => '120'],
        ['section' => 'Preferences', 'key' => 'Downloads\\PreAllocation', 'value' => 'false'],
        ['section' => 'Preferences', 'key' => 'WebUI\\CSRFProtection', 'value' => 'false'],
        ['section' => 'Preferences', 'key' => 'WebUI\\ClickjackingProtection', 'value' => 'false'],
        ['section' => 'Preferences', 'key' => 'WebUI\\HostHeaderValidation', 'value' => 'false'],
    ];
}

/**
 * Replace one managed qBittorrent key when it already exists in its section.
 */
function pmssQbittorrentConfigUpsert(string $config, string $section, string $key, string $value): ?string
{
    $lineEnding = strpos($config, "\r\n") !== false ? "\r\n" : "\n";
    $normalized = str_replace(["\r\n", "\r"], "\n", $config);
    $lines = preg_split("/\n/", $normalized);
    if (!is_array($lines)) {
        return null;
    }

    $hadTrailingNewline = substr($normalized, -1) === "\n";
    if ($hadTrailingNewline && end($lines) === '') {
        array_pop($lines);
    }

    $sectionHeader = '['.$section.']';
    $managedLine = $key.'='.$value;
    $sectionStart = null;
    $sectionEnd = count($lines);
    $found = false;

    foreach ($lines as $index => $line) {
        if ($line === $sectionHeader) {
            $sectionStart = $index;
            continue;
        }
        if ($sectionStart !== null && preg_match('/^\[[^\]]+\]$/', $line) === 1) {
            $sectionEnd = $index;
            break;
        }
    }

    if ($sectionStart === null) {
        return $config;
    }

    for ($index = $sectionStart + 1; $index < $sectionEnd; $index++) {
        if (strpos($lines[$index], $key.'=') !== 0) {
            continue;
        }

        $lines[$index] = $managedLine;
        $found = true;
        break;
    }

    if (!$found) {
        return $config;
    }

    $updated = implode("\n", $lines);
    if ($updated !== '' && $hadTrailingNewline) {
        $updated .= "\n";
    }

    return str_replace("\n", $lineEnding, $updated);
}

/**
 * Refresh the PMSS-managed subset of a user's qBittorrent config.
 */
function pmssQbittorrentApplyManagedConfig(string $username, ?string $configFile = null): bool
{
    if ($configFile === null) {
        $configFile = pmssQbittorrentConfigPath($username);
    }
    if (!is_file($configFile) || is_link($configFile)) {
        return false;
    }

    $config = file_get_contents($configFile);
    if (!is_string($config)) {
        return false;
    }

    $updatedConfig = $config;
    foreach (pmssQbittorrentManagedConfigEntries() as $entry) {
        $updated = pmssQbittorrentConfigUpsert($updatedConfig, $entry['section'], $entry['key'], $entry['value']);
        if (!is_string($updated)) {
            return false;
        }
        $updatedConfig = $updated;
    }

    if ($updatedConfig === $config) {
        return false;
    }

    $mode = fileperms($configFile);

    return pmssReplaceUserFileWithMetadata(
        $configFile,
        $updatedConfig,
        is_int($mode) ? ($mode & 0777) : 0600,
        $username,
        $username
    );
}

/**
 * Apply the upload throttle setting to an existing qBittorrent config.
 */
function pmssQbittorrentApplyUploadThrottle(string $username, ?int $throttle = null): bool
{
    $configFile = pmssQbittorrentConfigPath($username);
    if (!is_file($configFile) || is_link($configFile) || ($config = file_get_contents($configFile)) === false) {
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
