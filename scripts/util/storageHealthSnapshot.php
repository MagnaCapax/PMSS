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

    if (($logDir = dirname($logPath)) !== '') { pmssDirEnsureExists($logDir, 0755); }
    $timestamp = date('c');
    $last = pmssStorageHealthReadLastEntries($logPath);

    $lsblkOut = shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null');
    $disks = [];
    foreach (preg_split('/\r?\n/', trim((string) $lsblkOut)) as $line) {
        if ($line === '' || !is_array($parts = preg_split('/\s+/', trim($line))) || ($partCount = count($parts)) < 3) {
            continue;
        }

        $kname = (string) $parts[0];
        if ($parts[1] !== 'disk' || strpos($kname, 'loop') === 0 || strpos($kname, 'ram') === 0) {
            continue;
        }

        $disks[] = [
            'path' => '/dev/'.$kname,
            'kname' => $kname,
            'rota' => (int) $parts[2],
            'model' => implode(' ', array_slice($parts, 3, max(0, $partCount - 5))),
            'serial' => (string) ($parts[$partCount - 2] ?? ''),
            'size' => (string) ($parts[$partCount - 1] ?? ''),
        ];
    }
    foreach ($disks as $disk) {
        pmssJsonLineAppend($logPath, pmssStorageHealthSnapshotSmart($disk, $last, $timestamp));
        if (is_array($nvme = pmssStorageHealthSnapshotNvme($disk, $last, $timestamp))) {
            pmssJsonLineAppend($logPath, $nvme);
        }
    }
    foreach (pmssStorageHealthSnapshotRaid($timestamp) as $raid) {
        pmssJsonLineAppend($logPath, $raid);
    }

    if (!$quiet) { echo "Storage health snapshot written to {$logPath}\n"; }
    return 0;
}

exit(pmssStorageHealthSnapshotMain($argv));
