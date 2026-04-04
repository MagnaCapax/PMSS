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
 * Replace or restore one managed qBittorrent key inside the target section.
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

    if ($sectionStart !== null) {
        for ($index = $sectionStart + 1; $index < $sectionEnd; $index++) {
            if (strpos($lines[$index], $key.'=') !== 0) {
                continue;
            }

            $lines[$index] = $managedLine;
            $found = true;
            break;
        }
    }

    if ($found) {
        $updated = implode("\n", $lines);
        if ($updated !== '' && $hadTrailingNewline) {
            $updated .= "\n";
        }

        return str_replace("\n", $lineEnding, $updated);
    }

    if ($sectionStart === null) {
        if (!empty($lines) && end($lines) !== '') {
            $lines[] = '';
        }
        $lines[] = $sectionHeader;
        $lines[] = $managedLine;
    } else {
        for ($index = $sectionStart + 1; $index < $sectionEnd; $index++) {
            if ($lines[$index] === $managedLine) {
                $updated = implode("\n", $lines);
                if ($updated !== '' && $hadTrailingNewline) {
                    $updated .= "\n";
                }

                return str_replace("\n", $lineEnding, $updated);
            }
        }

        while ($sectionEnd > ($sectionStart + 1) && $lines[$sectionEnd - 1] === '') {
            $sectionEnd--;
        }

        // Insert before the next section header so user-owned settings keep their section.
        array_splice($lines, $sectionEnd, 0, [$managedLine]);
    }

    $updated = implode("\n", $lines);
    if ($updated !== '' && $hadTrailingNewline) {
        $updated .= "\n";
    }

    return str_replace("\n", $lineEnding, $updated);
}

/**
 * Load, transform, and atomically rewrite a user's qBittorrent config.
 */
function pmssQbittorrentConfigMutate(string $username, callable $mutator, ?string $configFile = null): bool
{
    if ($configFile === null) {
        $configFile = pmssQbittorrentConfigPath($username);
    }
    if (!is_file($configFile) || is_link($configFile)) {
        return false;
    }

    $config = @file_get_contents($configFile);
    if (!is_string($config)) {
        return false;
    }

    $updatedConfig = $mutator($config);
    if (!is_string($updatedConfig) || $updatedConfig === $config) {
        return false;
    }

    $mode = @fileperms($configFile);

    return pmssReplaceUserFileWithMetadata(
        $configFile,
        $updatedConfig,
        is_int($mode) ? ($mode & 0777) : 0600,
        $username,
        $username
    );
}

/**
 * Generate the qBittorrent PBKDF2 password hash format.
 */
function pmssQbittorrentPasswordHashGenerate(string $password): string
{
    $salt = random_bytes(16);
    $hash = hash_pbkdf2('sha512', $password, $salt, 100000, 64, true);

    return '@ByteArray(' . base64_encode($salt) . ':' . base64_encode($hash) . ')';
}

/**
 * Refresh the PMSS-managed subset of a user's qBittorrent config.
 */
function pmssQbittorrentApplyManagedConfig(string $username, ?string $configFile = null): bool
{
    return pmssQbittorrentConfigMutate(
        $username,
        static function (string $config): ?string {
            $updatedConfig = $config;
            foreach (pmssQbittorrentManagedConfigEntries() as $entry) {
                $updatedConfig = pmssQbittorrentConfigUpsert($updatedConfig, $entry['section'], $entry['key'], $entry['value']);
                if (!is_string($updatedConfig)) {
                    return null;
                }
            }

            return $updatedConfig;
        },
        $configFile
    );
}

/**
 * Apply the upload throttle setting to an existing qBittorrent config.
 */
function pmssQbittorrentApplyUploadThrottle(string $username, ?int $throttle = null): bool
{
    $throttle = $throttle ?? pmssReadTorrentThrottle($username);
    return pmssQbittorrentConfigMutate(
        $username,
        static function (string $config) use ($throttle): ?string {
            if ($throttle !== null && $throttle > 0) {
                return pmssQbittorrentConfigUpsert(
                    $config,
                    'Preferences',
                    'Connection\\GlobalUPLimit',
                    (string) (int) $throttle
                );
            }

            $updated = preg_replace('/^Connection\\\\GlobalUPLimit=.*\r?\n?/m', '', $config, 1);
            return is_string($updated) ? $updated : null;
        }
    );
}

/**
 * Update the qBittorrent WebUI password hash inside the config file.
 */
function pmssQbittorrentApplyPassword(string $username, string $password, ?string $configFile = null): bool
{
    return pmssQbittorrentConfigMutate(
        $username,
        static function (string $config) use ($password): ?string {
            return pmssQbittorrentConfigUpsert(
                $config,
                'Preferences',
                'WebUI\\Password_PBKDF2',
                pmssQbittorrentPasswordHashGenerate($password)
            );
        },
        $configFile
    );
}
