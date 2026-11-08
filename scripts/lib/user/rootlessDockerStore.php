<?php
/** Rootless Docker storage observation; loaded by rootlessDockerConfig.php. @license GPL-3.0-only */

/** Resolve Docker's data-root; an unsafe configured root returns an empty path. */
function pmssUserRootlessDockerDataRoot(string $home, array $config): string
{
    $configured = $config['data-root'] ?? null;
    if (is_string($configured) && $configured !== '' && $configured[0] === '/' && strpos($configured, "\0") === false) {
        $realHome = realpath($home);
        $realRoot = realpath($configured);
        // Resolve the final component too: a data-root symlink may leave the home.
        return $realHome !== false && $realRoot !== false
            && strpos($realRoot, rtrim($realHome, '/').'/') === 0 ? $configured : '';
    }
    return rtrim($home, '/').'/.local/share/docker';
}

/** Detect a populated Docker store without traversing a symlinked data-root. */
function pmssUserRootlessDockerStoreExists(string $dataRoot): bool
{
    $dataRoot = rtrim($dataRoot, '/') ?: '/';
    if (is_link($dataRoot)) {
        return true;
    }
    if (!is_dir($dataRoot)) {
        return false;
    }
    foreach (['containerd', 'image', 'overlay2', 'fuse-overlayfs', 'vfs', 'containers'] as $entry) {
        $path = $dataRoot.'/'.$entry;
        if (is_link($path)) {
            return true;
        }
        if (is_dir($path)) {
            $contents = pmssDirectoryEntriesRead($path);
            // An unreadable store cannot be treated as empty for driver changes.
            if ($contents === false || $contents !== []) {
                return true;
            }
        }
    }
    return false;
}
