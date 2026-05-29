<?php
/**
 * Helpers for locating and comparing per-user cgroup I/O weight files.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

declare(strict_types=1);

/**
 * Resolve the cgroup weight file for a user slice in the detected hierarchy.
 */
function pmssCgroupBfqWeightTargetPath(string $mode, int $uid, string $cgroupRoot = '/sys/fs/cgroup'): string
{
    $root = rtrim($cgroupRoot, '/');

    if ($mode === 'v1') {
        return $root.'/blkio/user.slice/user-'.$uid.'.slice/blkio.bfq.weight';
    }

    if ($mode === 'v2') {
        $sliceDir = $root.'/user.slice/user-'.$uid.'.slice';
        $bfqPath = $sliceDir.'/io.bfq.weight';

        return file_exists($bfqPath) ? $bfqPath : $sliceDir.'/io.weight';
    }

    return '';
}

/**
 * Confirm the hierarchy exposes the controller needed by the writer.
 */
function pmssCgroupBfqWeightControllerReady(string $mode, string $cgroupRoot = '/sys/fs/cgroup'): bool
{
    $root = rtrim($cgroupRoot, '/');

    if ($mode === 'v1') {
        return is_dir($root.'/blkio');
    }

    if ($mode !== 'v2') {
        return false;
    }

    $controllersPath = $root.'/cgroup.controllers';
    if (!is_file($controllersPath)) {
        return false;
    }

    $controllers = preg_split('/\s+/', trim((string) @file_get_contents($controllersPath)));
    return is_array($controllers) && in_array('io', $controllers, true);
}

/**
 * Detect whether any classic sd* device currently uses the BFQ scheduler.
 */
function pmssCgroupBfqWeightBfqSchedulerActive(string $blockRoot = '/sys/block'): bool
{
    $root = rtrim($blockRoot, '/');

    foreach (glob($root.'/sd*/queue/scheduler') ?: [] as $schedFile) {
        if (preg_match('/\[bfq\]/', (string) @file_get_contents($schedFile))) {
            return true;
        }
    }

    return false;
}

/**
 * Parse either v1's bare integer or v2's "default N" weight readback.
 */
function pmssCgroupBfqWeightCurrentValue(string $contents): int
{
    if (preg_match('/^\s*default\s+([0-9]+)/', $contents, $match)) {
        return (int) $match[1];
    }

    if (preg_match('/^\s*([0-9]+)/', $contents, $match)) {
        return (int) $match[1];
    }

    return 0;
}

/**
 * Return the value to write; v2 is explicit about updating the default weight.
 */
function pmssCgroupBfqWeightWritePayload(string $mode, int $weight): string
{
    return $mode === 'v2' ? 'default '.$weight : (string) $weight;
}
