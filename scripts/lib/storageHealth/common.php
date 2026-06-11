<?php
/**
 * Shared helpers for storage health snapshot/reporting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

/**
 * Parse the common `lsblk -dn` disk inventory format used by storage tools.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthDiskInventoryFromLsblk(string $lsblkOut): array
{
    $disks = [];
    foreach (preg_split('/\r?\n/', trim($lsblkOut)) as $line) {
        if ($line === '' || !is_array($parts = preg_split('/\s+/', trim($line))) || ($partCount = count($parts)) < 3) continue;
        $kname = (string) $parts[0];
        // KNAME comes from lsblk; reject shell/path syntax before composing /dev/<name>.
        if ($kname === '' || $kname === '.' || $kname === '..' || preg_match('/^[A-Za-z0-9._!+-]+$/D', $kname) !== 1) continue;
        if ($parts[1] !== 'disk' || strpos($kname, 'loop') === 0 || strpos($kname, 'ram') === 0) continue;
        $disks[] = ['path' => '/dev/'.$kname, 'kname' => $kname, 'rota' => (int) $parts[2], 'model' => implode(' ', array_slice($parts, 3, max(0, $partCount - 5))), 'serial' => (string) ($parts[$partCount - 2] ?? ''), 'size' => (string) ($parts[$partCount - 1] ?? '')];
    }
    return $disks;
}

/**
 * Read disk inventory only after lsblk reports a clean exit.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthDiskInventoryRead(): array
{
    $result = pmssCommandCapture('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE', 30);
    if ((int) ($result['rc'] ?? 1) !== 0) {
        return [];
    }

    return pmssStorageHealthDiskInventoryFromLsblk((string) ($result['stdout'] ?? ''));
}

/**
 * Read the latest entry per (kind, device/array) key from a JSONL file.
 *
 * @return array<string, array<string, mixed>>
 */
function pmssStorageHealthReadLastEntries(string $path): array
{
    $last = [];
    pmssJsonLineFileEach($path, static function (array $entry) use (&$last): void {
        $last[(string) ($entry['kind'] ?? '').'::'.(string) ($entry['device'] ?? ($entry['array'] ?? 'global'))] = $entry;
    });
    return $last;
}

/** @param array<string, mixed> $disk @return array<string, mixed> */
function pmssStorageHealthDeviceEntryBuild(string $kind, array $disk, string $timestamp, int $defaultRota): array
{
    return [
        'timestamp' => $timestamp,
        'kind' => $kind,
        'device' => (string) ($disk['path'] ?? ''),
        'kname' => (string) ($disk['kname'] ?? ''),
        'model' => (string) ($disk['model'] ?? ''),
        'serial' => (string) ($disk['serial'] ?? ''),
        'rota' => (int) ($disk['rota'] ?? $defaultRota),
        'size' => (string) ($disk['size'] ?? ''),
    ];
}

/** @param array<string, mixed> $entry @param array<int, string> $flags @return array<string, mixed> */
function pmssStorageHealthEntryFinalize(array $entry, array $flags, string $severity, ?string $error = null): array
{
    if ($error !== null) {
        $entry['error'] = $error;
    }
    $entry['flags'] = array_values(array_unique($flags));
    $entry['severity'] = $severity;
    $entry['ok'] = ($severity === 'ok');
    return $entry;
}

/** Promote OK storage-health severity to WARN without downgrading failures. */
function pmssStorageHealthWarnSeverity(string $severity): string
{
    return $severity === 'ok' ? 'warn' : $severity;
}

/** Append flags when current metrics exceed the previous snapshot. */
function pmssStorageHealthAppendMetricIncreaseFlags(array $metrics, array $previous, array $metricFlags, array $warningMetrics, array &$flags, string $severity): string
{
    foreach ($metricFlags as $metric => $flag) {
        $current = $metrics[$metric] ?? null;
        $old = $previous[$metric] ?? null;
        if (!is_int($current) || !is_int($old) || $current <= $old) continue;
        if (in_array($metric, $warningMetrics, true)) $severity = pmssStorageHealthWarnSeverity($severity);
        $flags[] = $flag;
    }
    return $severity;
}

/**
 * Execute a shell command with captured output (no streaming).
 *
 * @return array{rc:int,stdout:string,stderr:string}
 */
function pmssStorageHealthExecCapture(string $cmd, int $timeoutSec = 20): array
{
    return pmssCommandCapture($cmd, $timeoutSec);
}
