<?php
/**
 * Filesystem health checks used by update preflight.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/../runtime.php';

if (!defined('PMSS_HOME_INODE_DENSITY_WARN_BYTES')) {
    define('PMSS_HOME_INODE_DENSITY_WARN_BYTES', 256 * 1024);
}

/** Run phase-2 preflight checks before package and configuration work begins. */
function pmssUpdateStep2PreflightChecks(?callable $logger = null, ?array $diskPaths = null, ?array $lockFiles = null, ?array $aptCachePaths = null, string $networkHost = 'deb.debian.org', float $requiredBytes = 3221225472.0): bool
{
    $log = $logger ?: 'logMessage';
    $fatalError = false;
    foreach ($diskPaths ?? ['/', '/home'] as $path) {
        if (!is_dir($path)) continue;
        $free = @disk_free_space($path);
        if ($free === false) {
            $log("[WARN] Unable to determine free space for {$path}");
            pmssLogJson(['event' => 'preflight_error', 'check' => 'disk_space', 'path' => $path, 'status' => 'warn', 'reason' => 'stat_failed']);
            continue;
        }
        if ($free >= $requiredBytes) continue;
        $fatalError = true;
        pmssLogJson(['event' => 'preflight_error', 'check' => 'disk_space', 'path' => $path, 'status' => 'error', 'available_bytes' => $free, 'required_bytes' => $requiredBytes]);
        $log('Insufficient free space on '.$path.': '.round($free / 1073741824, 2).' GiB available, '.round($requiredBytes / 1073741824, 2).' GiB required');
    }
    foreach ($lockFiles ?? ['/var/lib/dpkg/lock-frontend', '/var/lib/dpkg/lock'] as $lockFile) {
        $lockBusy = false;
        $fh = pmssLockFileAcquire($lockFile, true, 'c', false, true, $lockBusy);
        if ($fh !== false) { pmssLockHandleRelease($fh, false); continue; }
        pmssLogJson(['event' => 'preflight_error', 'check' => 'dpkg_lock', 'status' => 'warn', 'path' => $lockFile, 'reason' => $lockBusy ? 'busy' : 'open_failed']);
        $log($lockBusy ? "[WARN] dpkg lock appears busy: {$lockFile}" : "[WARN] Unable to open dpkg lock file: {$lockFile}");
    }
    foreach ($aptCachePaths ?? ['/var/cache/apt/archives', '/var/lib/apt/lists'] as $path) {
        if (is_dir($path) && is_writable($path)) continue;
        pmssLogJson(['event' => 'preflight_error', 'check' => 'apt_cache', 'status' => 'warn', 'path' => $path, 'reason' => 'unwritable']);
        $log("[WARN] APT cache path missing or not writable: {$path}");
    }
    if ($networkHost !== '' && !pmssEnvFlagEnabled('PMSS_DRY_RUN') && !pmssTestModeEnabled()) {
        $sock = @fsockopen($networkHost, 80, $errno, $errstr, 3.0);
        if ($sock === false) {
            pmssLogJson(['event' => 'preflight_error', 'check' => 'network', 'status' => 'warn', 'reason' => 'unreachable', 'host' => $networkHost, 'errno' => $errno, 'error' => $errstr]);
            $log('[WARN] Unable to reach '.$networkHost.': '.$errstr.' ('.$errno.')');
        } else {
            fclose($sock);
        }
    }
    if ($fatalError) { $log('Preflight checks failed (fatal) - aborting update-step2'); return false; }
    pmssLogJson(['event' => 'preflight_ok']);
    return true;
}

/**
 * Parse the `stat -f -c "%S %b %c"` output needed for inode density checks.
 *
 * @return array{block_size:int,blocks:int,inodes:int}|null
 */
function pmssFilesystemStatLineParse(string $line): ?array
{
    $parts = preg_split('/\s+/', trim($line));
    if (!is_array($parts) || count($parts) < 3) {
        return null;
    }

    foreach ([$parts[0], $parts[1], $parts[2]] as $value) {
        if (!ctype_digit((string) $value) || (int) $value <= 0) {
            return null;
        }
    }

    return [
        'block_size' => (int) $parts[0],
        'blocks'     => (int) $parts[1],
        'inodes'     => (int) $parts[2],
    ];
}

/** Return bytes-per-inode for a parsed filesystem stat row. */
function pmssFilesystemBytesPerInode(array $stats): ?float
{
    foreach (['block_size', 'blocks', 'inodes'] as $key) {
        if (!isset($stats[$key]) || !is_numeric($stats[$key]) || (float) $stats[$key] <= 0.0) {
            return null;
        }
    }

    return ((float) $stats['block_size'] * (float) $stats['blocks']) / (float) $stats['inodes'];
}

/** Format a bytes-per-inode value for concise operator logs. */
function pmssFilesystemBytesPerInodeFormat(float $bytes): string
{
    return pmssFormatBytes($bytes, 1, 1).' per inode';
}

/**
 * Emit a warning when /home has too few inodes for modern shared workloads.
 */
function pmssHomeInodeDensityCheck(
    ?callable $logger = null,
    string $path = '/home',
    int $warnThresholdBytes = PMSS_HOME_INODE_DENSITY_WARN_BYTES
): void {
    $log = $logger ?: 'logMessage';
    $warnThresholdBytes = $warnThresholdBytes > 0 ? $warnThresholdBytes : PMSS_HOME_INODE_DENSITY_WARN_BYTES;

    if (!is_dir($path)) {
        $log('[SKIP] Home inode density check skipped; path missing: '.$path);
        pmssLogJson(['event' => 'home_inode_density', 'status' => 'skip', 'path' => $path, 'reason' => 'missing_path']);
        return;
    }

    $rc = runStep('Inspecting home filesystem inode density', pmssBuildCommand('stat', ['-f', '-c', '%S %b %c', $path]));
    if ($rc !== 0) {
        $log('[WARN] Unable to inspect home inode density for '.$path.' (stat rc='.$rc.')');
        pmssLogJson(['event' => 'home_inode_density', 'status' => 'warn', 'path' => $path, 'reason' => 'stat_failed', 'rc' => $rc]);
        return;
    }

    $stdout = trim((string) (($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '')));
    $line = strtok($stdout, "\r\n");
    $stats = pmssFilesystemStatLineParse(is_string($line) ? $line : '');
    $bytesPerInode = $stats !== null ? pmssFilesystemBytesPerInode($stats) : null;
    if ($stats === null || $bytesPerInode === null) {
        $log('[WARN] Unable to parse home inode density from stat output for '.$path);
        pmssLogJson(['event' => 'home_inode_density', 'status' => 'warn', 'path' => $path, 'reason' => 'parse_failed']);
        return;
    }

    $roundedBytesPerInode = (int) round($bytesPerInode);
    $status = $roundedBytesPerInode > $warnThresholdBytes ? 'warn' : 'ok';
    pmssLogJson([
        'event'           => 'home_inode_density',
        'status'          => $status,
        'path'            => $path,
        'block_size'      => $stats['block_size'],
        'blocks'          => $stats['blocks'],
        'inodes'          => $stats['inodes'],
        'bytes_per_inode' => $roundedBytesPerInode,
        'threshold_bytes' => $warnThresholdBytes,
    ]);

    $density = pmssFilesystemBytesPerInodeFormat($bytesPerInode);
    $threshold = pmssFilesystemBytesPerInodeFormat((float) $warnThresholdBytes);
    if ($status === 'warn') {
        $log('[WARN] Home inode density is '.$density.' on '.$path.' (threshold '.$threshold.'); media-stack workloads may exhaust inodes before disk blocks. Migrate affected users or reformat during maintenance.');
        return;
    }

    $log('[OK] Home inode density is '.$density.' on '.$path.' (threshold '.$threshold.')');
}
