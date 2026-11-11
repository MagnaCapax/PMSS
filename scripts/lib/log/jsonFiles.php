<?php
/** JSON file reads and JSON Lines traversal through the shared log guards. @license GPL-3.0-only */

/** Read a JSON object file as an associative array, rejecting unsafe paths when requested. */
function pmssJsonFileReadAssoc(string $path, bool $safePathRequired = false): ?array
{
    if (!pmssRegularFilePathIsReadable($path) || ($safePathRequired && !pmssPathTargetIsSafe($path, false, true))) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return pmssJsonDecodeAssoc($raw);
}

/** Append one payload to a JSON Lines file. */
function pmssJsonLineAppend(string $path, array $payload): bool
{
    return pmssLogWritePathIsSafe($path)
        && is_string($encoded = pmssJsonEncodeSafe($payload, JSON_UNESCAPED_SLASHES))
        // Include the delimiter: a partial record is not a successful append.
        && pmssFileWriteComplete($path, $encoded.PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** Stream decodable JSON Lines entries to a caller-owned handler. */
function pmssJsonLineFileEach(string $path, callable $handler): bool
{
    if (!pmssLogWritePathIsSafe($path) || !pmssRegularFilePathIsReadable($path)
        || ($handle = pmssRegularFileOpenVerified($path)) === false) {
        return false;
    }
    try {
        while (($line = fgets($handle)) !== false) {
            if (($decoded = pmssJsonDecodeAssoc($line)) !== null) {
                $handler($decoded);
            }
        }
        // A failed read before EOF must not report a complete scan.
        return feof($handle);
    } finally {
        // Release the reader even when a handler throws; preserve its exception.
        fclose($handle);
    }
}

/** Read decodable JSON Lines entries from a file. */
function pmssJsonLineFileRead(string $path): array { $entries = []; pmssJsonLineFileEach($path, static function (array $entry) use (&$entries): void { $entries[] = $entry; }); return $entries; }
/** Return the last decodable JSON Lines entry from a file. */
function pmssJsonLineFileLast(string $path): ?array { $last = null; pmssJsonLineFileEach($path, static function (array $entry) use (&$last): void { $last = $entry; }); return $last; }
