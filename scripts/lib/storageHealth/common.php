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
    $closePipe = static function (&$pipe): void {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    };
    $closeProcess = static function ($process): void {
        if (function_exists('proc_terminate')) {
            @proc_terminate($process);
        }
        @proc_close($process);
    };
    $abortProcess = static function ($process, array $pipes, string $stderr) use ($closePipe, $closeProcess): array {
        foreach ([0, 1, 2] as $index) {
            if (array_key_exists($index, $pipes)) {
                $closePipe($pipes[$index]);
            }
        }
        $closeProcess($process);
        return ['rc' => 1, 'stdout' => '', 'stderr' => $stderr];
    };

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
    if (
        !isset($pipes[0], $pipes[1], $pipes[2])
        || !is_resource($pipes[0])
        || !is_resource($pipes[1])
        || !is_resource($pipes[2])
    ) {
        return $abortProcess($process, $pipes, 'proc_open pipes unavailable');
    }
    fclose($pipes[0]);
    if (!@stream_set_blocking($pipes[1], false) || !@stream_set_blocking($pipes[2], false)) {
        return $abortProcess($process, $pipes, 'proc_open pipes unavailable');
    }

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
            $stderr .= ($stderr !== '' ? "\n" : '').'stream_select failed';
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

    $closePipe($pipes[1]);
    $closePipe($pipes[2]);

    if ($timedOut) {
        $closeProcess($process);
        return ['rc' => 124, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    $rc = proc_close($process);
    if ($rc === -1 && function_exists('proc_get_status')) {
        $status = @proc_get_status($process);
        if (is_array($status) && isset($status['exitcode']) && is_int($status['exitcode']) && $status['exitcode'] >= 0) {
            $rc = $status['exitcode'];
        }
    }
    return ['rc' => (int) $rc, 'stdout' => $stdout, 'stderr' => $stderr];
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
