<?php
/** Pure storage metrics and finding policies shared by SMART, NVMe and RAID. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime/environment.php';

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
function pmssStorageHealthEntryFinalize(array $entry, array $flags, ?string $error = null): array
{
    if ($error !== null) {
        $entry['error'] = $error;
    }
    $entry['flags'] = array_values(array_unique($flags));
    $entry['severity'] = pmssStorageHealthFlagsSeverity($flags);
    $entry['ok'] = ($entry['severity'] === 'ok');
    return $entry;
}

/** Derive status from findings: failures win; informational flags remain OK. */
function pmssStorageHealthFlagsSeverity(array $flags): string
{
    if (array_intersect($flags, ['health_not_ok', 'nvme_critical_warning', 'degraded']) !== []) {
        return 'fail';
    }
    return array_diff($flags, ['standby', 'reallocated_increase', 'link_errors_increase', 'err_log_increase']) === [] ? 'ok' : 'warn';
}

/** Add the nullable bitmap observation and derive RAID status without deduplicating activity flags. */
function pmssStorageHealthRaidBitmapApply(array $entry, ?bool $bitmap): array
{
    $entry['write_intent_bitmap'] = $bitmap;
    if ($bitmap !== true) {
        $entry['flags'][] = $bitmap === false ? 'no_write_intent_bitmap' : 'write_intent_bitmap_unknown';
        $entry['message'] = $bitmap === false
            ? 'No write-intent bitmap: an unclean stop will force a full resync.'
            : 'Write-intent bitmap state unknown: mdadm detail unavailable or incomplete.';
    }
    $entry['severity'] = pmssStorageHealthFlagsSeverity($entry['flags']);
    $entry['ok'] = $entry['severity'] === 'ok';
    return $entry;
}

/** Return findings only for integer counters that grew since the last snapshot. */
function pmssStorageHealthMetricIncreaseFlags(array $metrics, ?array $previous, array $metricFlags): array
{
    $flags = [];
    foreach ($metricFlags as $metric => $flag) {
        $current = $metrics[$metric] ?? null;
        $old = $previous[$metric] ?? null;
        if (!is_int($current) || !is_int($old) || $current <= $old) continue;
        $flags[] = $flag;
    }
    return $flags;
}

/** Apply ordered counter patterns; callers choose lines or one whole-output chunk. */
function pmssStorageHealthMetricsParse(array $chunks, array $patterns, array $metrics): array
{
    foreach ($chunks as $chunk) {
        foreach ($patterns as $pattern => $targets) {
            if (preg_match($pattern, $chunk, $matches) !== 1) continue;
            // Oversized probe counters are unavailable, never saturated measurements.
            $value = pmssUnsignedDecimalIntParse($matches[1]);
            foreach ($targets as $target) $metrics[$target] = $value;
        }
    }
    return $metrics;
}

/** Select the first reached threshold per metric, retaining rule and flag order. */
function pmssStorageHealthMetricThresholdFlags(array $metrics, array $rules): array
{
    $flags = [];
    foreach ($rules as $metric => $thresholds) {
        foreach ($thresholds as $threshold => $flag) {
            if (($metrics[$metric] ?? 0) < $threshold) continue;
            $flags[] = $flag;
            break;
        }
    }
    return $flags;
}
