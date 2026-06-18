<?php
/**
 * Deluge managed configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/integerSetting.php';

/**
 * Resolve the canonical Deluge core.conf path for a user.
 */
function pmssDelugeConfigPath(string $username): string
{
    return pmssUserHomeFilePath($username, '.config/deluge/core.conf');
}

/**
 * Resolve the canonical Deluge auth path for a user.
 */
function pmssDelugeManagedAuthPath(string $username): string
{
    return dirname(pmssDelugeConfigPath($username)).'/auth';
}

/**
 * Resolve the canonical Deluge hostlist.conf path for a user.
 */
function pmssDelugeHostlistPath(string $username): string
{
    return dirname(pmssDelugeConfigPath($username)).'/hostlist.conf';
}

/**
 * Read the daemon localclient password from a Deluge auth file.
 */
function pmssDelugeManagedAuthLocalclientPasswordRead(string $authPath): string
{
    $content = pmssReadRegularFileContents($authPath);
    if ($content === null || $content === '') {
        return '';
    }

    return preg_match('/^localclient:([^:\r\n]+):[0-9]+$/m', $content, $matches) === 1
        ? $matches[1]
        : '';
}

/**
 * Return the PMSS-managed Deluge core.conf keys and values.
 *
 * Keep this list intentionally small: only shared-host safety limits belong
 * here so maintenance can preserve user-owned settings.
 *
 * @return array<string, int>
 */
function pmssDelugeManagedConfigEntries(): array
{
    return [
        'max_active_downloading' => 5,
        'max_active_limit' => 500,
        'max_connections_global' => 300,
        'max_upload_slots_global' => 15,
        'pre_allocate_storage' => true,
    ];
}

/**
 * Decode Deluge's dual-JSON config format into metadata + config arrays.
 *
 * @return array{meta:array,config:array}|null
 */
function pmssDelugeConfigDecode(string $raw): ?array
{
    if (strpos($raw, "\0") !== false) {
        return null;
    }

    $length = strlen($raw);
    $start = strspn($raw, " \t\n\r");
    if ($start >= $length || $raw[$start] !== '{') {
        return null;
    }

    $depth = 0;
    $inString = false;
    $escape = false;
    $firstObjectEnd = null;
    for ($index = $start; $index < $length; $index++) {
        $ch = $raw[$index];
        if ($inString) {
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\') {
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $inString = false;
            }
            continue;
        }
        if ($ch === '"') {
            $inString = true;
            continue;
        }
        if ($ch === '{') {
            $depth++;
            continue;
        }
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $firstObjectEnd = $index + 1;
                break;
            }
        }
    }

    if ($firstObjectEnd === null) {
        return null;
    }

    $meta = pmssJsonDecodeAssoc(substr($raw, $start, $firstObjectEnd - $start));
    $config = pmssJsonDecodeAssoc(ltrim(substr($raw, $firstObjectEnd)));
    if ($meta === null || $config === null) {
        return null;
    }

    return ['meta' => $meta, 'config' => $config];
}

/**
 * Encode Deluge metadata + config arrays back into the dual-JSON format.
 */
function pmssDelugeConfigEncode(array $meta, array $config): ?string
{
    $metaJson = pmssJsonEncodePretty($meta, JSON_UNESCAPED_UNICODE);
    $configJson = pmssJsonEncodePretty($config, JSON_UNESCAPED_UNICODE);

    return ($metaJson === null || $configJson === null) ? null : $metaJson.$configJson;
}

/**
 * Load, transform, and atomically rewrite a user's Deluge core.conf.
 */
function pmssDelugeConfigMutate(string $username, callable $mutator, ?string $configFile = null): bool
{
    $configFile = $configFile ?? pmssDelugeConfigPath($username);
    $raw = pmssReadRegularFileContents($configFile);
    $parsed = $raw !== null ? pmssDelugeConfigDecode($raw) : null;
    if (!is_array($parsed)) {
        return false;
    }

    $updatedConfig = $mutator($parsed['config']);
    if (!is_array($updatedConfig) || $updatedConfig === $parsed['config']) {
        return false;
    }

    $updatedRaw = pmssDelugeConfigEncode($parsed['meta'], $updatedConfig);
    if (!is_string($updatedRaw) || $updatedRaw === $raw) {
        return false;
    }

    $mode = @fileperms($configFile);

    return pmssReplaceUserFileWithMetadata(
        $configFile,
        $updatedRaw,
        is_int($mode) ? ($mode & 0777) : 0600,
        $username,
        $username
    );
}

/**
 * Return true for the hostlist entry Deluge Web UI uses for the local daemon.
 *
 * @param mixed $host
 * @param mixed $login
 */
function pmssDelugeHostlistEntryIsLocalclient($host, $login): bool
{
    return is_string($host)
        && is_string($login)
        && $login === 'localclient'
        && in_array($host, ['127.0.0.1', 'localhost'], true);
}

/**
 * Synchronize hostlist.conf with the current daemon localclient password.
 */
function pmssDelugeHostlistSyncLocalclientPassword(string $username, ?string $hostlistFile = null, ?string $authFile = null): bool
{
    $authFile = $authFile ?? pmssDelugeManagedAuthPath($username);
    $localclientPassword = pmssDelugeManagedAuthLocalclientPasswordRead($authFile);
    if ($localclientPassword === '') {
        return false;
    }

    $hostlistFile = $hostlistFile ?? pmssDelugeHostlistPath($username);
    $raw = pmssReadRegularFileContents($hostlistFile);
    $parsed = $raw !== null ? pmssDelugeConfigDecode($raw) : null;
    if (!is_array($parsed) || !is_array($parsed['config']['hosts'] ?? null)) {
        return false;
    }

    $config = $parsed['config'];
    $changed = false;
    foreach ($config['hosts'] as $index => $entry) {
        if (!is_array($entry) || count($entry) < 5 || !pmssDelugeHostlistEntryIsLocalclient($entry[1] ?? null, $entry[3] ?? null)) {
            continue;
        }

        if (($entry[4] ?? null) === $localclientPassword) {
            continue;
        }

        $entry[4] = $localclientPassword;
        $config['hosts'][$index] = $entry;
        $changed = true;
    }

    if (!$changed) {
        return false;
    }

    $updatedRaw = pmssDelugeConfigEncode($parsed['meta'], $config);
    return is_string($updatedRaw)
        && $updatedRaw !== $raw
        && pmssReplaceUserFilePreservingMetadata($hostlistFile, $updatedRaw, 0644);
}

/**
 * Restart Deluge Web UI after hostlist.conf changes so it reloads daemon auth.
 */
function pmssDelugeRestartWebIfEnabled(string $username): void
{
    if (!is_file(pmssUserHomeFilePath($username, '.delugeEnable'))) {
        return;
    }

    runStep('Restarting Deluge Web UI (hostlist auth changed)', sprintf(
        'killall -u %s -TERM deluge-web 2>/dev/null || true',
        escapeshellarg($username)
    ));
}

/**
 * Refresh the PMSS-managed subset of a user's Deluge core.conf.
 */
function pmssDelugeApplyManagedConfig(string $username, ?string $configFile = null): bool
{
    $coreChanged = pmssDelugeConfigMutate(
        $username,
        static function (array $config): array {
            foreach (pmssDelugeManagedConfigEntries() as $key => $value) {
                $config[$key] = $value;
            }

            return $config;
        },
        $configFile
    );
    $hostlistChanged = pmssDelugeHostlistSyncLocalclientPassword($username);
    if ($hostlistChanged) {
        pmssDelugeRestartWebIfEnabled($username);
    }

    return $coreChanged || $hostlistChanged;
}
