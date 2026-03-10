<?php
/**
 * Shared helpers for storage health snapshot/reporting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Read the latest entry per (kind, device/array) key from a JSONL file.
 *
 * @return array<string, array<string, mixed>>
 */
function pmssStorageHealthReadLastEntries(string $path): array
{
    $fh = is_file($path) ? fopen($path, 'r') : false;
    if ($fh === false) {
        return [];
    }
    $last = [];
    while (($line = fgets($fh)) !== false) {
        $j = json_decode($line, true);
        if (!is_array($j)) {
            continue;
        }
        $kind = (string) ($j['kind'] ?? '');
        $id = (string) ($j['device'] ?? ($j['array'] ?? 'global'));
        $key = $kind.'::'.$id;
        $last[$key] = $j;
    }
    fclose($fh);
    return $last;
}

function pmssStorageHealthSeverityMax(string $a, string $b): string
{
    $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
    return (($rank[$b] ?? 1) > ($rank[$a] ?? 1)) ? $b : $a;
}

/**
 * Read the device mounted at the requested mount point from /proc/mounts.
 */
function pmssStorageHealthMountSourceRead(string $mountPoint, ?string $mountsPath = null): ?string
{
    $mountsPath = ($mountsPath !== null && $mountsPath !== '') ? $mountsPath : '/proc/mounts';
    $mounts = @file($mountsPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($mounts)) {
        return null;
    }

    foreach ($mounts as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (!is_array($fields) || count($fields) < 2) {
            continue;
        }

        $candidateMount = str_replace('\\040', ' ', (string) $fields[1]);
        if ($candidateMount !== $mountPoint) {
            continue;
        }

        return str_replace('\\040', ' ', (string) $fields[0]);
    }

    return null;
}

/**
 * Resolve the md array that backs /home, if /home is mounted directly on md.
 */
function pmssStorageHealthHomeArrayResolve(?string $mountsPath = null): ?string
{
    $mountSource = pmssStorageHealthMountSourceRead('/home', $mountsPath);
    if ($mountSource === null) {
        return null;
    }

    $resolvedPath = @realpath($mountSource);
    if (!is_string($resolvedPath) || $resolvedPath === '') {
        $resolvedPath = $mountSource;
    }

    if (preg_match('#/(md\d+)$#', $resolvedPath, $matches) === 1) {
        return $matches[1];
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
    $summary = [
        'operation' => '',
        'progress' => '',
        'eta' => '',
        'speed' => '',
    ];

    if (preg_match('/\b(check|resync|recovery|reshape)\b/', $activityLine, $matches) === 1) {
        $summary['operation'] = $matches[1];
    }
    if (preg_match('/=\s*([0-9.]+%)/', $activityLine, $matches) === 1) {
        $summary['progress'] = $matches[1];
    }
    if (preg_match('/\bfinish=([^\s]+)/', $activityLine, $matches) === 1) {
        $summary['eta'] = $matches[1];
    }
    if (preg_match('/\bspeed=([^\s]+)/', $activityLine, $matches) === 1) {
        $summary['speed'] = $matches[1];
    }

    return $summary;
}

/**
 * Read current activity for the md array backing /home.
 *
 * @param array<int, array<string,mixed>>|null $raidEntries
 * @return array<string,string>|null
 */
function pmssStorageHealthHomeRaidActivity(?string $mountsPath = null, ?array $raidEntries = null): ?array
{
    $homeArray = pmssStorageHealthHomeArrayResolve($mountsPath);
    if ($homeArray === null) {
        return null;
    }

    if ($raidEntries === null) {
        $raidEntries = pmssStorageHealthSnapshotRaid(date('c'));
    }

    foreach ($raidEntries as $entry) {
        if ((string) ($entry['array'] ?? '') !== $homeArray) {
            continue;
        }

        $activityLine = trim((string) ($entry['resync'] ?? ''));
        if ($activityLine === '') {
            continue;
        }

        $summary = pmssStorageHealthRaidActivitySummaryParse($activityLine);
        if ($summary['operation'] === '') {
            continue;
        }

        $summary['array'] = $homeArray;
        return $summary;
    }

    return null;
}

/**
 * Build the shared GUI notice for /home RAID maintenance.
 */
function pmssStorageHealthHomeRaidNoticeHtmlBuild($activity): string
{
    if (!is_array($activity) || empty($activity['operation']) || empty($activity['array'])) {
        return '';
    }

    $parts = [];
    foreach (['progress' => 'Progress', 'eta' => 'ETA', 'speed' => 'Speed'] as $key => $label) {
        if (!empty($activity[$key])) {
            $parts[] = $label.': '.htmlspecialchars((string) $activity[$key], ENT_QUOTES, 'UTF-8');
        }
    }

    $detailHtml = '';
    if (!empty($parts)) {
        $detailHtml = '<div class="pmss-raid-meta">'.implode(' <span aria-hidden="true">&bull;</span> ', $parts).'</div>';
    }

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
        $arrayName = (string) ($entry['array'] ?? 'md');
        $isRebuild = in_array('rebuild_in_progress', $flags, true);
        $isDegraded = in_array('degraded', $flags, true) || (string) ($entry['severity'] ?? 'ok') !== 'ok';
        if (!$isRebuild && !$isDegraded) {
            continue;
        }

        $operation = (string) ($entry['operation'] ?? 'resync');
        $reason = $isRebuild ? "RAID {$arrayName} {$operation} in progress" : "RAID {$arrayName} degraded";
        return ['status' => 'performance_limited', 'reason' => $reason, 'array' => $arrayName];
    }
    return null;
}
