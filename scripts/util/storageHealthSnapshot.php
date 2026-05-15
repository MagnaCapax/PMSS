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

function pmssStorageHealthSnapshotMain(array $argv): int
{
    $logPath = '/var/log/pmss/storage-health.jsonl';
    $quiet = false;

    for ($i = 1, $argc = count($argv); $i < $argc; $i++) {
        $arg = $argv[$i];
        [$key, $val] = array_pad(explode('=', $arg, 2), 2, null);
        if ($key === '--quiet') {
            $quiet = true;
            continue;
        }
        if ($key === '--help' || $key === '-h') {
            echo "\nStorage health snapshot (SMART/NVMe + mdadm) to JSONL\nUsage: storageHealthSnapshot.php [--json <path>] [--quiet]\n\n  --json <path>   JSON Lines output (default /var/log/pmss/storage-health.jsonl)\n  --quiet         Suppress the success message (cron-friendly)\n\n";
            return 0;
        }
        if ($key !== '--json') {
            continue;
        }
        if ($val === null && $i + 1 < $argc && strpos($argv[$i + 1], '--') !== 0) {
            $val = $argv[++$i];
        }
        if ($val !== null && $val !== '') {
            $logPath = $val;
        }
    }

    if (($logDir = dirname($logPath)) !== '' && !pmssDirEnsureExists($logDir, 0755)) {
        fwrite(STDERR, "Failed to create storage health log directory {$logDir}\n");
        return 1;
    }

    $timestamp = date('c');
    $last = pmssStorageHealthReadLastEntries($logPath);

    $disks = pmssStorageHealthDiskInventoryFromLsblk((string) shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null'));
    foreach ($disks as $disk) {
        $smartWritten = pmssJsonLineAppend($logPath, pmssStorageHealthSnapshotSmart($disk, $last, $timestamp));
        if (!$smartWritten) {
            fwrite(STDERR, "Failed to write storage health snapshot to {$logPath}\n");
            return 1;
        }
        if (is_array($nvme = pmssStorageHealthSnapshotNvme($disk, $last, $timestamp))) {
            $nvmeWritten = pmssJsonLineAppend($logPath, $nvme);
            if (!$nvmeWritten) {
                fwrite(STDERR, "Failed to write storage health snapshot to {$logPath}\n");
                return 1;
            }
        }
    }
    foreach (pmssStorageHealthSnapshotRaid($timestamp) as $raid) {
        $raidWritten = pmssJsonLineAppend($logPath, $raid);
        if (!$raidWritten) {
            fwrite(STDERR, "Failed to write storage health snapshot to {$logPath}\n");
            return 1;
        }
    }

    if (!$quiet) { echo "Storage health snapshot written to {$logPath}\n"; }
    return 0;
}

exit(pmssStorageHealthSnapshotMain($argv));
