<?php
/**
 * Customer-side storage-health notices for the /home RAID array and host I/O.
 *
 * Reads /proc/mounts and /proc/mdstat (both world-readable kernel paths)
 * to detect whether the customer's /home is on an md array that's
 * currently degraded, resyncing, rebuilding, or reshaping. It also reads the
 * narrow host-pressure snapshot written by root cron. Returns rendered HTML
 * for info.php / welcome.php to embed in the panel.
 *
 * Lives in etc/skel/www/ because customer PHP runs as the customer UID
 * and cannot traverse /scripts/ (operator-only). The chained operator-side
 * lib at /scripts/lib/storageHealth/common.php was unreachable from
 * customer PHP, so the home-RAID notice silently disappeared from every
 * customer's panel after the helper moved to its current location.
 *
 * Functions inlined from /scripts/lib/storageHealth/common.php:
 *   pmssStorageHealthHomeArrayResolve, pmssStorageHealthRaidActivitySummaryParse,
 *   pmssStorageHealthHomeRaidActivity, pmssStorageHealthHomeRaidNoticeHtmlBuild
 * Plus pmssStorageHealthSnapshotRaid from /scripts/lib/storageHealth.php
 * (only the /proc/mdstat-reading subset; no SMART/NVMe paths, those are
 * operator-cron-only and write their data to /var/log/pmss/storage-health.jsonl).
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';

if (!function_exists('pmssStorageHealthHomeArrayResolve')) {
    /** Resolve which md array backs /home via /proc/mounts. Returns 'mdN' or null. */
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
}

if (!function_exists('pmssStorageHealthRaidActivitySummaryParse')) {
    /** Parse mdadm activity details (operation/progress/eta/speed) from a /proc/mdstat line. */
    function pmssStorageHealthRaidActivitySummaryParse(string $activityLine): array
    {
        $summary = array_fill_keys(['operation', 'progress', 'eta', 'speed'], '');
        foreach ([
            'operation' => '/\b(check|resync|recovery|reshape)\b/',
            'progress' => '/=\s*([0-9.]+%)/',
            'eta' => '/\bfinish=([^\s]+)/',
            'speed' => '/\bspeed=([^\s]+)/',
        ] as $key => $pattern) {
            if (preg_match($pattern, $activityLine, $matches) === 1) {
                $summary[$key] = $matches[1];
            }
        }
        return $summary;
    }
}

if (!function_exists('pmssStorageHealthSnapshotRaid')) {
    /** Read mdadm array status from /proc/mdstat (world-readable). */
    function pmssStorageHealthSnapshotRaid(string $timestamp): array
    {
        $mdstat = @file_get_contents('/proc/mdstat');
        if ($mdstat === false) {
            return [];
        }
        $entries = [];
        foreach (preg_split('/\r?\n/', $mdstat) as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(md\d+)\s*:\s*(\w+)\s+(raid\d+)\s+(.*)$/', $trimmed, $matches)) {
                $entry = [
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
                if (
                    preg_match('/\[(\d+)\/(\d+)\]\s*\[([U_]+)\]/', $entry['detail'], $detailMatches)
                    && (strpos($detailMatches[3], '_') !== false || (int) $detailMatches[1] !== (int) $detailMatches[2])
                ) {
                    $entry['severity'] = 'fail';
                    $entry['ok'] = false;
                    $entry['flags'][] = 'degraded';
                }
                $entries[] = $entry;
                continue;
            }
            if (!empty($entries) && preg_match('/\b(check|resync|recovery|reshape)\b/', $line, $operationMatches) === 1) {
                $lastIndex = count($entries) - 1;
                if ((string) $entries[$lastIndex]['severity'] === 'ok') {
                    $entries[$lastIndex]['severity'] = 'warn';
                }
                $entries[$lastIndex]['ok'] = false;
                $entries[$lastIndex]['flags'][] = 'rebuild_in_progress';
                $entries[$lastIndex]['operation'] = $operationMatches[1];
                $entries[$lastIndex]['resync'] = $trimmed;
            }
        }
        return $entries;
    }
}

if (!function_exists('pmssStorageHealthHomeRaidActivity')) {
    /**
     * Read current activity for the md array backing /home.
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
}

if (!function_exists('pmssStorageHealthHomeRaidNoticeHtmlBuild')) {
    /** Build the shared GUI notice for /home RAID maintenance / degradation. */
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
                $parts[] = $label.': '.pmssCustomerHtmlAttr($activity[$key]);
            }
        }
        $detailHtml = empty($parts)
            ? ''
            : '<div class="pmss-raid-meta">'.implode(' <span aria-hidden="true">&bull;</span> ', $parts).'</div>';
        $operation = pmssCustomerHtmlAttr($activity['operation']);
        $arrayName = pmssCustomerHtmlAttr($activity['array']);
        return <<<HTML
<div class="pmss-raid-notice" role="status" aria-live="polite">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#10071;</span> Home storage maintenance in progress</strong>
    <p>The <code>/home</code> RAID array {$arrayName} is running a {$operation}. Disk performance is temporarily lower.</p>
    {$detailHtml}
    <p>If possible, avoid heavy disk activity until the array work completes. Lighter use helps the maintenance finish sooner.</p>
</div>
HTML;
    }
}

if (!function_exists('pmssStorageHealthHostPressureMetricFloat')) {
    /** Normalize one customer-readable pressure metric at the trust boundary. */
    function pmssStorageHealthHostPressureMetricFloat($value): ?float
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= 0 ? $number : null;
    }
}

if (!function_exists('pmssStorageHealthHostPressureStateRead')) {
    /**
     * Read a fresh host-pressure snapshot and return only alerting signals.
     *
     * Latency above the storage benchmark's 100ms idle gate alerts here. A
     * 20% full PSI average means all non-idle tasks stalled for at least one
     * minute of the five-minute window, avoiding transient warning churn.
     */
    function pmssStorageHealthHostPressureStateRead(
        string $path = '/var/log/pmss/host-pressure.json',
        ?int $now = null
    ): ?array {
        $raw = pmssCustomerFileRead($path);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 4096) {
            return null;
        }
        $payload = pmssJsonDecodeAssoc($raw);
        $timestamp = is_array($payload)
            ? pmssCustomerUnsignedIntegerValue($payload['timestamp'] ?? null)
            : null;
        $now = $now ?? time();
        if ($timestamp === null || $now <= 0 || $timestamp > $now || ($now - $timestamp) > 900) {
            return null;
        }

        $psi = pmssStorageHealthHostPressureMetricFloat($payload['psi_io_full_avg300'] ?? null);
        $latency = pmssStorageHealthHostPressureMetricFloat($payload['ioping_home_ms'] ?? null);
        $state = ['timestamp' => $timestamp];
        if ($psi !== null && $psi >= 20.0) $state['psi_io_full_avg300'] = $psi;
        if ($latency !== null && $latency > 100.0) $state['ioping_home_ms'] = $latency;
        return count($state) > 1 ? $state : null;
    }
}

if (!function_exists('pmssStorageHealthHostPressureNoticeHtmlBuild')) {
    /** Build a shared-server I/O pressure notice from validated alert state. */
    function pmssStorageHealthHostPressureNoticeHtmlBuild($state): string
    {
        if (!is_array($state)) return '';

        $details = [];
        if (isset($state['psi_io_full_avg300'])) {
            $details[] = 'I/O wait: '.number_format((float) $state['psi_io_full_avg300'], 1, '.', '').'% of the last 5 minutes';
        }
        if (isset($state['ioping_home_ms'])) {
            $details[] = 'Storage response: '.number_format((float) $state['ioping_home_ms'], 1, '.', '').' ms';
        }
        if (empty($details)) return '';

        $detailHtml = pmssCustomerHtmlAttr(implode(' | ', $details));
        return <<<HTML
<div class="pmss-raid-notice pmss-host-pressure-notice" role="status" aria-live="polite">
    <strong><span class="pmss-raid-icon" aria-hidden="true">&#10071;</span> Shared storage is under heavy load</strong>
    <p>Disk-backed services may respond more slowly while the server-wide I/O queue clears. Repeated service restarts will not make this condition clear faster.</p>
    <div class="pmss-raid-meta">{$detailHtml}</div>
</div>
HTML;
    }
}

if (!function_exists('pmssStorageHealthNoticeHtmlRead')) {
    /** Prefer the specific /home RAID explanation over a generic pressure alert. */
    function pmssStorageHealthNoticeHtmlRead(
        ?string $mountsPath = null,
        ?array $raidEntries = null,
        string $hostPressurePath = '/var/log/pmss/host-pressure.json',
        ?int $now = null
    ): string {
        $raidHtml = pmssStorageHealthHomeRaidNoticeHtmlBuild(
            pmssStorageHealthHomeRaidActivity($mountsPath, $raidEntries)
        );
        if ($raidHtml !== '') return $raidHtml;

        return pmssStorageHealthHostPressureNoticeHtmlBuild(
            pmssStorageHealthHostPressureStateRead($hostPressurePath, $now)
        );
    }
}
