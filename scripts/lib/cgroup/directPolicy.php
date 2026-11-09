<?php
/** Pure target/specification policy for direct cgroup-v1 enforcement. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime/environment.php';

const PMSS_CGROUP_DIRECT_BLKIO_ROOT = '/sys/fs/cgroup/blkio';

/** Build the cgroup-v1 user.slice directory for one positive UID. */
function pmssCgroupDirectUserSliceDir(int $uid): string { return PMSS_CGROUP_DIRECT_BLKIO_ROOT.'/user.slice/user-'.$uid.'.slice'; }

/** Build one direct blkio target path below a managed user's cgroup-v1 slice. */
function pmssCgroupDirectUserBlkioFilePath(int $uid, string $fileName): string { return pmssCgroupDirectUserSliceDir($uid).'/'.$fileName; }

/** Allow only explicitly pinned files below positive-UID per-user blkio slices. */
function pmssCgroupDirectUserBlkioPathAllowed(string $cgPath, array $allowedFiles): bool
{
    $patterns = [];
    foreach ($allowedFiles as $fileName) {
        if (is_string($fileName) && $fileName !== '' && strpos($fileName, '/') === false && strpos($fileName, "\0") === false) $patterns[] = preg_quote($fileName, '#');
    }

    return $patterns !== []
        && preg_match('#^'.preg_quote(PMSS_CGROUP_DIRECT_BLKIO_ROOT, '#').'/user\.slice/user-[1-9][0-9]*\.slice/(?:'.implode('|', $patterns).')$#', $cgPath) === 1;
}

/** Read only this device's old integer from the writer's already captured state. */
function pmssCgroupDirectDeviceValueParse(string $raw, string $device): ?int
{
    if (strpos($raw, "\0") !== false) return null;
    foreach (pmssConfigTextRows($raw, 2, []) as $columns) {
        if ($columns[0] === $device) return count($columns) === 2 ? pmssUnsignedDecimalIntParse($columns[1]) : null;
    }
    return null;
}

/**
 * Parse an IOReadIOPS/IOWriteIOPS spec into its positive-integer value.
 * "/home:2000" -> 2000. "/dev/md0:2000" -> 2000. Empty/malformed -> null.
 */
function pmssIopsParseSpec($raw): ?int
{
    if (!is_string($raw) || $raw === '' || $raw === '0') {
        return null;
    }

    // Do not let an arbitrary "label:number" string trigger a /home throttle.
    if (preg_match('#^(?:/home|/dev/[^:\r\n\x00]+):([0-9]+)$#', $raw, $m) !== 1) {
        return null;
    }

    $n = (int) $m[1];
    return $n > 0 ? $n : null;
}
