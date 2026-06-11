<?php
/**
 * Deluge managed core.conf helpers.
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
 * Refresh the PMSS-managed subset of a user's Deluge core.conf.
 */
function pmssDelugeApplyManagedConfig(string $username, ?string $configFile = null): bool
{
    return pmssDelugeConfigMutate(
        $username,
        static function (array $config): array {
            foreach (pmssDelugeManagedConfigEntries() as $key => $value) {
                $config[$key] = $value;
            }

            return $config;
        },
        $configFile
    );
}
