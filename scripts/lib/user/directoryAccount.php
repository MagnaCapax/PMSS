<?php
/** Create and repair home directories with the account's own authority. @license GPL-3.0-only */
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
