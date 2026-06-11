<?php
/**
 * RAID storage-health snapshot, status, and customer notice helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/common.php';

/**
 * Read mdadm array status from `/proc/mdstat`.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthSnapshotRaid(string $timestamp, string $mdstatPath = '/proc/mdstat'): array
{
    $mdstat = @file_get_contents($mdstatPath);
    return $mdstat === false ? [] : pmssStorageHealthRaidEntriesParse($mdstat, $timestamp);
}

/**
 * Parse mdadm array status text.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthRaidEntriesParse(string $mdstat, string $timestamp): array
{
    $entries = [];
    foreach (preg_split('/\r?\n/', $mdstat) as $line) {
        $trimmed = trim($line);
        if (preg_match('/^(md\\d+)\\s*:\\s*(\\w+)\\s+(raid\\d)\\s+(.*)$/', $trimmed, $matches)) {
            $entries[] = [
                'timestamp' => $timestamp,
                'kind' => 'raid',
                'array' => $matches[1],
                'level' => $matches[3],
                'state' => $matches[2],
                'detail' => $matches[4],
                'ok' => true,
                'severity' => 'ok',
                'flags' => [],
            ];
            $last = count($entries) - 1;
            if (preg_match('/\\[(\\d+)\\/(\\d+)\\]\\s*\\[([U_]+)\\]/', $entries[$last]['detail'], $detail) && (strpos($detail[3], '_') !== false || (int) $detail[1] !== (int) $detail[2])) {
                $entries[$last]['severity'] = 'fail';
                $entries[$last]['ok'] = false;
                $entries[$last]['flags'][] = 'degraded';
            }
        } elseif (!empty($entries) && preg_match('/\b(check|resync|recovery|reshape)\b/', $line, $operation) === 1) {
            $last = count($entries) - 1;
            if ((string) $entries[$last]['severity'] === 'ok') {
                $entries[$last]['severity'] = 'warn';
            }
            $entries[$last]['ok'] = false;
            $entries[$last]['flags'][] = 'rebuild_in_progress';
            $entries[$last]['operation'] = $operation[1];
            $entries[$last]['resync'] = $trimmed;
        }
    }
    return $entries;
}

/** Resolve the md array that backs /home, if /home is mounted directly on md. */
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
        $source = str_replace('\\040', ' ', (string) $fields[0]);
        return preg_match('#/(md\d+)$#', @realpath($source) ?: $source, $matches) === 1 ? $matches[1] : null;
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
    foreach (['operation' => '/\b(check|resync|recovery|reshape)\b/', 'progress' => '/=\s*([0-9.]+%)/', 'eta' => '/\bfinish=([^\s]+)/', 'speed' => '/\bspeed=([^\s]+)/'] as $key => $pattern) {
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

    $degradedNotice = null;
    foreach ($raidEntries ?? pmssStorageHealthSnapshotRaid(date('c')) as $entry) {
        if ((string) ($entry['array'] ?? '') !== $homeArray) {
            continue;
        }
        $summary = pmssStorageHealthRaidActivitySummaryParse(trim((string) ($entry['resync'] ?? '')));
        if ($summary['operation'] !== '') {
            return $summary + ['array' => $homeArray];
        }
        if (in_array('degraded', (array) ($entry['flags'] ?? []), true)) {
            $degradedNotice = ['array' => $homeArray, 'flags' => ['degraded']];
        }
    }
    return $degradedNotice;
}

/** Build the shared GUI notice for /home RAID maintenance. */
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
    $detailHtml = empty($parts) ? '' : '<div class="pmss-raid-meta">'.implode(' <span aria-hidden="true">&bull;</span> ', $parts).'</div>';
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
        $array = (string) ($entry['array'] ?? 'md');
        return ['status' => 'performance_limited', 'reason' => $isRebuild ? 'RAID '.$array.' '.((string) ($entry['operation'] ?? 'resync')).' in progress' : 'RAID '.$array.' degraded', 'array' => $array];
    }
    return null;
}
