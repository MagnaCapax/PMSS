#!/usr/bin/env php
<?php
/**
 * Storage health snapshot (SMART/NVMe + mdadm) to JSONL (safe for cron).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/storageHealth.php';
require_once __DIR__.'/../lib/log.php';
require_once __DIR__.'/../lib/cli/optionParser.php';

function pmssStorageHealthSnapshotMain(array $argv): int
{
    $logPath = '/var/log/pmss/storage-health.jsonl';
    if (($parsed = pmssParseCliTokensOrHelp($argv, "\nStorage health snapshot (SMART/NVMe + mdadm + /home and / free space) to JSONL\nUsage: storageHealthSnapshot.php [--json <path>] [--space-only] [--quiet]\n\n  --json <path>   JSON Lines output (default /var/log/pmss/storage-health.jsonl)\n  --space-only    Record only the /home and / free-space entries (cheap, for frequent cron runs)\n  --quiet         Suppress the success message (cron-friendly)\n\n")) === null) return 0;

    $quiet = pmssCliOptionPresent($parsed, 'quiet');
    $spaceOnly = pmssCliOptionPresent($parsed, 'space-only');
    $logPath = pmssCliOptionString($parsed, 'json', null, $logPath) ?? $logPath;

    $logDir = dirname($logPath);
    $logDirError = null;
    if (!pmssLogWriteDirectoryPrepare($logDir, 0755, $logDirError)) {
        return pmssCliReturnWithStderr($logDirError === 'create'
            ? "Failed to create storage health log directory {$logDir}\n"
            : "Refusing unsafe storage health log path {$logPath}\n");
    }
    if (!pmssLogWritePathIsSafe($logPath)) {
        return pmssCliReturnWithStderr("Refusing unsafe storage health log path {$logPath}\n");
    }

    $timestamp = date('c');
    $snapshotEntries = [];
    // Disk and array probes are slow and device-touching; the frequent space-only run skips them.
    if (!$spaceOnly) {
        $last = pmssStorageHealthReadLastEntries($logPath);
        $disks = pmssStorageHealthDiskInventoryRead();
        foreach ($disks as $disk) {
            $snapshotEntries[] = pmssStorageHealthSnapshotSmart($disk, $last, $timestamp);
            if (is_array($nvme = pmssStorageHealthSnapshotNvme($disk, $last, $timestamp))) {
                $snapshotEntries[] = $nvme;
            }
        }
        $snapshotEntries = array_merge($snapshotEntries, pmssStorageHealthSnapshotRaid($timestamp));
    }
    $snapshotEntries = array_merge($snapshotEntries, pmssStorageHealthSnapshotSpace($timestamp));
    foreach ($snapshotEntries as $entry) {
        if (!pmssJsonLineAppend($logPath, $entry)) {
            return pmssCliReturnWithStderr("Failed to write storage health snapshot to {$logPath}\n");
        }
    }

    if (!$quiet) { echo "Storage health snapshot written to {$logPath}\n"; }
    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssStorageHealthSnapshotMain');
