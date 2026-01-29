<?php
/**
 * Deluge web.conf parsing and writing helpers.
 *
 * @license GPL-3.0-only
 */

function pmssSplitFirstJsonObject(string $content): ?array
{
    $len = strlen($content);
    $i = 0;

    while ($i < $len) {
        $ch = $content[$i];
        if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
            $i++;
            continue;
        }
        break;
    }

    if ($i >= $len || $content[$i] !== '{') {
        return null;
    }

    $start = $i;
    $depth = 0;
    $inString = false;
    $escape = false;

    for (; $i < $len; $i++) {
        $ch = $content[$i];
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
                $end = $i + 1;
                return [
                    substr($content, $start, $end - $start),
                    substr($content, $end),
                ];
            }
        }
    }

    return null;
}

function pmssDelugeReadWebConf(string $path): ?array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return null;
    }
    if (strpos($raw, "\0") !== false) {
        // Deluge web.conf is expected to be plain text JSON (two objects).
        // Treat NUL bytes as corruption/malicious input and refuse to parse.
        return null;
    }

    $split = pmssSplitFirstJsonObject($raw);
    if (!is_array($split) || count($split) !== 2) {
        return null;
    }

    $meta = json_decode($split[0], true);
    if (!is_array($meta) || json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    $config = json_decode(ltrim((string)$split[1]), true);
    if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return ['meta' => $meta, 'config' => $config];
}

function pmssDelugeWriteWebConf(string $path, array $meta, array $config, string $owner): bool
{
    $existingMode = @fileperms($path);
    $mode = is_int($existingMode) ? ($existingMode & 0777) : 0600;

    $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($metaJson === false || $configJson === false) {
        return false;
    }

    $dir = dirname($path);
    if (!is_dir($dir) || is_link($dir)) {
        return false;
    }
    $tmp = @tempnam($dir, basename($path).'.pmss-tmp-');
    if ($tmp === false) {
        return false;
    }
    if (file_put_contents($tmp, $metaJson.$configJson) === false) {
        @unlink($tmp);
        return false;
    }

    @chmod($tmp, $mode);
    @chown($tmp, $owner);
    @chgrp($tmp, $owner);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    @chmod($path, $mode);
    @chown($path, $owner);
    @chgrp($path, $owner);

    return true;
}

