<?php
/** Private temporary workspace creation and scoped cleanup. @license GPL-3.0-only */

function pmssPrivateTempBaseDirRealpath(?string $path = null): ?string
{
    $path = $path ?? sys_get_temp_dir();
    if ($path === '' || pmssFilesystemPathHasNulByte($path)) return null;
    $real = realpath($path);
    if ($real === false || $real === DIRECTORY_SEPARATOR || !is_dir($real) || !is_writable($real)) return null;
    return rtrim($real, DIRECTORY_SEPARATOR);
}
// Match the entire prefix: $ also accepts a position before a final newline.
function pmssPrivateTempPrefixIsSafe(string $prefix): bool { return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $prefix) === 1; }
function pmssCreatePrivateTempFile(string $prefix): ?string
{
    if (!pmssPrivateTempPrefixIsSafe($prefix) || ($base = pmssPrivateTempBaseDirRealpath()) === null) return null;
    $path = @tempnam($base, $prefix);
    if (!is_string($path) || pmssFilesystemPathHasNulByte($path) || is_link($path)) return null;
    $real = realpath($path);
    $basePrefix = $base.DIRECTORY_SEPARATOR;
    if ($real === false || !is_file($real) || strpos($real, $basePrefix) !== 0 || strpos(basename($real), $prefix) !== 0) {
        if (pmssRegularFilePathIsReadable($path)) @unlink($path);
        return null;
    }
    return $path;
}

function pmssCreatePrivateTempDir(string $prefix): ?string
{
    if (!pmssPrivateTempPrefixIsSafe($prefix)) { logMessage('[WARN] Refusing private temporary directory with unsafe prefix'); return null; }
    $path = pmssCreatePrivateTempFile($prefix);
    if ($path === null) return null;
    if (!@unlink($path) || !@mkdir($path, 0700)) {
        if (pmssRegularFilePathIsReadable($path)) @unlink($path);
        return null;
    }
    // Do not hand a caller a workspace whose private mode could not be set.
    if (is_link($path) || !@chmod($path, 0700)) {
        @rmdir($path);
        return null;
    }
    if (pmssPrivateTempDirRealpath($path, $prefix) === null) {
        @rmdir($path);
        return null;
    }
    return $path;
}

function pmssPrivateTempDirRealpath(string $path, string $prefix, ?callable $logger = null): ?string
{
    $log = $logger ?: 'logMessage';
    if (!pmssPrivateTempPrefixIsSafe($prefix)) { $log('[WARN] Refusing temporary directory cleanup for unsafe prefix'); return null; }
    if (pmssFilesystemPathHasNulByte($path)) { $log('[WARN] Refusing temporary directory cleanup for unsafe path'); return null; }
    $base = pmssPrivateTempBaseDirRealpath(); $real = $path !== '' && !is_link($path) ? realpath($path) : false;
    if ($base === null || $real === false || !is_dir($real)) { $log('[WARN] Refusing temporary directory cleanup for unresolved path: '.$path); return null; }
    // tempnam() creates direct children; a matching nested name is not our workspace.
    if (dirname($real) !== $base || strpos(basename($real), $prefix) !== 0) { $log('[WARN] Refusing temporary directory cleanup outside PMSS temp scope: '.$real); return null; }
    return $real;
}

/** Remove a PMSS-owned private temporary directory after validating its scope. */
function pmssRemovePrivateTempDir(string $path, string $prefix, string $description, ?callable $logger = null, ?callable $runner = null): int
{
    $real = pmssPrivateTempDirRealpath($path, $prefix, $logger);
    if ($real === null) return 1;
    $command = 'rm -rf '.escapeshellarg($real);
    if ($runner !== null) return (int) $runner($description, $command);
    if (!function_exists('runStep')) {
        $log = $logger ?: 'logMessage';
        $log('[WARN] Unable to remove private temporary directory; runStep unavailable');
        return 1;
    }
    return runStep($description, $command);
}
