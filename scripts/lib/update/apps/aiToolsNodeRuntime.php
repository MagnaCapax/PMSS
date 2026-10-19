<?php
/** Ownership and cleanup helpers for the bundled AI tools Node.js runtime. */

/** Check each directory entry itself; symlink targets are never inspected. */
function pmssAiToolsNodeTreeNeedsRefresh(string $dir, int $expectedUid = 0): bool
{
    $root = @lstat($dir);
    if ($root === false) {
        return false;
    }
    if ($root['uid'] !== $expectedUid || ($root['mode'] & 0170000) !== 0040000) {
        return true;
    }

    try {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($entries as $entry) {
            $stat = @lstat($entry->getPathname());
            if ($stat === false || $stat['uid'] !== $expectedUid) {
                return true;
            }
        }
    } catch (UnexpectedValueException $exception) {
        return true;
    }
    return false;
}

/** Move a managed runtime tree aside, then remove it with rm. */
function pmssAiToolsNodeTreeRemove(string $path): bool
{
    $root = @lstat($path);
    if ($root === false) {
        return true;
    }
    if (($root['mode'] & 0170000) !== 0040000) {
        return @unlink($path);
    }

    try {
        $stage = dirname($path).'/.pmss-remove-'.bin2hex(random_bytes(8));
    } catch (Exception $exception) {
        return false;
    }
    if (!@mkdir($stage, 0700)) {
        return false;
    }

    clearstatcache(true, $stage);
    $stageStat = @lstat($stage);
    if ($stageStat === false || ($stageStat['mode'] & 0170000) !== 0040000
        || !function_exists('posix_geteuid') || $stageStat['uid'] !== posix_geteuid()
        || ($stageStat['mode'] & 07777) !== 0700) {
        @rmdir($stage);
        return false;
    }

    if (!@rename($path, $stage.'/tree')) {
        @rmdir($stage);
        return false;
    }

    // GNU rm keeps traversal anchored to directory entries as they change.
    $output = [];
    $rc = 1;
    exec('rm -rf --one-file-system -- '.escapeshellarg($stage), $output, $rc);
    clearstatcache(true, $stage);
    return $rc === 0 && @lstat($stage) === false;
}
