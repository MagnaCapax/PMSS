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
    if (($fh = @fopen($path, 'r')) === false) {
        return [];
    }
    $last = [];
    while (($line = fgets($fh)) !== false) {
        if (is_array($entry = json_decode($line, true))) {
            $last[(string) ($entry['kind'] ?? '').'::'.(string) ($entry['device'] ?? ($entry['array'] ?? 'global'))] = $entry;
        }
    }
    fclose($fh);
    return $last;
}

/**
 * Execute a shell command with captured output (no streaming).
 *
 * @return array{rc:int,stdout:string,stderr:string}
 */
function pmssStorageHealthExecCapture(string $cmd, int $timeoutSec = 20): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $bash = '/bin/bash -lc '.escapeshellarg($cmd);
    $process = proc_open($bash, $descriptor, $pipes);
    if (!is_resource($process)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $startedAt = microtime(true);
    $timedOut = false;

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        $read = [];
        if (!feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $read[] = $pipes[2];
        }
        if (empty($read)) {
            break;
        }
        $write = $except = [];
        $ready = stream_select($read, $write, $except, 0, 200000);
        if ($ready === false) {
            break;
        }
        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            if ($stream === $pipes[1]) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }
        if ((microtime(true) - $startedAt) > $timeoutSec) {
            $timedOut = true;
            break;
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    if ($timedOut) {
        if (function_exists('proc_terminate')) {
            @proc_terminate($process);
        }
        @proc_close($process);
        return ['rc' => 124, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    $rc = proc_close($process);
    return ['rc' => (int) $rc, 'stdout' => $stdout, 'stderr' => $stderr];
}

function pmssStorageHealthSeverityMax(string $a, string $b): string
{
    static $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
    return (($rank[$b] ?? 1) > ($rank[$a] ?? 1)) ? $b : $a;
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
    $summary = [
        'operation' => '',
        'progress' => '',
        'eta' => '',
        'speed' => '',
    ];

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
 * @return array<string,string>|null
 */
function pmssStorageHealthHomeRaidActivity(?string $mountsPath = null, ?array $raidEntries = null): ?array
{
    $homeArray = pmssStorageHealthHomeArrayResolve($mountsPath);
    if ($homeArray === null) {
        return null;
    }

    $raidEntries = $raidEntries ?? pmssStorageHealthSnapshotRaid(date('c'));

    foreach ($raidEntries as $entry) {
        $activityLine = trim((string) ($entry['resync'] ?? ''));
        if ((string) ($entry['array'] ?? '') !== $homeArray || $activityLine === '') {
            continue;
        }

        $summary = pmssStorageHealthRaidActivitySummaryParse($activityLine);
        if ($summary['operation'] === '') {
            continue;
        }

        return $summary + ['array' => $homeArray];
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
