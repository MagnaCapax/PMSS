<?php
/**
 * Minimal Qt settings QVariant readers used by user transfer helpers.
 *
 * @license GPL-3.0-only
 */

/** Decode the byte escapes used by Qt's @Variant(...) settings text. */
function pmssUserTransferQtSettingsBytesDecode(string $encoded): string
{
    $out = '';
    $length = strlen($encoded);
    $escapes = ['0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t"];
    for ($i = 0; $i < $length; $i++) {
        if ($encoded[$i] !== '\\' || $i + 1 >= $length) {
            $out .= $encoded[$i];
            continue;
        }
        $next = $encoded[++$i];
        if (isset($escapes[$next])) {
            $out .= $escapes[$next];
        } elseif ($next === 'x') {
            $hex = '';
            while ($i + 1 < $length && strlen($hex) < 2 && ctype_xdigit($encoded[$i + 1])) {
                $hex .= $encoded[++$i];
            }
            $out .= $hex === '' ? 'x' : chr(hexdec($hex));
        } else {
            $out .= $next;
        }
    }
    return $out;
}

/** Parse the QVariantMap/QVariantHash string map used by legacy qBittorrent. */
function pmssUserTransferQtVariantStringMap(string $bytes): array
{
    $offset = 0;
    $type = pmssUserTransferQtReadInt32($bytes, $offset);
    $count = pmssUserTransferQtReadInt32($bytes, $offset);
    if (!in_array($type, [8, 28], true) || $count === null || $count < 0 || $count > 4096) {
        return [];
    }

    $map = [];
    for ($i = 0; $i < $count; $i++) {
        $key = pmssUserTransferQtReadString($bytes, $offset);
        $valueType = pmssUserTransferQtReadInt32($bytes, $offset);
        if ($key === null || $valueType !== 10 || ($value = pmssUserTransferQtReadString($bytes, $offset)) === null) {
            return [];
        }
        $map[$key] = $value;
    }
    return $map;
}

/** Read a big-endian signed int32 from a Qt data stream. */
function pmssUserTransferQtReadInt32(string $bytes, int &$offset): ?int
{
    if ($offset + 4 > strlen($bytes)) {
        return null;
    }
    $parts = unpack('Nvalue', substr($bytes, $offset, 4));
    $offset += 4;
    $value = is_array($parts) ? (int) $parts['value'] : 0;
    return $value >= 0x80000000 ? $value - 0x100000000 : $value;
}

/** Read a UTF-16BE QString from a Qt data stream. */
function pmssUserTransferQtReadString(string $bytes, int &$offset): ?string
{
    $length = pmssUserTransferQtReadInt32($bytes, $offset);
    if ($length === null || $length < -1 || ($length % 2) !== 0 || $offset + $length > strlen($bytes)) {
        return null;
    }
    if ($length === -1) {
        return '';
    }

    $raw = substr($bytes, $offset, $length);
    $offset += $length;
    if (is_string($converted = function_exists('iconv') ? @iconv('UTF-16BE', 'UTF-8//IGNORE', $raw) : false)) {
        return $converted;
    }

    $fallback = '';
    for ($i = 1; $i < strlen($raw); $i += 2) {
        $fallback .= $raw[$i];
    }
    return $fallback;
}
