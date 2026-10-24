<?php
/** Checked regular-file acquisition and record decoding. @license GPL-3.0-only */

/** Read a bounded regular file and optionally return the metadata from its opened inode. */
function pmssReadRegularFileContentsVerified(string $path, ?int $requiredUid = null, int $maxBytes = 4096, ?array &$metadata = null): ?string { return pmssRegularFileRead($path, $requiredUid, $maxBytes, $metadata); }

/** Preserve the unbounded reader's relative-path and pseudo-file support. */
function pmssReadRegularFileContents(string $path): ?string { return pmssRegularFileRead($path); }

/** One checked-open lifetime; only bounded reads require owner, size, and complete-length checks. */
function pmssRegularFileRead(string $path, ?int $requiredUid = null, ?int $maxBytes = null, ?array &$metadata = null): ?string
{
    $metadata = null;
    if ($path === '' || pmssFilesystemPathHasNulByte($path)) return null;
    // Bounded reads require absolute paths and a representable one-byte overread.
    if ($maxBytes !== null && ($path[0] !== '/' || $maxBytes < 1 || $maxBytes === PHP_INT_MAX)) return null;
    clearstatcache(true, $path);
    $entry = @lstat($path);
    if (!pmssUserFileStatIsSingleRegular($entry)) return null;
    // Non-blocking open keeps a substituted FIFO from stalling either reader.
    $handle = @fopen($path, 'rbn');
    if (!pmssStreamHandleIsOpen($handle)) return null;
    try {
        if (!pmssLockFileHandleMatchesPath($handle, $path, $entry, $opened)
            || !pmssUserFileStatIsSingleRegular($opened)
            || ($requiredUid !== null && ($opened['uid'] ?? null) !== $requiredUid)
            || ($maxBytes !== null && ($opened['size'] ?? PHP_INT_MAX) > $maxBytes)) return null;
        $contents = $maxBytes === null ? @stream_get_contents($handle) : @stream_get_contents($handle, $maxBytes + 1);
        if (!is_string($contents) || ($maxBytes !== null && (strlen($contents) > $maxBytes || strlen($contents) !== $opened['size']))) return null;
        $metadata = $opened;
        return $contents;
    } finally {
        @fclose($handle);
    }
}

function pmssReadRegularFileTrimmed(string $path): ?string { return (($contents = pmssReadRegularFileContents($path)) === null) ? null : trim($contents); }
function pmssReadRegularFileNonEmptyLines(string $path): array { $lines = preg_split('/\r?\n/', pmssReadRegularFileContents($path) ?? ''); return pmssNonEmptyStrings(is_array($lines) ? $lines : []); }

function pmssReadSerializedArrayFile(string $path): ?array
{
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null || $raw === '') return null;
    $data = @unserialize($raw, ['allowed_classes' => false]);
    return is_array($data) ? $data : null;
}

function pmssReadOptionalSerializedArrayFile(string $path, string $label = 'serialized array file'): array
{
    if (pmssFilesystemPathHasNulByte($path) || !file_exists($path)) return [];
    $payload = pmssReadSerializedArrayFile($path);
    if ($payload === null) throw new RuntimeException('Invalid '.$label.': '.$path);
    return $payload;
}

function pmssReadRequiredRegularFile(string $path, string $label = 'required file'): string
{
    if (!pmssRegularFilePathIsReadable($path)) throw new RuntimeException('Missing '.$label.': '.$path);
    $contents = pmssReadRegularFileContents($path);
    if ($contents === null) throw new RuntimeException('Unable to read '.$label.': '.$path);
    return $contents;
}

function pmssReadRegularFileDigits(string $path): ?string { return (($raw = pmssReadRegularFileTrimmed($path)) !== null && ctype_digit($raw)) ? $raw : null; }
function pmssColonRecordFieldsLookup(string $path, string $recordName, int $minFields = 2, bool $skipEmptyLines = true): ?array
{
    if ($recordName === '' || $minFields < 1 || strpos($recordName, ':') !== false || preg_match('/[\r\n\0\/]/', $recordName) === 1 || !pmssRegularFilePathIsReadable($path)) return null;
    $flags = FILE_IGNORE_NEW_LINES | ($skipEmptyLines ? FILE_SKIP_EMPTY_LINES : 0);
    $lines = @file($path, $flags);
    if (!is_array($lines)) return null;
    $prefix = $recordName.':';
    foreach ($lines as $line) {
        if (!is_string($line) || strpos($line, $prefix) !== 0) continue;
        $fields = explode(':', $line);
        return count($fields) >= $minFields ? $fields : null;
    }
    return null;
}

function pmssReadRegularFileInt(string $path, int $default = 0): int
{
    return pmssUnsignedDecimalIntParse(pmssReadRegularFileTrimmed($path) ?? '') ?? $default;
}
