<?php
/**
 * Shared filesystem helpers for hermetic PMSS test harnesses.
 *
 * @license GPL-3.0-only
 */

/** Ensure a fixture directory exists with the requested mode. */
function pmssTestingEnsureDirectory(string $path, int $mode = 0700): bool
{
    return is_dir($path) || @mkdir($path, $mode, true);
}

/** Remove a temporary tree created by tests without shelling out. */
function pmssTestingRemoveTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
            continue;
        }
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }

    @rmdir($path);
}

/** Copy a fixture tree, preserving directories, regular files, and symlinks. */
function pmssTestingCopyTree(string $source, string $destination, int $dirMode = 0700): bool
{
    if (!is_dir($source)) {
        return false;
    }

    $sourceRoot = rtrim($source, '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $sourcePath = $item->getPathname();
        $target = rtrim($destination, '/').'/'.substr($sourcePath, strlen($sourceRoot) + 1);
        if ($item->isLink()) {
            $linkTarget = readlink($sourcePath);
            if (
                !pmssTestingEnsureDirectory(dirname($target), $dirMode)
                || !is_string($linkTarget)
                || !@symlink($linkTarget, $target)
            ) {
                return false;
            }
            continue;
        }
        if ($item->isDir()) {
            if (!pmssTestingEnsureDirectory($target, $dirMode)) {
                return false;
            }
            continue;
        }
        if (!pmssTestingEnsureDirectory(dirname($target), $dirMode) || !@copy($sourcePath, $target)) {
            return false;
        }
    }

    return true;
}
