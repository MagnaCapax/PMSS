<?php
/**
 * RAID storage-health snapshot, status, and customer notice helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/common.php';

// Load only the shipped root-owned skeleton, never an account's writable copy.
$pmssStorageRaidDisplay = dirname(__DIR__, 3).'/etc/skel/www/storageHealthRaidDisplay.php';
if (!is_file($pmssStorageRaidDisplay) || is_link($pmssStorageRaidDisplay)) {
    throw new RuntimeException('Storage RAID display helper unavailable');
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0
    && (fileowner($pmssStorageRaidDisplay) !== 0 || (fileperms($pmssStorageRaidDisplay) & 0022) !== 0
        || fileowner(dirname($pmssStorageRaidDisplay)) !== 0 || (fileperms(dirname($pmssStorageRaidDisplay)) & 0022) !== 0)) {
    throw new RuntimeException('Unsafe storage RAID display helper ownership');
}
require_once $pmssStorageRaidDisplay;
unset($pmssStorageRaidDisplay);
require_once __DIR__.'/raidParse.php';

/**
 * Read mdadm array status from `/proc/mdstat`.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthSnapshotRaid(string $timestamp, string $mdstatPath = '/proc/mdstat'): array
{
    // Invalid paths must use the same empty snapshot as an unavailable proc file.
    if ($mdstatPath === '' || pmssFilesystemPathHasNulByte($mdstatPath)) {
        return [];
    }
    $mdstat = @file_get_contents($mdstatPath);
    return $mdstat === false ? [] : pmssStorageHealthRaidEntriesParse($mdstat, $timestamp);
}

/** Resolve the md array that backs /home, if /home is mounted directly on md. */
function pmssStorageHealthHomeArrayResolve(?string $mountsPath = null): ?string
{
    $mountsPath = ($mountsPath !== null && $mountsPath !== '') ? $mountsPath : '/proc/mounts';
    if (pmssFilesystemPathHasNulByte($mountsPath)) {
        return null;
    }
    if (!is_array($mounts = @file($mountsPath, FILE_IGNORE_NEW_LINES))) {
        return null;
    }
    foreach (pmssConfigRows($mounts, 2, []) as $fields) {
        if (str_replace('\\040', ' ', (string) $fields[1]) !== '/home') {
            continue;
        }
        $source = str_replace('\\040', ' ', (string) $fields[0]);
        // A malformed mount source cannot be passed safely to realpath().
        if (pmssFilesystemPathHasNulByte($source)) {
            return null;
        }
        return preg_match('#/(md\d+)$#', @realpath($source) ?: $source, $matches) === 1 ? $matches[1] : null;
    }
    return null;
}

/**
 * Read current activity for the md array backing /home.
 *
 * @param array<int, array<string,mixed>>|null $raidEntries
 * @return array<string,mixed>|null
 */
function pmssStorageHealthHomeRaidActivity(?string $mountsPath = null, ?array $raidEntries = null): ?array
{
    $homeArray = pmssStorageHealthHomeArrayResolve($mountsPath);
    if ($homeArray === null) {
        return null;
    }

    $degradedNotice = null;
    foreach ($raidEntries ?? pmssStorageHealthSnapshotRaid(date('c')) as $entry) {
        if ((string) ($entry['array'] ?? '') !== $homeArray) {
            continue;
        }
        $summary = pmssStorageHealthRaidActivitySummaryParse(trim((string) ($entry['resync'] ?? '')));
        if ($summary['operation'] !== '') {
            return $summary + ['array' => $homeArray];
        }
        if (in_array('degraded', (array) ($entry['flags'] ?? []), true)) {
            $degradedNotice = ['array' => $homeArray, 'flags' => ['degraded']];
        }
    }
    return $degradedNotice;
}

/**
 * Detect performance-limiting conditions (e.g., RAID resync/rebuild).
 *
 * @param array<int, array<string,mixed>> $raidEntries
 * @return array<string,string>|null ['status','reason','array']
 */
function pmssStorageHealthPerformanceStatus(array $raidEntries): ?array
{
    foreach ($raidEntries as $entry) {
        $flags = (array) ($entry['flags'] ?? []);
        $isRebuild = in_array('rebuild_in_progress', $flags, true);
        if (!$isRebuild && !in_array('degraded', $flags, true) && (string) ($entry['severity'] ?? 'ok') === 'ok') {
            continue;
        }
        $array = (string) ($entry['array'] ?? 'md');
        return ['status' => 'performance_limited', 'reason' => $isRebuild ? 'RAID '.$array.' '.((string) ($entry['operation'] ?? 'resync')).' in progress' : 'RAID '.$array.' degraded', 'array' => $array];
    }
    return null;
}
