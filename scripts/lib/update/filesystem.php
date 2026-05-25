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
