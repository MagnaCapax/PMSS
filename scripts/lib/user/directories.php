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
 * - apply chmod + (when running as root) chown/chgrp on the leaf directory
 * - log only when changes were required
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/pathSafety.php';

if (!function_exists('pmssEnsureDir')) {
    /**
     * Ensure a directory exists with desired ownership and mode.
     *
     * @param string        $path       Absolute path to the directory.
     * @param int           $mode       Desired mode for the leaf directory.
     * @param string|null   $ownerUser  Owner username (best-effort; only applied when running as root).
     * @param string|null   $ownerGroup Owner group (best-effort; only applied when running as root).
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

        // mkdir -p equivalent with per-segment modes (parents vs leaf).
        $createdAny = false;
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
            if (is_dir($current)) {
                continue;
            }
            if (file_exists($current)) {
                $log('[WARN] Refusing to ensure dir; parent path exists and is not a directory: '.$current);
                return false;
            }

            $modeForThis = ($i === $lastIndex) ? $mode : $parentMode;
            if (!@mkdir($current, $modeForThis)) {
                $log('[WARN] Failed to create directory: '.$current);
                return false;
            }
            $createdAny = true;
            @chmod($current, $modeForThis);
            if ($isRoot) {
                if ($wantUid !== null) {
                    @chown($current, $wantUid);
                }
                if ($wantGid !== null) {
                    @chgrp($current, $wantGid);
                }
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
        if ($home === '' || $home[0] !== '/' || strpos($home, "\0") !== false) {
            $log('[WARN] Refusing to ensure user dir; invalid home path: '.$safeHome);
            return false;
        }
        foreach (explode('/', trim($home, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                $log('[WARN] Refusing to ensure user dir; unsafe home path segment: '.$safeHome);
                return false;
            }
        }
        if (!is_dir($home) || is_link($home)) {
            $log('[WARN] Refusing to ensure user dir; invalid home path: '.$safeHome);
            return false;
        }
        $relative = trim($relative);
        if (!pmssPathRelativeStringIsSafe($relative, ['allowEmptySegments' => true, 'allowDotSegments' => true, 'allowControlChars' => true])) {
            $log('[WARN] Refusing to ensure user dir; invalid relative path');
            return false;
        }

        $path = $home.'/'.$relative;
        if (strpos($path, $home.'/') !== 0) {
            $log('[WARN] Refusing to ensure user dir; path prefix mismatch');
            return false;
        }

        return pmssEnsureDir($path, $mode, $user, $user, $logger, $parentMode);
    }
}
