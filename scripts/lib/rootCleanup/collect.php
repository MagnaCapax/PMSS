<?php
/** Read-only root cleanup discovery and byte measurements.
 * @license GPL-3.0-only
 */

/** Recursively collect rotated logs under /var/log older than $olderThanDays. */
function pmssSafeRootCleanupCollectRotatedLogs(int $olderThanDays, int $now): array
{
    $out = [];
    $root = '/var/log';
    if (!is_dir($root)) {
        return $out;
    }
    $cutoff = $now - ($olderThanDays * 86400);
    // SKIP_DOTS only — RecursiveDirectoryIterator does NOT descend into symlinked
    // directories by default, so a /var/log symlink cannot escape the tree; and the
    // per-file realpath+deny-prefix gate is the final backstop regardless.
    // CATCH_GET_CHILD: a permission-denied subdir (e.g. /var/log/private, 0700) must be
    // SKIPPED, not throw — otherwise one unreadable dir aborts the whole cleanup.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $path = $fileInfo->getPathname();
        // atop handled by its own class; skip here to avoid double-count.
        if (strncmp($path, '/var/log/atop/', 14) === 0
            || !pmssSafeRootCleanupIsRotatedLog($path)
            || $fileInfo->getMTime() > $cutoff
            || !pmssSafeRootCleanupIsDeletableFile($path)) {
            continue;
        }
        $out[$path] = $fileInfo->getSize();
    }
    return $out;
}

/** Collect old atop logs (/var/log/atop/atop_* older than N days). */
function pmssSafeRootCleanupCollectAtopLogs(int $olderThanDays, int $now): array
{
    $out = [];
    $cutoff = $now - ($olderThanDays * 86400);
    foreach (glob('/var/log/atop/atop_*') ?: [] as $path) {
        if (!is_file($path) || filemtime($path) > $cutoff
            || strncmp((string) realpath($path), '/var/log/atop/atop_', 19) !== 0) {
            continue;
        }
        $out[$path] = filesize($path);
    }
    return $out;
}

/** Collect dist-upgrade /var/tmp/mkinitramfs_* scratch (dirs and files). */
function pmssSafeRootCleanupCollectDistUpgradeTmp(): array
{
    $out = [];
    foreach (glob('/var/tmp/mkinitramfs_*') ?: [] as $path) {
        if (!pmssSafeRootCleanupIsDistUpgradeTmp($path)) {
            continue;
        }
        $out[$path] = pmssSafeRootCleanupPathBytes($path);
    }
    return $out;
}

/** Bytes on disk for a file or directory (du -sb; bounded to a single path). */
function pmssSafeRootCleanupPathBytes(string $path): int
{
    if (is_file($path)) {
        return (int) filesize($path);
    }
    if (!is_dir($path)) {
        return 0;
    }
    $out = @shell_exec('du -sb ' . escapeshellarg($path) . ' 2>/dev/null');
    return (int) trim(explode("\t", (string) $out)[0]);
}
