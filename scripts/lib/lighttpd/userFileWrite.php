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
require_once __DIR__.'/rootStage.php';

/** Lexical account-home boundary used to reject paths that cannot be classified. */
function pmssManagedAccountHomePath(string $path): bool
{
    $root = rtrim((string) (getenv('PMSS_HOME_DIR') ?: '/home'), '/');
    if (strpos($path, $root.'/') !== 0) return false;
    // The account writer and root stage keep root out of account-controlled trees.
    // A non-root caller has no privilege to protect: it writes with its own UID,
    // including inside its own home, where it cannot publish root-owned files.
    return !function_exists('posix_geteuid') || @posix_geteuid() === 0;
}

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
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity !== null && @posix_geteuid() === $identity[2]) {
            require_once __DIR__.'/../user/directories.php';
            return pmssAccountDirectoryEnsure($identity[1], $identity[0], $path, $mode, 0755);
        }
        if ($context === null) return false;
        require_once __DIR__.'/../user/directories.php';
        return pmssAccountDirectoryEnsure($context[2], $context[1], $path, $mode, 0755);
    }
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
    if (pmssManagedAccountHomePath($path)) {
        $entry = @lstat($path);
        if (is_array($entry)) pmssUserFileApplyMetadata($path, $owner, $entry['mode'] & 0777, $group);
        return;
    }
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @lchown($path, $owner);
        @lchgrp($path, ($group === null || $group === '') ? $owner : $group);
    }
}

/** Apply file mode plus root-only ownership metadata to a managed user path. */
function pmssUserFileApplyMetadata(string $path, string $owner, int $mode, ?string $group = null): void
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $entry = @lstat($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity === null || !is_array($entry) || $mode < 0 || $mode > 0777) {
            error_log("Unable to update managed metadata: {$path}");
            return;
        }
        list($home, $user) = $identity;
        if ($owner === $user) {
            $account = @posix_getpwnam($user);
            if (!is_array($account) || $entry['uid'] !== $account['uid']) return;
            $target = escapeshellarg($path);
            $command = 'test ! -L '.$target.' && chmod '.sprintf('%o', $mode).' -- '.$target;
            if (!pmssAccountPathRun($user, $home, [$path], $command)) {
                error_log("Unable to update account metadata for {$user}: {$path}");
            }
            return;
        }
        if ($context !== null && $owner === 'root' && $entry['uid'] === 0 && ($entry['mode'] & 0170000) === 0100000) {
            $content = pmssReadRegularFileContents($path);
            if (is_string($content) && pmssRootStageFileWrite($path, $content, $group ?: 'root', $mode)) return;
        }
        error_log("Unable to update managed metadata: {$path}");
        return;
    }
    @chmod($path, $mode);
    pmssUserFileApplyOwnership($path, $owner, $group);
}

/** Converge an account file's mode as its owner; older ownership is repaired by the managed path. */
function pmssAccountFileApplyMetadata(string $username, string $home, string $path, int $mode): void
{
    if ($mode < 0 || $mode > 0777 || !pmssUserFilePathIsSafe($path) || !is_file($path)) {
        error_log("Unable to update account file mode for {$username}: {$path}");
        return;
    }
    $account = pmssAccountPathIdentity($username, $home);
    if (is_array($account) && @fileowner($path) === $account['uid']
        && pmssAccountPathRun($username, $home, [$path],
            'find '.escapeshellarg($path).' -maxdepth 0 -type f -links 1 -exec chmod '.sprintf('%o', $mode).' {} +')) {
        return;
    }
    error_log("Unable to update account file mode for {$username}: {$path}");
}

/**
 * Atomically replace a regular file, with optional temp-file preparation.
 * Incomplete writes leave the destination untouched and remove the temp file.
 */
function pmssReplaceUserFile(string $path, string $content, ?callable $prepareTemp = null): bool
{
    if (pmssManagedAccountHomePath($path) || !pmssUserFilePathIsSafe($path)) {
        return false;
    }

    $tmp = @tempnam(dirname($path), basename($path).'.pmss-tmp-');
    if (!is_string($tmp) || !pmssRegularFilePathIsReadable($tmp)) {
        if (is_string($tmp)) @unlink($tmp);
        return false;
    }

    try {
        if (!pmssFileWriteComplete($tmp, $content)) {
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

/** Prepare replacement metadata before publication; failed permissions leave the old file intact. */
function pmssUserFilePrepareMetadata(string $path, int $mode, ?string $owner, ?string $group): bool
{
    if ($mode < 0 || $mode > 07777 || !@chmod($path, $mode)) {
        return false;
    }
    if ($owner === null || !function_exists('posix_geteuid') || @posix_geteuid() !== 0) {
        return true;
    }

    return @lchown($path, $owner) && @lchgrp($path, ($group === null || $group === '') ? $owner : $group);
}

function pmssReplaceUserFileWithMetadata(string $path, string $content, int $mode, ?string $owner = null, ?string $group = null): bool
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity === null) return false;
        list($home, $user) = $identity;
        if ($owner === 'root' || $owner === 0 || $owner === null) {
            if ($context === null) return false;
            return pmssRootStageFileWrite($path, $content, $group === null ? 'root' : $group, $mode);
        }
        if ($owner === $user && ($context !== null || @posix_geteuid() === $identity[2])) {
            return pmssReplaceAccountFile($user, $home, $path, $content, $mode, true);
        }
        return false;
    }
    return pmssReplaceUserFile($path, $content, static function (string $tmpPath) use ($mode, $owner, $group): bool {
        return pmssUserFilePrepareMetadata($tmpPath, $mode, $owner, $group);
    });
}

/** Replace a file while keeping existing mode/uid/gid when available. */
function pmssReplaceUserFilePreservingMetadata(string $path, string $content, int $fallbackMode = 0644): bool
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity === null) return false;
        list($home, $user) = $identity;
        $entry = @lstat($path);
        if (is_array($entry) && (($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1)) return false;
        $mode = is_array($entry) ? $entry['mode'] & 0777 : $fallbackMode;
        $account = @posix_getpwnam($user);
        if (!is_array($account)) return false;
        if (is_array($entry) && $entry['uid'] === 0) {
            if ($context === null) return false;
            $group = @posix_getgrgid($entry['gid']);
            return is_array($group) && pmssRootStageFileWrite($path, $content, $group['name'], $mode);
        }
        if (is_array($entry) && $entry['uid'] !== $account['uid']) return false;
        if ($context === null && @posix_geteuid() !== $identity[2]) return false;
        return pmssReplaceAccountFile($user, $home, $path, $content, $mode, true);
    }
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

    return pmssReplaceUserFile($path, $content, static function (string $tmpPath) use ($group, $mode, $owner): bool {
        if (!pmssUserFilePrepareMetadata($tmpPath, $mode, null, null)) {
            return false;
        }
        return ($owner === null || @chown($tmpPath, $owner))
            && ($group === null || @chgrp($tmpPath, $group));
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
    if ($mode === null && pmssManagedAccountHomePath($path)) {
        return pmssRootStageFileWrite($path, $content, 'root', 0600);
    }
    return $mode === null ? pmssReplaceUserFile($path, $content) : pmssReplaceUserFileWithMetadata($path, $content, $mode);
}

/** Encode and atomically publish one newline-terminated pretty JSON document. */
function pmssAtomicJsonFileWrite(string $path, array $payload, int $mode): bool
{
    $encoded = pmssJsonEncodePrettyLine($payload);
    if (pmssManagedAccountHomePath($path)) {
        return is_string($encoded) && pmssReplaceUserFileWithMetadata($path, $encoded, $mode);
    }
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
 * Existing entries must be regular files owned by that account. The optional
 * legacy repair accepts a root-owned file only beneath an account-owned parent;
 * the account replaces it through that parent. Other managed entries remain
 * on pmssWriteManagedFile(). The parent must already exist.
 */
function pmssReplaceAccountFile(
    string $username, string $home, string $path, string $content, int $mode, bool $replaceLegacyOwned = false
): bool
{
    $account = pmssAccountPathIdentity($username, $home);
    $existing = @lstat($path);
    $legacyOwner = $replaceLegacyOwned && is_array($account) && isset($account['uid']) && is_array($existing)
        && (int) $existing['uid'] === 0 && @fileowner(dirname($path)) === $account['uid'];
    if (!is_array($account) || !isset($account['uid']) || $mode < 0 || $mode > 0777
        || !pmssUserFilePathIsSafe($path)
        || (is_array($existing) && ((($existing['mode'] & 0170000) !== 0100000)
            || ($existing['uid'] !== $account['uid'] && !$legacyOwner)
            || $existing['nlink'] !== 1))) {
        error_log("Unable to replace account file for {$username}: {$path}");
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
    if (!$replaced) error_log("Unable to replace account file for {$username}: {$path}");
    // The shell replaces the inode outside PHP; invalidate metadata cached for the old path.
    clearstatcache(true, $path);
    return $replaced;
}

/** Keep older ownership layouts writable while normal account files use the account writer. */
function pmssReplaceAccountFileWithLegacyFallback(string $username, string $home, string $path, string $content, int $mode): bool
{
    $account = pmssAccountPathIdentity($username, $home);
    if (!is_array($account) || !isset($account['uid'])
        || @fileowner($home) !== $account['uid']) {
        error_log("Unable to replace account file for {$username}: {$path}");
        return false;
    }
    return pmssReplaceAccountFile($username, $home, $path, $content, $mode, true);
}

/** Best-effort immutable toggle for managed root-owned files. */
function pmssManagedFileImmutableSet(string $path, bool $enable): void
{
    if (pmssManagedAccountHomePath($path)) {
        $entry = @lstat($path);
        if (!is_array($entry) || !pmssRootStageImmutableSet($path, $entry, $enable)) {
            error_log("Unable to update managed immutable state: {$path}");
        }
        return;
    }
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
        if (pmssManagedAccountHomePath($path)) {
            $ok = pmssRootStageFileWrite($path, $serialized, $group, $mode, $immutable);
            if (!$ok) {
                $allWritesSucceeded = false;
                $failureLogger($path);
            }
            continue;
        }
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
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        return $context !== null && $owner === $context[2]
            && pmssAppendAccountFile($owner, $context[1], $path, $content, $mode);
    }
    if (!pmssUserFilePathIsSafe($path)) {
        return false;
    }

    if (!pmssFileWriteComplete($path, $content, FILE_APPEND | LOCK_EX)) {
        return false;
    }

    pmssUserFileApplyMetadata($path, $owner, $mode);

    return true;
}

/** Append to an account-owned file while holding its append lock. */
function pmssAppendAccountFile(string $username, string $home, string $path, string $content, int $mode, int $timeoutSec = 0, ?bool &$timedOut = null): bool
{
    $timedOut = false;
    $account = pmssAccountPathIdentity($username, $home);
    if (!is_array($account) || !isset($account['uid']) || $mode < 0 || $mode > 0777
        || !pmssUserFilePathIsSafe($path)
        || (file_exists($path) && (!is_file($path) || @fileowner($path) !== $account['uid']
            || @stat($path)['nlink'] !== 1))) {
        return false;
    }

    $target = escapeshellarg($path);
    $command = 'umask 077; test ! -L '.$target
        .' && { test ! -e '.$target.' || test -f '.$target.'; }'
        .' && exec 3>>'.$target.' && flock -w 10 -x 3 && cat >&3'
        .' && chmod '.sprintf('%o', $mode).' -- '.$target;
    return pmssAccountPathRun($username, $home, [$path], $command, $content, $timeoutSec, $timedOut);
}
