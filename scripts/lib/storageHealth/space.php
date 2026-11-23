<?php
/**
 * Host filesystem free-space snapshot for the storage-health stream.
 *
 * A host whose per-account quotas sum to more than the physical array can fill it, and then
 * every account gets ENOSPC while its own quota panel still shows headroom. This records
 * the physical /home and / free space so the condition is visible before it becomes an outage.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/common.php';

// Free percent (of space available to non-root) below which a filesystem is flagged.
const PMSS_STORAGE_HEALTH_SPACE_WARN_PERCENT = 10.0;
const PMSS_STORAGE_HEALTH_SPACE_FAIL_PERCENT = 3.0;

/**
 * Parse `stat -f -c '%S %b %f %a %c %d'` output into free-space metrics.
 *
 * Used space is blocks minus root-free blocks, so the percentage matches what df reports.
 *
 * @return array{total_bytes:int,free_bytes:int,free_percent:float,inode_free_percent:?float}|null
 */
function pmssStorageHealthSpaceParse(string $output): ?array
{
    if (preg_match('/^(\d+) (\d+) (\d+) (\d+) (\d+) (\d+)\s*$/D', $output, $m) !== 1) {
        return null;
    }
    [$blockSize, $blocks, $freeRoot, $avail, $inodes, $inodesFree] = array_map('intval', array_slice($m, 1));
    $usable = ($blocks - $freeRoot) + $avail;
    if ($blockSize <= 0 || $usable <= 0) {
        return null;
    }
    return [
        'total_bytes' => $usable * $blockSize,
        'free_bytes' => $avail * $blockSize,
        'free_percent' => round($avail / $usable * 100, 1),
        // Filesystems without a fixed inode table report zero inodes: nothing to measure.
        'inode_free_percent' => $inodes > 0 ? round($inodesFree / $inodes * 100, 1) : null,
    ];
}

/** Severity for the lower of block and inode free percent: fail below 3%, warn below 10%. */
function pmssStorageHealthSpaceSeverity(array $metrics): string
{
    $lowest = min($metrics['free_percent'], $metrics['inode_free_percent'] ?? 100.0);
    if ($lowest < PMSS_STORAGE_HEALTH_SPACE_FAIL_PERCENT) return 'fail';
    return $lowest < PMSS_STORAGE_HEALTH_SPACE_WARN_PERCENT ? 'warn' : 'ok';
}

/**
 * One `kind: fs` record per mount. /home is read only when it is its own filesystem: a
 * missing mount would otherwise report the root filesystem's figures as the data array's.
 *
 * @param array<int,string> $mounts
 * @param callable|null $capture Bounded command runner (test seam), defaults to pmssCommandCapture.
 * @param callable|null $deviceId Returns the st_dev of a path or null (test seam).
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthSnapshotSpace(string $timestamp, array $mounts = ['/home', '/'], ?callable $capture = null, ?callable $deviceId = null): array
{
    $capture = $capture ?? 'pmssCommandCapture';
    $deviceId = $deviceId ?? static function (string $path): ?int {
        $stat = @stat($path);
        return is_array($stat) ? (int) $stat['dev'] : null;
    };
    $entries = [];
    foreach ($mounts as $mount) {
        $entry = ['timestamp' => $timestamp, 'kind' => 'fs', 'device' => $mount, 'severity' => 'ok', 'flags' => [], 'metrics' => []];
        if ($mount === '/home' && $deviceId('/home') === $deviceId('/')) {
            $entry['severity'] = 'warn';
            $entry['flags'] = ['home_not_separate_mount'];
            $entries[] = $entry;
            continue;
        }
        // Shell quoting cannot make an option into an operand or accept NUL bytes.
        $result = is_string($mount) && $mount !== '' && $mount[0] !== '-' && strpos($mount, "\0") === false
            ? $capture('stat -f -c '.escapeshellarg('%S %b %f %a %c %d').' '.escapeshellarg($mount), 15)
            : ['rc' => 1];
        $metrics = (int) ($result['rc'] ?? 1) === 0 ? pmssStorageHealthSpaceParse((string) ($result['stdout'] ?? '')) : null;
        if ($metrics === null) {
            $entry['severity'] = 'warn';
            $entry['flags'] = ['space_unreadable'];
        } else {
            $entry['metrics'] = $metrics;
            $entry['severity'] = pmssStorageHealthSpaceSeverity($metrics);
            $entry['flags'] = $entry['severity'] === 'ok' ? [] : ['low_free_space'];
        }
        $entries[] = $entry;
    }
    return $entries;
}
