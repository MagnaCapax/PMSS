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
        if ($kname === '' || $kname === '.' || $kname === '..' || strpos($kname, '/') !== false || strpos($kname, '\\') !== false || strpos($kname, "\0") !== false) continue;
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
/**
 * Execute a shell command with captured output (no streaming).
 *
 * @return array{rc:int,stdout:string,stderr:string}
 */
function pmssStorageHealthExecCapture(string $cmd, int $timeoutSec = 20): array
{
    return pmssCommandCapture($cmd, $timeoutSec);
}

/**
 * Resolve the md array that backs /home, if /home is mounted directly on md.
 */
function pmssStorageHealthHomeArrayResolve(?string $mountsPath = null): ?string
{
    $mountsPath = ($mountsPath !== null && $mountsPath !== '') ? $mountsPath : '/proc/mounts';
    if (!is_array($mounts = @file($mountsPath, FILE_IGNORE_NEW_LINES))) {
        return null;
    }

    foreach ($mounts as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (!is_array($fields) || count($fields) < 2 || str_replace('\\040', ' ', (string) $fields[1]) !== '/home') {
            continue;
        }

        $resolvedPath = @realpath($mountSource = str_replace('\\040', ' ', (string) $fields[0])) ?: $mountSource;
        return preg_match('#/(md\d+)$#', $resolvedPath, $matches) === 1 ? $matches[1] : null;
    }
    return null;
}

/**
 * Parse mdadm activity details from /proc/mdstat.
 *
 * @return array<string,string>
 */
function pmssStorageHealthRaidActivitySummaryParse(string $activityLine): array
{
    $summary = array_fill_keys(['operation', 'progress', 'eta', 'speed'], '');

    foreach (
        [
            'operation' => '/\b(check|resync|recovery|reshape)\b/',
            'progress' => '/=\s*([0-9.]+%)/',
            'eta' => '/\bfinish=([^\s]+)/',
            'speed' => '/\bspeed=([^\s]+)/',
        ] as $key => $pattern
    ) {
        if (preg_match($pattern, $activityLine, $matches) === 1) {
            $summary[$key] = $matches[1];
        }
    }

    return $summary;
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

    $raidEntries = $raidEntries ?? pmssStorageHealthSnapshotRaid(date('c'));

    $degradedNotice = null;
    foreach ($raidEntries as $entry) {
        if ((string) ($entry['array'] ?? '') !== $homeArray) {
            continue;
        }

        $activityLine = trim((string) ($entry['resync'] ?? ''));
        if ($activityLine !== '') {
            $summary = pmssStorageHealthRaidActivitySummaryParse($activityLine);
            if ($summary['operation'] !== '') {
                return $summary + ['array' => $homeArray];
            }
        }

        if (in_array('degraded', (array) ($entry['flags'] ?? []), true)) {
            $degradedNotice = [
                'array' => $homeArray,
                'flags' => ['degraded'],
            ];
        }
    }

    return $degradedNotice;
}

/**
 * Build the shared GUI notice for /home RAID maintenance.
 */
function pmssStorageHealthHomeRaidNoticeHtmlBuild($activity): string
{
    if (!is_array($activity) || empty($activity['array'])) {
        return '';
    }

    $flags = (array) ($activity['flags'] ?? []);
    if (in_array('degraded', $flags, true) && empty($activity['operation'])) {
        return <<<HTML
<div class="pmss-raid-notice pmss-raid-notice-error" role="alert" aria-live="assertive">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#9940;</span> Storage array degraded</strong>
    <p>A drive in your server's storage array has failed. Your data is still accessible but the array is running without full redundancy. Please contact support if you experience issues.</p>
</div>
HTML;
    }

    if (empty($activity['operation'])) {
        return '';
    }

    $parts = [];
    foreach (['progress' => 'Progress', 'eta' => 'ETA', 'speed' => 'Speed'] as $key => $label) {
        if (!empty($activity[$key])) {
            $parts[] = $label.': '.htmlspecialchars((string) $activity[$key], ENT_QUOTES, 'UTF-8');
        }
    }

    $detailHtml = empty($parts)
        ? ''
        : '<div class="pmss-raid-meta">'.implode(' <span aria-hidden="true">&bull;</span> ', $parts).'</div>';

    $operation = htmlspecialchars((string) $activity['operation'], ENT_QUOTES, 'UTF-8');
    $arrayName = htmlspecialchars((string) $activity['array'], ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="pmss-raid-notice" role="status" aria-live="polite">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#10071;</span> Home storage maintenance in progress</strong>
    <p>The <code>/home</code> RAID array {$arrayName} is running a {$operation}. Disk performance is temporarily lower.</p>
    {$detailHtml}
    <p>If possible, avoid heavy disk activity until the array work completes. Lighter use helps the maintenance finish sooner.</p>
</div>
HTML;
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

        $arrayName = (string) ($entry['array'] ?? 'md');
        return [
            'status' => 'performance_limited',
            'reason' => $isRebuild
                ? 'RAID '.$arrayName.' '.((string) ($entry['operation'] ?? 'resync')).' in progress'
                : 'RAID '.$arrayName.' degraded',
            'array' => $arrayName,
        ];
    }
    return null;
}
