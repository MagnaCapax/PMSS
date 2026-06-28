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
    $parsed = pmssParseCliTokens($argv);

    if (pmssCliHelpTextEmitIfRequested($parsed, "\nStorage health snapshot (SMART/NVMe + mdadm) to JSONL\nUsage: storageHealthSnapshot.php [--json <path>] [--quiet]\n\n  --json <path>   JSON Lines output (default /var/log/pmss/storage-health.jsonl)\n  --quiet         Suppress the success message (cron-friendly)\n\n")) return 0;

    $quiet = pmssCliOptionPresent($parsed, 'quiet');
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
    $last = pmssStorageHealthReadLastEntries($logPath);

    $snapshotEntries = [];
    $disks = pmssStorageHealthDiskInventoryRead();
    foreach ($disks as $disk) {
        $snapshotEntries[] = pmssStorageHealthSnapshotSmart($disk, $last, $timestamp);
        if (is_array($nvme = pmssStorageHealthSnapshotNvme($disk, $last, $timestamp))) {
            $snapshotEntries[] = $nvme;
        }
    }
    foreach (pmssStorageHealthSnapshotRaid($timestamp) as $raid) {
        $snapshotEntries[] = $raid;
    }
    foreach ($snapshotEntries as $entry) {
        if (!pmssJsonLineAppend($logPath, $entry)) {
            return pmssCliReturnWithStderr("Failed to write storage health snapshot to {$logPath}\n");
        }
    }

    if (!$quiet) { echo "Storage health snapshot written to {$logPath}\n"; }
    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssStorageHealthSnapshotMain');
