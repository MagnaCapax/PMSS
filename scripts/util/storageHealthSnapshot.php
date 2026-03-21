#!/usr/bin/env php
<?php
/**
 * Storage health snapshot (SMART/NVMe + mdadm) to JSONL (safe for cron).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/storageHealth.php';

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
            echo "\nStorage health snapshot (SMART/NVMe + mdadm) to JSONL\n";
            echo "Usage: storageHealthSnapshot.php [--json <path>] [--quiet]\n\n";
            echo "  --json <path>   JSON Lines output (default /var/log/pmss/storage-health.jsonl)\n";
            echo "  --quiet         Suppress the success message (cron-friendly)\n\n";
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

    if (($logDir = dirname($logPath)) !== '' && !is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $timestamp = date('c');
    $last = pmssStorageHealthReadLastEntries($logPath);

    $lsblkOut = shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null');
    $disks = [];
    foreach (preg_split('/\r?\n/', trim((string) $lsblkOut)) as $line) {
        if ($line === '') {
            continue;
        }

        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || ($partCount = count($parts)) < 3) {
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
        @file_put_contents($logPath, json_encode(pmssStorageHealthSnapshotSmart($disk, $last, $timestamp), JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        if (is_array($nvme = pmssStorageHealthSnapshotNvme($disk, $last, $timestamp))) {
            @file_put_contents($logPath, json_encode($nvme, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
    foreach (pmssStorageHealthSnapshotRaid($timestamp) as $raid) {
        @file_put_contents($logPath, json_encode($raid, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    if (!$quiet) {
        echo "Storage health snapshot written to {$logPath}\n";
    }
    return 0;
}

exit(pmssStorageHealthSnapshotMain($argv));
