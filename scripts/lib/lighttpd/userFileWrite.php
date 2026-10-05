<?php
/**
 * Managed file writing helpers shared by lighttpd and other PMSS writers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../runtime/filesystem.php';
require_once __DIR__.'/accountPath.php';

function pmssUserFilePathIsSafe(string $path): bool
{
    return pmssPathTargetIsSafe($path, false, true);
}

/** Keep customer-readable status artifacts inside their account home. */
function pmssUserStatusPathIsSafe(string $home, string $path): bool { return $path !== '' && pmssPathTargetIsSafe($path, false, true) && pmssPathWithinResolvedRoot($path, $home); }

/**
 * Confirm a safe path resolves inside an already-existing directory root.
 */
function pmssPathWithinRootIsSafe(string $path, string $rootPath, bool $directoryTarget = false): bool
{
    $rootPath = rtrim($rootPath, '/');
    if ($rootPath === '') {
        $rootPath = '/';
    }

    if ($rootPath !== '/' && !pmssPathTargetIsSafe($rootPath, true)) {
        return false;
    }
    if (!pmssPathTargetIsSafe($path, $directoryTarget)) {
        return false;
    }

    $resolvedRoot = @realpath($rootPath);
    $resolvedPath = @realpath($path);
    if ($resolvedRoot === false || $resolvedPath === false) {
        return false;
    }
    if ($resolvedRoot === '/') {
        return $resolvedPath !== '' && $resolvedPath[0] === '/';
    }

    return $resolvedPath === $resolvedRoot || strpos($resolvedPath, $resolvedRoot.'/') === 0;
}

/**
 * Ensure a directory exists when the target path is safe.
 */
function pmssEnsureSafeDir(string $path, int $mode): bool
{
    if (!pmssPathTargetIsSafe($path, true)) {
        return false;
    }

    if (!pmssDirEnsureExists($path, $mode)) {
        return false;
    }

    // A created directory is not ready for callers until its requested mode applies.
    return @chmod($path, $mode) && is_dir($path) && !is_link($path);
}

/** Ensure each managed directory exists, reporting unsafe paths through the callback. */
function pmssManagedDirsEnsure(array $directories, callable $failureLogger): void { foreach ($directories as $dir => $mode) { pmssEnsureSafeDir((string) $dir, (int) $mode) || $failureLogger((string) $dir); } }

/** Apply owner/group metadata only when the caller is root. */
function pmssUserFileApplyOwnership(string $path, string $owner, ?string $group = null): void
{
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @lchown($path, $owner);
        @lchgrp($path, ($group === null || $group === '') ? $owner : $group);
    }
}

/** Apply file mode plus root-only ownership metadata to a managed user path. */
function pmssUserFileApplyMetadata(string $path, string $owner, int $mode, ?string $group = null): void
{
    @chmod($path, $mode);
    pmssUserFileApplyOwnership($path, $owner, $group);
}

/** Converge an account file's mode as its owner; older ownership is repaired by the managed path. */
function pmssAccountFileApplyMetadata(string $username, string $home, string $path, int $mode): void
{
    if ($mode < 0 || $mode > 0777 || !pmssUserFilePathIsSafe($path) || !is_file($path)) return;
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    if (is_array($account) && @fileowner($path) === $account['uid']
        && pmssAccountPathRun($username, $home, [$path],
            'find '.escapeshellarg($path).' -maxdepth 0 -type f -links 1 -exec chmod '.sprintf('%o', $mode).' {} +')) {
        return;
    }
    pmssUserFileApplyMetadata($path, $username, $mode);
}

/**
 * Atomically replace a regular file, with optional temp-file preparation.
 * Incomplete writes leave the destination untouched and remove the temp file.
 */
function pmssReplaceUserFile(string $path, string $content, ?callable $prepareTemp = null): bool
{
    if (!pmssUserFilePathIsSafe($path)) {
        return false;
    }

    $tmp = @tempnam(dirname($path), basename($path).'.pmss-tmp-');
    if (!is_string($tmp) || !pmssRegularFilePathIsReadable($tmp)) {
        if (is_string($tmp)) @unlink($tmp);
        return false;
    }

    try {
        if (@file_put_contents($tmp, $content) !== strlen($content)) {
            return false;
        }
        if ($prepareTemp !== null && $prepareTemp($tmp) === false) {
            return false;
        }
        if (!pmssRegularFilePathIsReadable($tmp) || !pmssUserFilePathIsSafe($path) || !@rename($tmp, $path)) {
            return false;
        }

        $tmp = null;
        return true;
    } finally {
        // Failed operations and thrown I/O errors must not strand staging files.
        if (is_string($tmp)) @unlink($tmp);
    }
}

function pmssReplaceUserFileWithMetadata(string $path, string $content, int $mode, ?string $owner = null, ?string $group = null): bool
{
    return pmssReplaceUserFile($path, $content, static function (string $tmpPath) use ($mode, $owner, $group): void {
        if ($owner === null) {
            @chmod($tmpPath, $mode);
            return;
        }

        pmssUserFileApplyMetadata($tmpPath, $owner, $mode, $group);
    });
}

/** Replace a file while keeping existing mode/uid/gid when available. */
function pmssReplaceUserFilePreservingMetadata(string $path, string $content, int $fallbackMode = 0644): bool
{
    $mode = $fallbackMode;
    $owner = null;
    $group = null;
    $canAdjustOwnership = function_exists('posix_geteuid') && @posix_geteuid() === 0;

    if (is_file($path) && is_array($stat = @stat($path))) {
        $mode = $stat['mode'] & 0777;
        if ($canAdjustOwnership) {
            $owner = $stat['uid'];
            $group = $stat['gid'];
        }
    }

    return pmssReplaceUserFile($path, $content, static function (string $tmpPath) use ($group, $mode, $owner): void {
        @chmod($tmpPath, $mode);
        if ($owner !== null) @chown($tmpPath, $owner);
        if ($group !== null) @chgrp($tmpPath, $group);
    });
}

/** Persist a numeric network port through the shared symlink-safe writer. */
function pmssNetworkPortFileWrite(string $path, int $port, int $min = 1, int $max = 65535, int $fallbackMode = 0640): bool
{
    return pmssNetworkPortInRange($port, $min, $max)
        && pmssReplaceUserFilePreservingMetadata($path, (string) $port, $fallbackMode);
}

function pmssAtomicWriteFile(string $path, string $content, ?int $mode = null): bool
{
    return $mode === null ? pmssReplaceUserFile($path, $content) : pmssReplaceUserFileWithMetadata($path, $content, $mode);
}

/** Encode and atomically publish one newline-terminated pretty JSON document. */
function pmssAtomicJsonFileWrite(string $path, array $payload, int $mode): bool
{
    $encoded = pmssJsonEncodePrettyLine($payload);
    return is_string($encoded) && pmssReplaceUserFile(
        $path,
        $encoded,
        static function (string $temporaryPath) use ($mode): bool {
            return @chmod($temporaryPath, $mode);
        }
    );
}

function pmssWriteManagedFile(string $path, string $content, string $owner, ?string $group, int $mode): bool
{
    return pmssReplaceUserFileWithMetadata($path, $content, $mode, $owner, $group);
}

function pmssWriteUserFile(string $path, string $content, string $owner, int $mode): bool
{
    return pmssWriteManagedFile($path, $content, $owner, $owner, $mode);
}

/**
 * Atomically replace account-owned content as the account itself.
 * Existing entries must be regular files owned by that account. A root-owned
 * managed entry remains on pmssWriteManagedFile(), even when its parent is
 * writable by the account. The parent must already exist.
 */
function pmssReplaceAccountFile(string $username, string $home, string $path, string $content, int $mode): bool
{
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    if (!is_array($account) || !isset($account['uid']) || $mode < 0 || $mode > 0777
        || !pmssUserFilePathIsSafe($path)
        || (file_exists($path) && (@fileowner($path) !== $account['uid'] || @stat($path)['nlink'] !== 1))) {
        return false;
    }

    $directory = dirname($path);
    $temporary = $directory.'/'.basename($path).'.pmss-tmp-XXXXXXXX';
    $command = 'tmp=$(mktemp -- '.escapeshellarg($temporary).') || exit 1; '
        .'trap \'rm -f -- "$tmp"\' EXIT; '
        .'cat > "$tmp" && test "$(wc -c < "$tmp")" -eq '.strlen($content)
        .' && chmod '.sprintf('%o', $mode).' -- "$tmp"'
        .' && test ! -L '.escapeshellarg($path)
        .' && { test ! -e '.escapeshellarg($path).' || test -f '.escapeshellarg($path).'; }'
        .' && mv -T -- "$tmp" '.escapeshellarg($path);
    $replaced = pmssAccountPathRun($username, $home, [$path], $command, $content);
    // The shell replaces the inode outside PHP; invalidate metadata cached for the old path.
    clearstatcache(true, $path);
    return $replaced;
}

/** Keep older ownership layouts writable while normal account files use the account writer. */
function pmssReplaceAccountFileWithLegacyFallback(string $username, string $home, string $path, string $content, int $mode): bool
{
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    if (!is_array($account) || !isset($account['uid'])
        || @fileowner($home) !== $account['uid']
        || @fileowner(dirname($path)) !== $account['uid']
        || (file_exists($path) && @fileowner($path) !== $account['uid'])) {
        return pmssWriteUserFile($path, $content, $username, $mode);
    }
    return pmssReplaceAccountFile($username, $home, $path, $content, $mode);
}

/** Best-effort immutable toggle for managed root-owned files. */
function pmssManagedFileImmutableSet(string $path, bool $enable): void
{
    if (!pmssUserFilePathIsSafe($path) || !pmssRegularFilePathIsReadable($path)) {
        return;
    }

    static $chattr = null;
    if ($chattr === null) {
        $chattr = '';
        foreach (['/usr/bin/chattr', '/bin/chattr'] as $candidate) {
            if (is_executable($candidate)) {
                $chattr = $candidate;
                break;
            }
        }
    }

    $chattr === '' || @exec($chattr.' '.($enable ? '+i' : '-i').' '.escapeshellarg($path).' 2>/dev/null');
}

/**
 * Normalize one serialized-target tuple before any shell-adjacent toggles run.
 *
 * @return array{0:string,1:string,2:int,3:bool}|null
 */
function pmssManagedSerializedTargetNormalize($target): ?array
{
    if (!is_array($target) || count($target) < 4) {
        return null;
    }

    $path = $target[0] ?? null;
    $group = $target[1] ?? null;
    $mode = $target[2] ?? null;
    if (!is_string($path)
        || !is_string($group)
        || (!is_int($mode) && !is_string($mode))
        || !pmssUserFilePathIsSafe($path)
    ) {
        return null;
    }

    // chmod must never receive a mode produced by casting malformed tuple data.
    if (is_string($mode) && preg_match('/^[0-9]+$/D', $mode) !== 1) {
        return null;
    }
    if ((int) $mode < 0 || (int) $mode > 07777) {
        return null;
    }

    return [$path, $group, (int) $mode, (bool) ($target[3] ?? false)];
}

/** Return a printable target label for write failure callbacks. */
function pmssManagedSerializedTargetFailurePath($target): string
{
    $path = is_array($target) ? ($target[0] ?? null) : null;
    return is_string($path) && strpos($path, "\0") === false ? $path : '(invalid target)';
}

/** Write one serialized payload to each managed target while preserving partial success. */
function pmssManagedSerializedTargetsWrite(string $serialized, array $targets, callable $failureLogger): bool
{
    $allWritesSucceeded = true;
    foreach ($targets as $target) {
        $normalized = pmssManagedSerializedTargetNormalize($target);
        if ($normalized === null) {
            $allWritesSucceeded = false;
            $failureLogger(pmssManagedSerializedTargetFailurePath($target));
            continue;
        }

        list($path, $group, $mode, $immutable) = $normalized;
        $immutable && pmssManagedFileImmutableSet($path, false);
        try {
            $ok = pmssWriteManagedFile($path, $serialized, 'root', $group, $mode);
        } finally {
            $immutable && pmssManagedFileImmutableSet($path, true);
        }
        if (!$ok) {
            $allWritesSucceeded = false;
            $failureLogger($path);
        }
    }
    return $allWritesSucceeded;
}

/**
 * Append content to a regular user-owned file when the target path is safe.
 *
 * This keeps legacy append workflows on the same path validation rules as the
 * atomic writer so symlinks and non-regular targets are rejected consistently.
 * A short append reports failure; bytes already appended cannot be rolled back.
 */
function pmssAppendUserFile(string $path, string $content, string $owner, int $mode): bool
{
    if (!pmssUserFilePathIsSafe($path)) {
        return false;
    }

    if (@file_put_contents($path, $content, FILE_APPEND | LOCK_EX) !== strlen($content)) {
        return false;
    }

    pmssUserFileApplyMetadata($path, $owner, $mode);

    return true;
}

/** Append to an account-owned file while holding its append lock. */
function pmssAppendAccountFile(string $username, string $home, string $path, string $content, int $mode): bool
{
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    if (!is_array($account) || !isset($account['uid']) || $mode < 0 || $mode > 0777
        || !pmssUserFilePathIsSafe($path)
        || (file_exists($path) && (!is_file($path) || @fileowner($path) !== $account['uid']
            || @stat($path)['nlink'] !== 1))) {
        return false;
    }

    $target = escapeshellarg($path);
    $command = 'umask 077; test ! -L '.$target
        .' && { test ! -e '.$target.' || test -f '.$target.'; }'
        .' && exec 3>>'.$target.' && flock -x 3 && cat >&3'
        .' && chmod '.sprintf('%o', $mode).' -- '.$target;
    return pmssAccountPathRun($username, $home, [$path], $command, $content);
}
