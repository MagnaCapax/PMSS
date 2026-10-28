<?php
/** Pure Deluge dual-JSON decoding, encoding, and change detection.
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../log.php';

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
    for ($index = $start; $index < $length; $index++) {
        $ch = $raw[$index];
        if ($inString) {
            if (!$escape && $ch === '"') {
                $inString = false;
            }
            // A backslash escapes exactly one following byte, including a backslash.
            $escape = !$escape && $ch === '\\';
            continue;
        }
        if ($ch === '"') {
            $inString = true;
            continue;
        }
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}' && --$depth === 0) {
            // Only the first complete object is metadata; the whole remainder is config.
            $meta = pmssJsonDecodeAssoc(substr($raw, $start, $index + 1 - $start));
            $config = pmssJsonDecodeAssoc(ltrim(substr($raw, $index + 1)));
            return $meta === null || $config === null ? null : ['meta' => $meta, 'config' => $config];
        }
    }
    return null;
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

/** Transform only config data; preserve metadata and return null for every no-write outcome. */
function pmssDelugeConfigTransform(?string $raw, callable $mutator): ?string
{
    $parsed = $raw !== null ? pmssDelugeConfigDecode($raw) : null;
    if ($parsed === null) {
        return null;
    }
    $config = $mutator($parsed['config']);
    if (!is_array($config) || $config === $parsed['config']) {
        return null;
    }
    $updated = pmssDelugeConfigEncode($parsed['meta'], $config);
    return $updated !== $raw ? $updated : null;
}
