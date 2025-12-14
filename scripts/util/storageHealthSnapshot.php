#!/usr/bin/php
<?php
/**
 * Storage health snapshot (SMART/NVMe + mdadm) to JSONL (safe for cron).
 */

require_once __DIR__.'/../lib/storageHealth.php';

function pmssStorageHealthSnapshotUsage(): void
{
    echo "\nStorage health snapshot (SMART/NVMe + mdadm) to JSONL\n";
    echo "Usage: storageHealthSnapshot.php [--json <path>] [--quiet]\n\n";
    echo "  --json <path>   JSON Lines output (default ".pmssStorageHealthDefaultJsonPath().")\n";
    echo "  --quiet         Suppress the success message (cron-friendly)\n\n";
}

function pmssStorageHealthSnapshotMain(array $argv): int
{
    $logPath = pmssStorageHealthDefaultJsonPath();
    $quiet = false;

    $argc = count($argv);
    for ($i = 1; $i < $argc; $i++) {
        $arg = $argv[$i];
        $next = ($i + 1 < $argc) ? $argv[$i + 1] : null;
        $kv = null;
        if (strpos($arg, '=') !== false) {
            $kv = explode('=', $arg, 2);
        }
        $key = $kv ? $kv[0] : $arg;
        $val = $kv ? $kv[1] : null;
        switch ($key) {
            case '--json':
                if ($val === null && $next !== null && strpos($next, '--') !== 0) {
                    $val = $next;
                    $i++;
                }
                if ($val !== null && $val !== '') {
                    $logPath = $val;
                }
                break;
            case '--quiet':
                $quiet = true;
                break;
            case '--help':
            case '-h':
                pmssStorageHealthSnapshotUsage();
                return 0;
        }
    }

    pmssStorageHealthEnsureParentDir($logPath);
    $timestamp = date('c');
    $last = pmssStorageHealthReadLastEntries($logPath);

    foreach (pmssStorageHealthListDisks() as $disk) {
        pmssStorageHealthAppendJsonl($logPath, pmssStorageHealthSnapshotSmart($disk, $last, $timestamp));
        $nvme = pmssStorageHealthSnapshotNvme($disk, $last, $timestamp);
        if (is_array($nvme)) {
            pmssStorageHealthAppendJsonl($logPath, $nvme);
        }
    }
    foreach (pmssStorageHealthSnapshotRaid($timestamp) as $raid) {
        pmssStorageHealthAppendJsonl($logPath, $raid);
    }

    if (!$quiet) {
        echo "Storage health snapshot written to {$logPath}\n";
    }
    return 0;
}

exit(pmssStorageHealthSnapshotMain($argv));
