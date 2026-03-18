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
        $next = ($i + 1 < $argc) ? $argv[$i + 1] : null;
        [$key, $val] = array_pad(explode('=', $arg, 2), 2, null);
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
                echo "\nStorage health snapshot (SMART/NVMe + mdadm) to JSONL\n";
                echo "Usage: storageHealthSnapshot.php [--json <path>] [--quiet]\n\n";
                echo "  --json <path>   JSON Lines output (default /var/log/pmss/storage-health.jsonl)\n";
                echo "  --quiet         Suppress the success message (cron-friendly)\n\n";
                return 0;
        }
    }

    $logDir = dirname($logPath);
    if ($logDir !== '' && !is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $timestamp = date('c');
    $last = pmssStorageHealthReadLastEntries($logPath);
    $appendJson = static function (array $entry) use ($logPath): void {
        @file_put_contents(
            $logPath,
            json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    };

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
        $appendJson(pmssStorageHealthSnapshotSmart($disk, $last, $timestamp));
        $nvme = pmssStorageHealthSnapshotNvme($disk, $last, $timestamp);
        if (is_array($nvme)) {
            $appendJson($nvme);
        }
    }
    foreach (pmssStorageHealthSnapshotRaid($timestamp) as $raid) {
        $appendJson($raid);
    }

    if (!$quiet) {
        echo "Storage health snapshot written to {$logPath}\n";
    }
    return 0;
}

exit(pmssStorageHealthSnapshotMain($argv));
