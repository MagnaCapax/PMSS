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

/** A /proc/self/fd/N path names an inode this process already holds open. */
function pmssUserFileOpenedPathIs(string $path): bool { return preg_match('#\A/proc/self/fd/[0-9]+\z#', $path) === 1; }

/**
 * Set owner and group on one managed entry. Real paths use lchown/lchgrp so a
 * planted link is never followed. An opened-inode path must be followed:
 * lchown on the procfs link would change only that link, not the file.
 */
function pmssUserFileOwnershipSet(string $path, $owner, $group): bool
{
    if (pmssUserFileOpenedPathIs($path)) {
        $ownerSet = @chown($path, $owner);
        return @chgrp($path, $group) && $ownerSet;
    }
    $ownerSet = @lchown($path, $owner);
    return @lchgrp($path, $group) && $ownerSet;
}

/** Apply owner/group metadata only when the caller is root. */
function pmssUserFileApplyOwnership(string $path, string $owner, ?string $group = null): void
{
    if (pmssManagedAccountHomePath($path)) {
        $entry = @lstat($path);
        if (is_array($entry)) pmssUserFileApplyMetadata($path, $owner, $entry['mode'] & 0777, $group);
        return;
    }
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        pmssUserFileOwnershipSet($path, $owner, ($group === null || $group === '') ? $owner : $group);
    }
}

/**
 * Apply file mode plus root-only ownership metadata to a managed user path.
 * Account-home paths retain their account/root-stage routing (ADR 0121).
 * Other real paths must be single-link regular files; metadata changes use
 * the opened inode so later name changes cannot redirect chmod/chown.
 */
function pmssUserFileApplyMetadata(string $path, string $owner, int $mode, ?string $group = null): void
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $entry = @lstat($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity === null || !pmssUserFileStatIsSingleRegular($entry) || $mode < 0 || $mode > 0777) {
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
    if (pmssUserFileOpenedPathIs($path)) {
        @chmod($path, $mode);
        pmssUserFileApplyOwnership($path, $owner, $group);
        return;
    }

    $handle = pmssUserFileOpenVerified($path, 'rbn');
    if ($handle === null) {
        return;
    }
    try {
        $openedPath = pmssUserFileHandleProcPath($handle);
        $openedPath === null || pmssUserFileApplyMetadata($openedPath, $owner, $mode, $group);
    } finally {
        @fclose($handle);
    }
}

/** True for lstat/fstat data describing a regular file with exactly one name. */
function pmssUserFileStatIsSingleRegular($stat): bool
{
    return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0100000 && ($stat['nlink'] ?? 0) === 1;
}

/** Confirm a directory entry is the single-link regular file behind an open handle. */
function pmssUserFileHandleMatchesEntry($handle, string $path): bool
{
    clearstatcache(true, $path);
    $entry = @lstat($path);
    return pmssUserFileStatIsSingleRegular($entry)
        && pmssLockFileHandleMatchesPath($handle, $path, $entry, $opened)
        && pmssUserFileStatIsSingleRegular($opened);
}

/**
 * Open an existing single-link regular file and prove the handle is that entry.
 * PHP resolves links in user space before open(2) and may reuse its realpath
 * cache, so the post-open comparison, not the pre-open lstat, is the guarantee.
 * The mode must not create files; 'n' keeps a swapped-in FIFO from blocking.
 *
 * @return resource|null
 */
function pmssUserFileOpenVerified(string $path, string $mode)
{
    clearstatcache(true);
    if (!pmssUserFilePathIsSafe($path) || !pmssUserFileStatIsSingleRegular(@lstat($path))) {
        return null;
    }
    $handle = @fopen($path, $mode);
    if (!pmssStreamHandleIsOpen($handle)) {
        return null;
    }
    if (!pmssUserFileHandleMatchesEntry($handle, $path)) {
        @fclose($handle);
        return null;
    }
    return $handle;
}

/**
 * Return /proc/self/fd/N for an open handle, found by matching device and inode.
 * Metadata calls on that path act on the opened inode whatever its name now is.
 */
function pmssUserFileHandleProcPath($handle): ?string
{
    $opened = pmssStreamHandleIsOpen($handle) ? @fstat($handle) : false;
    $entries = pmssDirectoryEntriesRead('/proc/self/fd');
    if (!is_array($opened) || !is_array($entries)) {
        return null;
    }
    foreach ($entries as $entry) {
        $candidate = '/proc/self/fd/'.$entry;
        clearstatcache(true, $candidate);
        $stat = ctype_digit((string) $entry) ? @stat($candidate) : false;
        if (is_array($stat) && $stat['dev'] === $opened['dev'] && $stat['ino'] === $opened['ino']) {
            return $candidate;
        }
    }
    return null;
}

/** Write the whole payload through an open handle; any failed or short write reports false. */
function pmssUserFileHandleWriteComplete($handle, string $content): bool
{
    return ($content === '' || @fwrite($handle, $content) === strlen($content)) && @fflush($handle);
}

/**
 * Create a private staging file beside $path under a random name.
 * The handle is returned only when the new name is a single-link regular file
 * that is the opened inode; $tmp receives that name.
 *
 * @return resource|null
 */
function pmssUserFileTempCreate(string $path, ?string &$tmp)
{
    $tmp = null;
    // Same bound tempnam() applied to prefixes, so long basenames stay valid names.
    $prefix = dirname($path).'/'.substr(basename($path).'.pmss-tmp-', 0, 63);
    for ($attempt = 0; $attempt < 4; $attempt++) {
        try {
            $candidate = $prefix.bin2hex(random_bytes(6));
        } catch (\Throwable $error) {
            return null;
        }
        clearstatcache(true);
        $umask = umask(0077);
        try {
            $handle = @fopen($candidate, 'xb');
        } finally {
            umask($umask);
        }
        if (!pmssStreamHandleIsOpen($handle)) {
            clearstatcache(true, $candidate);
            if (pmssPathExistsOrLink($candidate)) {
                continue;
            }
            return null;
        }
        if (pmssUserFileHandleMatchesEntry($handle, $candidate)) {
            $tmp = $candidate;
            return $handle;
        }
        // The open did not land on the entry just named; that entry is not ours to remove.
        @fclose($handle);
        return null;
    }
    return null;
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
 * The directory may be writable by the account, which can rename or replace
 * the staging name at any moment. Content is therefore written through the
 * open handle, and $prepareTemp receives /proc/self/fd/N for that handle, not
 * the staging name, so its chmod/chown calls reach only the staged inode.
 */
function pmssReplaceUserFile(string $path, string $content, ?callable $prepareTemp = null): bool
{
    if (pmssManagedAccountHomePath($path) || !pmssUserFilePathIsSafe($path)) {
        return false;
    }

    $handle = pmssUserFileTempCreate($path, $tmp);
    if ($handle === null) {
        return false;
    }

    try {
        if (!pmssUserFileHandleWriteComplete($handle, $content)) {
            return false;
        }
        if ($prepareTemp !== null) {
            $openedPath = pmssUserFileHandleProcPath($handle);
            if ($openedPath === null || $prepareTemp($openedPath) === false) {
                return false;
            }
        }
        // Publish only the staged inode: the name must still be that single-link file.
        if (!pmssUserFileHandleMatchesEntry($handle, $tmp) || !pmssUserFilePathIsSafe($path) || !@rename($tmp, $path)) {
            return false;
        }

        $tmp = null;
        return true;
    } finally {
        // Failed operations and thrown I/O errors must not strand staging files.
        @fclose($handle);
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

    return pmssUserFileOwnershipSet($path, $owner, ($group === null || $group === '') ? $owner : $group);
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
        // Owner and group are captured together; numeric ids from stat() stay numeric.
        return pmssUserFilePrepareMetadata($tmpPath, $mode, null, null)
            && ($owner === null || pmssUserFileOwnershipSet($tmpPath, $owner, $group));
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
 *
 * A missing file is staged under a random name and published by rename: PHP
 * resolves a link planted at the predictable name before open(2), so creating
 * it in place could create a file elsewhere. An existing file must be a
 * single-link regular file and is written and chmodded through its handle.
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

    clearstatcache(true, $path);
    if (!pmssPathExistsOrLink($path) && pmssReplaceUserFile($path, $content,
        static function (string $openedPath) use ($path, $owner, $mode): bool {
            pmssUserFileApplyMetadata($openedPath, $owner, $mode);
            // If another writer has already published, append to its file below.
            clearstatcache(true, $path);
            return !pmssPathExistsOrLink($path);
        })) {
        return true;
    }

    // 'r+' never creates, so a swapped-in dangling link cannot make a new file.
    $handle = pmssUserFileOpenVerified($path, 'r+bn');
    if ($handle === null) {
        return false;
    }
    try {
        if (!@flock($handle, LOCK_EX) || !pmssUserFileHandleMatchesEntry($handle, $path)
            || @fseek($handle, 0, SEEK_END) !== 0
            || !pmssUserFileHandleWriteComplete($handle, $content)) {
            return false;
        }
        $openedPath = pmssUserFileHandleProcPath($handle);
        $openedPath === null || pmssUserFileApplyMetadata($openedPath, $owner, $mode);
        return true;
    } finally {
        @fclose($handle);
    }
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
