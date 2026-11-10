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

require_once __DIR__.'/directoryAccount.php';
require_once __DIR__.'/directoryManaged.php';

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
        if ($ownerUser !== null && function_exists('posix_getpwnam')
            && is_array($pw = @posix_getpwnam($ownerUser)) && isset($pw['uid'], $pw['gid'])) {
            $wantUid = (int)$pw['uid'];
            $wantGid = (int)$pw['gid'];
        }
        if ($ownerGroup !== null && function_exists('posix_getgrnam')
            && is_array($gr = @posix_getgrnam($ownerGroup)) && isset($gr['gid'])) {
            $wantGid = (int)$gr['gid'];
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

        return pmssDirectoryManagedEnsure($path, $mode, $wantUid, $wantGid, $isRoot, $log, $parentMode);
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
