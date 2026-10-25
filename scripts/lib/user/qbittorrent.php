<?php
/**
 * qBittorrent configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/traffic.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/qbittorrentVersion.php';
require_once __DIR__.'/credentialProcess.php';

/**
 * Resolve the canonical qBittorrent config path for a user.
 */
function pmssQbittorrentConfigPath(string $username): string
{
    return pmssUserHomeFilePath($username, '.config/qBittorrent/qBittorrent.conf');
}

/**
 * Return the PMSS-managed qBittorrent keys and their enforced values.
 *
 * Keep this list intentionally small: only settings that materially affect
 * shared-host safety or the reverse-proxy contract belong here.
 *
 * @return array<int, array{section:string,key:string,value:string}>
 */
function pmssQbittorrentManagedConfigEntries(string $compatFamily = ''): array
{
    $entries = [
        ['section' => 'BitTorrent', 'key' => 'Session\\AsyncIOThreadsCount', 'value' => '4'],
        ['section' => 'BitTorrent', 'key' => 'Session\\DiskCacheSize', 'value' => '128'],
        ['section' => 'BitTorrent', 'key' => 'Session\\DiskCacheTTL', 'value' => '120'],
        ['section' => 'BitTorrent', 'key' => 'Session\\MaxConnections', 'value' => '300'],
        ['section' => 'BitTorrent', 'key' => 'Session\\MaxConnectionsPerTorrent', 'value' => '75'],
        ['section' => 'BitTorrent', 'key' => 'Session\\Preallocation', 'value' => 'true'],
        ['section' => 'Preferences', 'key' => 'Bittorrent\\MaxConnecs', 'value' => '300'],
        ['section' => 'Preferences', 'key' => 'Bittorrent\\MaxConnecsPerTorrent', 'value' => '75'],
        ['section' => 'Preferences', 'key' => 'Downloads\\DiskWriteCacheSize', 'value' => '128'],
        ['section' => 'Preferences', 'key' => 'Downloads\\DiskWriteCacheTTL', 'value' => '120'],
        ['section' => 'Preferences', 'key' => 'Downloads\\PreAllocation', 'value' => 'true'],
        ['section' => 'Preferences', 'key' => 'WebUI\\CSRFProtection', 'value' => 'true'],
        ['section' => 'Preferences', 'key' => 'WebUI\\ClickjackingProtection', 'value' => 'true'],
        // Keep off until per-user WebUI\ServerDomains contains customer WebUI hostnames and a live test host verifies WebUI load; blind enabling risks fleet-wide 403s.
        ['section' => 'Preferences', 'key' => 'WebUI\\HostHeaderValidation', 'value' => 'false'],
    ];
    $diskIoType = pmssQbittorrentDiskIoType($compatFamily);
    if ($diskIoType !== null) {
        $entries[] = ['section' => 'BitTorrent', 'key' => 'Session\\DiskIOType', 'value' => $diskIoType];
    }
    // Reconcile these with the running daemon only on hosts opted into the rollout.
    if (is_file('/etc/seedbox/config/qbittorrentWebuiEnforce.enabled')) {
        $entries[] = ['section' => 'Preferences', 'key' => 'WebUI\\LocalHostAuth', 'value' => 'true'];
        $entries[] = ['section' => 'Preferences', 'key' => 'WebUI\\AuthSubnetWhitelistEnabled', 'value' => 'false'];
    }
    return $entries;
}

/** Split config lines while retaining their original newline convention. */
function pmssQbittorrentConfigSplitLines(string $config): array
{
    $lineEnding = strpos($config, "\r\n") !== false ? "\r\n" : "\n";
    $normalized = str_replace(["\r\n", "\r"], "\n", $config);
    $lines = $normalized === '' ? [] : explode("\n", $normalized);
    $trailing = substr($normalized, -1) === "\n";
    if ($trailing) array_pop($lines);
    return [$lines, $trailing, $lineEnding];
}

/** Render normalized qBittorrent config lines with the original newline style. */
function pmssQbittorrentConfigJoinLines(array $lines, bool $hadTrailingNewline, string $lineEnding): string
{
    $updated = implode("\n", $lines);
    if ($updated !== '' && $hadTrailingNewline) {
        $updated .= "\n";
    }

    return str_replace("\n", $lineEnding, $updated);
}

/**
 * Replace or restore one managed qBittorrent key inside the target section.
 */
function pmssQbittorrentConfigUpsert(string $config, string $section, string $key, string $value): ?string
{
    list($lines, $hadTrailingNewline, $lineEnding) = pmssQbittorrentConfigSplitLines($config);

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
        return pmssQbittorrentConfigJoinLines($lines, $hadTrailingNewline, $lineEnding);
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
                return pmssQbittorrentConfigJoinLines($lines, $hadTrailingNewline, $lineEnding);
            }
        }

        while ($sectionEnd > ($sectionStart + 1) && $lines[$sectionEnd - 1] === '') {
            $sectionEnd--;
        }

        // Insert before the next section header so user-owned settings keep their section.
        array_splice($lines, $sectionEnd, 0, [$managedLine]);
    }

    return pmssQbittorrentConfigJoinLines($lines, $hadTrailingNewline, $lineEnding);
}

/**
 * Load, transform, and atomically rewrite a user's qBittorrent config.
 */
function pmssQbittorrentConfigMutate(string $username, callable $mutator, ?string $configFile = null): bool
{
    if ($configFile === null) {
        $configFile = pmssQbittorrentConfigPath($username);
    }
    $config = pmssReadRegularFileContents($configFile);
    if ($config === null) {
        return false;
    }

    $updatedConfig = $mutator($config);
    if (!is_string($updatedConfig) || $updatedConfig === $config) {
        return false;
    }

    $mode = @fileperms($configFile);

    return pmssReplaceUserFilePreservingMetadata($configFile, $updatedConfig,
        is_int($mode) ? ($mode & 0777) : 0600);
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
        static function (string $config) use ($username): ?string {
            $updatedConfig = $config;
            $family = pmssQbittorrentCompatFamily($username);
            if ($family === 'lt1.2') {
                $updatedConfig = pmssQbittorrentDiskIoTypeRemove($updatedConfig);
                if (!is_string($updatedConfig)) return null;
            }
            foreach (pmssQbittorrentManagedConfigEntries($family) as $entry) {
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
            return pmssQbittorrentUploadThrottleConfig($config, $throttle);
        }
    );
}

/** Render the throttle in the daemon's persisted settings schema (KiB/s). */
function pmssQbittorrentUploadThrottleConfig(string $config, ?int $throttle): ?string
{
    $settings = @parse_ini_string($config, true, INI_SCANNER_RAW);
    if (!is_array($settings)) return null;
    $key = 'Session\\GlobalUPSpeedLimit';
    // Migration 2 introduced the session key; migration 9 removes the old key.
    $migrated = (int) ($settings['Meta']['MigrationVersion'] ?? 0) >= 2;
    if ($throttle !== null && $throttle > 0) {
        $updated = pmssQbittorrentConfigUpsert($config, 'BitTorrent', $key, (string) $throttle);
        if (!is_string($updated) || $migrated) return $updated;
        // Seed both keys before the first start, including legacy system builds.
        return pmssQbittorrentConfigUpsert($updated, 'Preferences', 'Connection\\GlobalUPLimit', (string) $throttle);
    }

    // A daemon may save an explicit unlimited value: leave it alone on reruns.
    if (isset($settings['BitTorrent'][$key]) && (string) $settings['BitTorrent'][$key] !== '0') {
        $config = pmssQbittorrentConfigUpsert($config, 'BitTorrent', $key, '0');
        if (!is_string($config)) return null;
    }
    if (!isset($settings['Preferences']['Connection\\GlobalUPLimit'])
        || (string) $settings['Preferences']['Connection\\GlobalUPLimit'] === '0') return $config;
    $updated = preg_replace('/^Connection\\\\GlobalUPLimit=.*\r?\n?/m', '', $config, 1);
    return is_string($updated) ? $updated : null;
}

/** Compare without writing while the daemon still owns its in-memory config. */
function pmssQbittorrentUploadThrottleNeedsUpdate(string $username): bool
{
    $path = pmssQbittorrentConfigPath($username);
    if (!pmssUserFilePathIsSafe($path)) return false;
    $config = pmssReadRegularFileContents($path);
    if ($config === null) return false;
    $updated = pmssQbittorrentUploadThrottleConfig($config, pmssReadTorrentThrottle($username));
    return is_string($updated) && $updated !== $config;
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

/**
 * Update qBittorrent config with a new WebUI password hash.
 */
function pmssUpdateQbittorrentPassword(string $username, string $password): bool
{
    return pmssQbittorrentApplyPassword($username, $password);
}

/** Write the user-owned config with the account's privileges. */
function pmssUpdateQbittorrentPasswordAsUser(string $username, string $password): bool
{
    $identity = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    if (!is_array($identity) || !function_exists('posix_geteuid')) {
        return false;
    }
    if (posix_geteuid() === (int) $identity['uid']) {
        return pmssUpdateQbittorrentPassword($username, $password);
    }
    if (posix_geteuid() !== 0) {
        return false;
    }

    // Load the operator library before the child switches to the account identity.
    $code = 'require_once '.var_export(__FILE__, true).";\n".<<<'PHP'
$account = posix_getpwnam($argv[1]);
if (!is_array($account)
    || !posix_initgroups($argv[1], (int) $account['gid'])
    || !posix_setgid((int) $account['gid'])
    || !posix_setuid((int) $account['uid'])) exit(1);
$password = fgets(STDIN);
if (!is_string($password)) exit(1);
$password = preg_replace('/\r?\n$/', '', $password);
exit(is_string($password) && $password !== '' && pmssUpdateQbittorrentPassword($argv[1], $password) ? 0 : 1);
PHP;
    $output = [];
    $command = pmssBuildCommand(PHP_BINARY, ['-r', $code, $username]);
    $status = pmssCredentialProcessRun($command, $password, $output);
    return $status === 0;
}
