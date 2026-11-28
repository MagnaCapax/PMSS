<?php
/** Customer-local support dependencies; no operator bootstrap. @license GPL-3.0-only */

/** Local copy of scripts/lib/runtime/filesystem.php::pmssDirEnsureExists; preserve its guards and behavior. */
function pmssSupportLocalDirEnsureExists(string $path, int $mode = 0755): bool { return $path !== '' && !pmssSupportLocalFilesystemPathHasNulByte($path) && (is_dir($path) || @mkdir($path, $mode, true) || is_dir($path)); }

/** Local copy of scripts/lib/runtime/filesystem.php::pmssFilesystemPathHasNulByte; preserve its guards and behavior. */
function pmssSupportLocalFilesystemPathHasNulByte(string $path): bool { return strpos($path, "\0") !== false; }

/** Local copy of scripts/lib/runtime/filesystem.php::pmssRegularFilePathIsReadable; preserve its guards and behavior. */
function pmssSupportLocalRegularFilePathIsReadable(string $path): bool { return $path !== '' && !pmssSupportLocalFilesystemPathHasNulByte($path) && is_file($path) && !is_link($path); }

/** Local copy of scripts/lib/runtime/filesystem.php::pmssLockFilePathIsSafe; preserve its guards and behavior. */
function pmssSupportLocalLockFilePathIsSafe(string $path): bool { return $path !== '' && !pmssSupportLocalFilesystemPathHasNulByte($path) && !is_link($path) && (!file_exists($path) || is_file($path)); }

/** Local copy of scripts/lib/runtime/filesystem.php::pmssFilesystemStatSameInode; preserve its guards and behavior. */
function pmssSupportLocalFilesystemStatSameInode(array $left, array $right): bool { return ($left['dev'] ?? null) === ($right['dev'] ?? null) && ($left['ino'] ?? null) === ($right['ino'] ?? null); }

/** Local copy of scripts/lib/runtime/filesystem.php::pmssUserFileStatIsSingleRegular; preserve its guards and behavior. */
function pmssSupportLocalUserFileStatIsSingleRegular($stat): bool
{
    return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0100000 && ($stat['nlink'] ?? 0) === 1;
}

/** Local copy of scripts/lib/runtime/filesystem.php::pmssLockFileHandleMatchesPath; preserve its guards and behavior. */
function pmssSupportLocalLockFileHandleMatchesPath($handle, string $path, ?array $pathStat = null, ?array &$handleStat = null): bool
{
    $handleStat = null; $validateLockPath = $pathStat === null;
    if (!pmssSupportLocalStreamHandleIsOpen($handle) || ($validateLockPath && !pmssSupportLocalLockFilePathIsSafe($path))) return false;
    $observedHandleStat = @fstat($handle); $pathStat = $validateLockPath ? @stat($path) : $pathStat;
    $handleStat = is_array($observedHandleStat) ? $observedHandleStat : null;
    if ($handleStat === null || !is_array($pathStat)) return false;
    if (!$validateLockPath) return pmssSupportLocalFilesystemStatSameInode($handleStat, $pathStat);
    return isset($handleStat['dev'], $handleStat['ino'], $pathStat['dev'], $pathStat['ino'])
        && (int) $handleStat['dev'] === (int) $pathStat['dev'] && (int) $handleStat['ino'] === (int) $pathStat['ino'];
}

/** Local copy of scripts/lib/runtime/filesystemStream.php::pmssFileOpenWithUmask; preserve its guards and behavior. */
function pmssSupportLocalFileOpenWithUmask(string $path, string $mode, int $mask)
{
    // Keep malformed opens on the false-result path before changing process state.
    if ($path === '' || $mode === '' || strpos($path, "\0") !== false || strpos($mode, "\0") !== false) return false;
    $previous = umask($mask);
    try {
        return @fopen($path, $mode);
    } finally {
        umask($previous);
    }
}

/** Local copy of scripts/lib/runtime/filesystemStream.php::pmssRegularFileOpenVerified; preserve its guards and behavior. */
function pmssSupportLocalRegularFileOpenVerified(string $path, ?array $pathStat = null, ?array &$handleStat = null)
{
    $handleStat = null;
    // Supplied metadata must not bypass raw-path validation before fopen().
    if ($path === '' || pmssSupportLocalFilesystemPathHasNulByte($path)) return false;
    if ($pathStat === null) {
        clearstatcache(true, $path);
        $pathStat = @lstat($path);
    }
    if (!is_array($pathStat) || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000) return false;
    $handle = @fopen($path, 'rbn');
    if (!pmssSupportLocalStreamHandleIsOpen($handle)) return false;
    $verified = false;
    try {
        $verified = pmssSupportLocalLockFileHandleMatchesPath($handle, $path, $pathStat, $handleStat);
        return $verified ? $handle : false;
    } finally {
        if (!$verified) fclose($handle);
    }
}

/** Local copy of scripts/lib/runtime/filesystemStream.php::pmssStreamHandleIsOpen; preserve its guards and behavior. */
function pmssSupportLocalStreamHandleIsOpen($handle): bool { return is_resource($handle) && get_resource_type($handle) === 'stream'; }

/** Local copy of scripts/lib/runtime/filesystemStream.php::pmssStreamWriteAll; preserve its guards and behavior. */
function pmssSupportLocalStreamWriteAll($handle, string $payload): bool
{
    $length = strlen($payload);
    for ($offset = 0; $offset < $length; $offset += $written) {
        // A wrapper may close the stream during a partial write; recheck before retrying.
        if (!pmssSupportLocalStreamHandleIsOpen($handle)) return false;
        $written = @fwrite($handle, substr($payload, $offset));
        if (!is_int($written) || $written < 1) return false;
    }
    return true;
}

