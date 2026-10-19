<?php
/** Apply the user.slice parent directory mode without changing account slices. */
function pmssCgroupUserSliceParentPaths(string $root = '/sys/fs/cgroup'): array
{
    $paths = [];
    if (is_file($root.'/cgroup.controllers')) {
        return [$root.'/user.slice'];
    }
    foreach (glob($root.'/*/user.slice', GLOB_ONLYDIR) ?: [] as $path) {
        $paths[] = $path;
    }
    return $paths;
}

/** Set traversal-only mode on each mounted controller's user.slice parent. */
function pmssCgroupDirectoryModeApply(?callable $logger = null, string $root = '/sys/fs/cgroup'): void
{
    $log = $logger ?: 'logMessage';
    foreach (pmssCgroupUserSliceParentPaths($root) as $path) {
        if (is_link($path) || !is_dir($path) || !@chmod($path, 0711)) {
            $log('[WARN] Unable to apply user.slice directory mode: '.$path);
        }
    }
}
