<?php
/** Create checked managed path segments, then converge the leaf metadata. @license GPL-3.0-only */
function pmssDirectoryManagedEnsure(string $path, int $mode, ?int $wantUid, ?int $wantGid, bool $isRoot, callable $log, int $parentMode): bool
{
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
        try {
            $made = @mkdir($current, $modeForThis);
        } finally {
            // A creation throwable must not leave later writes unmasked.
            umask($oldUmask);
        }
        if (!$made) {
            $log('[WARN] Failed to create directory: '.$current);
            return false;
        }
        $log('[WARN] Created directory: '.$current);
    }

    // Converge leaf perms/ownership when the directory already existed.
    $leafMode = @fileperms($path);
    if ($leafMode !== false && ($leafMode & 0777) !== ($mode & 0777) && @chmod($path, $mode)) {
        // Refresh stat cache so callers observe updated permissions.
        clearstatcache(true, $path);
        $log('[WARN] Updated directory mode: '.$path);
    }

    if ($isRoot && ($wantUid !== null || $wantGid !== null) && is_dir($path)) {
        $changedOwner = false;
        // Read both IDs before either mutation; one successful repair produces one note.
        $currentIds = ['uid' => @fileowner($path), 'gid' => @filegroup($path)];
        foreach (['uid' => $wantUid, 'gid' => $wantGid] as $field => $wanted) {
            if ($wanted !== null && $currentIds[$field] !== false && (int) $currentIds[$field] !== $wanted) {
                $changed = $field === 'uid' ? @chown($path, $wanted) : @chgrp($path, $wanted);
                $changedOwner = $changed || $changedOwner;
            }
        }
        if ($changedOwner) {
            $log('[WARN] Updated directory ownership: '.$path);
        }
    }
    // Avoid noise when no directories or metadata needed repair.
    return is_dir($path);
}
