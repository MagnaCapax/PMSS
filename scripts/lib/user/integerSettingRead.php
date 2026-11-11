<?php
/** Verified integer-setting reads and absent-file policy. Loaded by integerSetting.php.
 * @license GPL-3.0-only
 */

/** Read a verified setting, retaining the caller's default for any unavailable value. */
function pmssIntegerSettingFileRead(string $path, callable $parser, int $default = 0): int
{
    return pmssIntegerSettingFileParse($path, $parser) ?? $default;
}

/**
 * Like pmssIntegerSettingFileRead, but tells an absent file ($default) apart from a present file that cannot be
 * read or parsed (null), so a caller that enforces a limit can keep its current state instead of reading "0".
 */
function pmssIntegerSettingFileReadOrNull(string $path, callable $parser, int $default = 0): ?int
{
    // An invalid path cannot prove absence; keep enforcing callers on their unreadable-state path.
    if ($path === '' || pmssFilesystemPathHasNulByte($path)) return null;
    clearstatcache(true, $path);
    if (@lstat($path) === false) return $default;
    return pmssIntegerSettingFileParse($path, $parser);
}

/** Read and parse once; callers decide whether absence or unreadability uses a default. */
function pmssIntegerSettingFileParse(string $path, callable $parser): ?int
{
    $contents = pmssReadRegularFileContentsVerified($path, null, 256);
    if ($contents === null) return null;
    $raw = trim($contents, " \t\r\n");
    if ($raw === '') return null;

    $error = null;
    $value = $parser($raw, $error);
    return $value !== null ? (int) $value : null;
}
