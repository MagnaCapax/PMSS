<?php
/**
 * Converge template permissions while keeping per-user records private.
 *
 * @license GPL-3.0-only
 */

/** Apply modes to the config tree; the callback supports hermetic mode checks. */
function pmssConfigPermissionsSweep(string $configDir, ?callable $chmod = null): void
{
    if (!is_dir($configDir) || is_link($configDir)) {
        return;
    }
    $configDir = rtrim($configDir, '/');
    $usersDir = $configDir.'/users';
    $chmod = $chmod ?: (static function (string $path, int $mode): void { @chmod($path, $mode); });

    // Exclude the whole users subtree before the general directory/file modes apply.
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($configDir, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $node) use ($usersDir): bool {
            return $node->getPathname() !== $usersDir;
        }
    );
    $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $node) {
        $path = $node->getPathname();
        if ($node->isLink()) {
            if (function_exists('logmsg')) {
                logmsg(sprintf('Skipping %s: symlink detected', $path));
            }
            continue;
        }
        $chmod($path, $node->isDir() ? 0775 : 0664);
    }

    $chmod($configDir, 0775);
    if (is_dir($usersDir) && !is_link($usersDir)) {
        $chmod($usersDir, 0750);
        foreach (glob($usersDir.'/*.json') ?: [] as $userRecord) {
            if (is_file($userRecord) && !is_link($userRecord)) {
                $chmod($userRecord, 0640);
            }
        }
    }
}
