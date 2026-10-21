<?php
/**
 * Per-user directory helpers (idempotent; fail-soft).
 *
 * Goal: converge small, PMSS-managed directories under user homes when they go
 * missing (accidental deletion), without relying on shell one-liners.
 *
 * These helpers are intentionally conservative:
 * - refuse symlinks (avoid operating on unexpected destinations)
 * - create only directories (fail when a non-directory path exists)
 * - repair account-owned paths as that account; keep root on root-owned paths
 * - log only when changes were required
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/pathSafety.php';
require_once dirname(__DIR__).'/lighttpd/accountPath.php';

/** Create and repair home directories with the account's own authority. */
function pmssAccountDirectoryEnsure(
    string $user, string $home, string $path, int $mode, int $parentMode, ?callable $logger = null
): bool
{
    $home = rtrim($home, '/');
    if ($mode < 0 || $mode > 0777 || $parentMode < 0 || $parentMode > 0777
        || !pmssPathTargetIsSafe($home, true) || !is_dir($home)
        || ($path !== $home && strpos($path, $home.'/') !== 0)
        || !pmssPathSegmentsAreSafe($path, false, true)) {
        return false;
    }
    if ($path === $home) {
        $target = escapeshellarg($home);
        $command = 'test ! -L '.$target
            .' && chmod '.sprintf('%04o', $mode).' -- '.$target;
        return pmssAccountPathRun($user, $home, [$home.'/.pmss-directory-check'], $command);
    }
    $relative = substr($path, strlen($home) + 1);
    $parts = explode('/', $relative);
    $current = $home;
    foreach ($parts as $index => $part) {
        if ($part === '') return false;
        $current .= '/'.$part;
        clearstatcache(true, $current);
        $before = @lstat($current);
        if (is_link($current) || (file_exists($current) && !is_dir($current))) return false;
        $wanted = $index === count($parts) - 1 ? $mode : $parentMode;
        $target = escapeshellarg($current);
        $command = 'test ! -L '.$target.' && { test -d '.$target
            .' || mkdir -m '.sprintf('%04o', $wanted).' -- '.$target.'; }';
        if ($index === count($parts) - 1) {
            $command .= ' && chmod '.sprintf('%04o', $wanted).' -- '.$target;
        }
        if (!pmssAccountPathRun($user, $home, [$current], $command)) {
            return false;
        }
        if ($logger !== null && !is_array($before)) $logger('[WARN] Created directory: '.$current);
        if ($logger !== null && is_array($before) && $index === count($parts) - 1
            && (((int) $before['mode']) & 0777) !== $wanted) {
            $logger('[WARN] Updated directory mode: '.$current);
        }
    }
    clearstatcache(true, $path);
    return is_dir($path) && !is_link($path);
}

if (!function_exists('pmssEnsureDir')) {
    /**
     * Ensure a directory exists with desired ownership and mode.
     *
     * @param string        $path       Absolute path to the directory.
     * @param int           $mode       Desired mode for the leaf directory.
     * @param string|null   $ownerUser  Expected owner username.
     * @param string|null   $ownerGroup Expected group for root-owned paths.
     * @param callable|null $logger     Optional logger callback: function(string $message): void
     * @param int           $parentMode Mode for newly created parent directories (defaults to 0755).
     */
    function pmssEnsureDir(
        string $path,
        int $mode,
        ?string $ownerUser = null,
        ?string $ownerGroup = null,
        ?callable $logger = null,
        int $parentMode = 0755
    ): bool {
        $log = $logger ?: function (string $_msg): void { };

        $path = rtrim($path, '/');
        if ($path === '' || $path[0] !== '/') {
            $log('[WARN] Refusing to ensure non-absolute dir path: '.$path);
            return false;
        }
        if (strpos($path, "\0") !== false) {
            $log('[WARN] Refusing to ensure dir with null byte');
            return false;
        }
        if (is_link($path)) {
            $log('[WARN] Refusing to ensure symlinked dir: '.$path);
            return false;
        }
        if (file_exists($path) && !is_dir($path)) {
            $log('[WARN] Refusing to ensure dir; path exists and is not a directory: '.$path);
            return false;
        }

        $wantUid = null;
        $wantGid = null;
        if ($ownerUser !== null && function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($ownerUser);
            if (is_array($pw) && isset($pw['uid'], $pw['gid'])) {
                $wantUid = (int)$pw['uid'];
                $wantGid = (int)$pw['gid'];
            }
        }
        if ($ownerGroup !== null && function_exists('posix_getgrnam')) {
            $gr = @posix_getgrnam($ownerGroup);
            if (is_array($gr) && isset($gr['gid'])) {
                $wantGid = (int)$gr['gid'];
            }
        }

        $isRoot = function_exists('posix_geteuid') ? (posix_geteuid() === 0) : false;
        // The first account-owned ancestor is the authority boundary. Never
        // repair a child of that ancestor with the root path operations below.
        if ($isRoot) {
            $ancestor = $path;
            while ($ancestor !== '/') {
                $stat = @lstat($ancestor);
                if (is_link($ancestor)) return false;
                if (is_array($stat) && (int) $stat['uid'] !== 0) {
                    if (($wantUid !== null && $wantUid !== (int) $stat['uid'])
                        || !function_exists('posix_getpwuid')
                        || !is_array($account = @posix_getpwuid((int) $stat['uid']))) return false;
                    return pmssAccountDirectoryEnsure($account['name'], $ancestor, $path, $mode, $parentMode, $logger);
                }
                $ancestor = dirname($ancestor);
            }
            if ($wantUid !== null && $wantUid !== 0) return false;
        }

        // mkdir -p equivalent with per-segment modes (parents vs leaf).
        $segments = explode('/', ltrim($path, '/'));
        $current = '';
        $lastIndex = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= '/'.$segment;
            if (is_link($current)) {
                $log('[WARN] Refusing to ensure dir; parent is a symlink: '.$current);
                return false;
            }
            $existing = @lstat($current);
            if ($isRoot && is_array($existing)
                && ((int) $existing['uid'] !== 0 || (((int) $existing['mode']) & 0022) !== 0)) {
                return false;
            }
            if (is_dir($current)) {
                continue;
            }
            if (file_exists($current)) {
                $log('[WARN] Refusing to ensure dir; parent path exists and is not a directory: '.$current);
                return false;
            }

            $modeForThis = ($i === $lastIndex) ? $mode : $parentMode;
            if ($isRoot && $wantUid !== null && $wantUid !== 0) return false;
            $oldUmask = umask(0);
            $made = @mkdir($current, $modeForThis);
            umask($oldUmask);
            if (!$made) {
                $log('[WARN] Failed to create directory: '.$current);
                return false;
            }
            $log('[WARN] Created directory: '.$current);
        }

        // Converge leaf perms/ownership when the directory already existed.
        $leafMode = @fileperms($path);
        if ($leafMode !== false) {
            $leafMode = $leafMode & 0777;
            if ($leafMode !== ($mode & 0777)) {
                if (@chmod($path, $mode)) {
                    // Refresh stat cache so callers observe updated permissions.
                    clearstatcache(true, $path);
                    $log('[WARN] Updated directory mode: '.$path);
                }
            }
        }

        if ($isRoot && ($wantUid !== null || $wantGid !== null) && is_dir($path)) {
            $changedOwner = false;
            $curUid = @fileowner($path);
            $curGid = @filegroup($path);
            if ($wantUid !== null && $curUid !== false && (int)$curUid !== $wantUid) {
                if (@chown($path, $wantUid)) {
                    $changedOwner = true;
                }
            }
            if ($wantGid !== null && $curGid !== false && (int)$curGid !== $wantGid) {
                if (@chgrp($path, $wantGid)) {
                    $changedOwner = true;
                }
            }
            if ($changedOwner) {
                $log('[WARN] Updated directory ownership: '.$path);
            }
        }

        // Avoid noise: if nothing was created and no perms/ownership changed, pmssEnsureDir is silent.
        return is_dir($path);
    }
}

if (!function_exists('pmssEnsureUserHomeDir')) {
    /**
     * Ensure a directory under a user home (guards against path traversal).
     *
     * @param string        $user       Username (used for ownership when running as root).
     * @param string        $home       Absolute home directory path.
     * @param string        $relative   Path under $home, e.g. '.tmp' or 'www/recycle'.
     * @param int           $mode       Leaf mode.
     * @param callable|null $logger     Optional logger callback.
     * @param int           $parentMode Mode for newly created parent directories.
     */
    function pmssEnsureUserHomeDir(
        string $user,
        string $home,
        string $relative,
        int $mode,
        ?callable $logger = null,
        int $parentMode = 0755
    ): bool {
        $log = $logger ?: function (string $_msg): void { };

        $home = rtrim($home, '/');
        $safeHome = str_replace(["\0", "\r", "\n"], '?', $home);
        if (!pmssPathAbsoluteStringIsSafe($home, ['allowRoot' => false]) || !is_dir($home) || is_link($home)) {
            $log('[WARN] Refusing to ensure user dir; invalid home path: '.$safeHome);
            return false;
        }
        $relative = trim($relative);
        if (!pmssPathRelativeStringIsSafe($relative, ['allowEmptySegments' => true, 'allowDotSegments' => true, 'allowControlChars' => true])) {
            $log('[WARN] Refusing to ensure user dir; invalid relative path');
            return false;
        }

        return pmssAccountDirectoryEnsure($user, $home, $home.'/'.$relative, $mode, $parentMode, $logger);
    }
}
