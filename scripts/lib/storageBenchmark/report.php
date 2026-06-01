<?php
/**
 * Last-run report renderer for the storage benchmark JSONL log.
 *
 * The benchmark executor writes entries; this module owns read-only summary
 * display so the CLI does not mix execution branches with presentation rules.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/log.php';

/** Convert untrusted JSONL display values to printable scalar text. */
function storageBenchmarkScalarDisplay($value): string
{
    if (is_string($value) || is_int($value) || is_float($value)) return (string) $value;
    return is_bool($value) ? ($value ? '1' : '') : '';
}

/** Read a numeric metric from untrusted JSONL entries without PHP coercion warnings. */
function storageBenchmarkMetricFloat(array $metrics, string $key): float
{
    $value = $metrics[$key] ?? 0;
    if (is_int($value) || is_float($value)) return (float) $value;
    return (is_string($value) && is_numeric(trim($value))) ? (float) trim($value) : 0.0;
}

/** Return the metrics object only when the decoded JSONL shape is valid. */
function storageBenchmarkEntryMetrics(array $entry): array { return (isset($entry['metrics']) && is_array($entry['metrics'])) ? $entry['metrics'] : []; }

/** Return a printable metric for the preflight summary, defaulting when malformed. */
function storageBenchmarkPreflightDisplay(array $entry, string $key): string
{
    $value = array_key_exists($key, $entry) ? storageBenchmarkScalarDisplay($entry[$key]) : '';
    return $value !== '' ? $value : 'n/a';
}

/** Check whether a decoded benchmark entry has the file-test parameter shape. */
function storageBenchmarkEntryHasFileRw(array $entry): bool
{
    return isset($entry['params']) && is_array($entry['params'])
        && storageBenchmarkScalarDisplay($entry['params']['rw'] ?? '') !== '';
}

/** Return a safe device grouping key from an untrusted benchmark entry. */
function storageBenchmarkEntryDeviceName(array $entry): ?string
{
    $device = array_key_exists('device', $entry) ? storageBenchmarkScalarDisplay($entry['device']) : '';
    return ($device !== '' && $device !== '0') ? $device : null;
}

/** @return array{0:string,1:array<int,array<string,mixed>>} */
function storageBenchmarkLastRunRead(string $jsonLog): array
{
    $runs = [];
    $lastId = '';
    $lastTs = '';
    foreach (pmssJsonLineFileRead($jsonLog) as $entry) {
        if (!isset($entry['run_id']) || !is_string($entry['run_id']) || $entry['run_id'] === '') continue;
        $runId = $entry['run_id'];
        $runs[$runId][] = $entry;
        $runTs = (isset($entry['run_ts']) && is_string($entry['run_ts'])) ? $entry['run_ts'] : '';
        if ($runTs > $lastTs) {
            $lastTs = $runTs;
            $lastId = $runId;
        }
    }

    return [$lastId, $lastId !== '' ? $runs[$lastId] : []];
}

/** Print preflight, if the selected run recorded one. */
function storageBenchmarkReportPrintPreflight(array $run): void
{
    foreach ($run as $entry) {
        if (storageBenchmarkScalarDisplay($entry['test'] ?? '') !== 'preflight-idle') continue;
        echo "Preflight: ioping=".storageBenchmarkPreflightDisplay($entry, 'ioping_avg_ms')." ms util=".storageBenchmarkPreflightDisplay($entry, 'iostat_util_pct')."%\n\n";
        return;
    }
}

/** Print file-backed benchmark rows. */
function storageBenchmarkReportPrintFileRows(array $run): void
{
    echo "File-backed tests\n";
    echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95\twrite_p95\n";
    foreach ($run as $entry) {
        $test = storageBenchmarkScalarDisplay($entry['test'] ?? '');
        if ($test === '' || storageBenchmarkEntryDeviceName($entry) !== null || !storageBenchmarkEntryHasFileRw($entry)) continue;
        $metrics = storageBenchmarkEntryMetrics($entry);
        printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n", $test, storageBenchmarkMetricFloat($metrics, 'read_bw_MBps'), storageBenchmarkMetricFloat($metrics, 'write_bw_MBps'), storageBenchmarkMetricFloat($metrics, 'read_iops'), storageBenchmarkMetricFloat($metrics, 'write_iops'), storageBenchmarkMetricFloat($metrics, 'read_p95_ms'), storageBenchmarkMetricFloat($metrics, 'write_p95_ms'));
    }
}

/** Print grouped per-device rows while preserving JSONL encounter order. */
function storageBenchmarkReportPrintDeviceRows(array $run): void
{
    echo "\nPer-device tests\n";
    $devices = [];
    foreach ($run as $entry) if (($device = storageBenchmarkEntryDeviceName($entry)) !== null) $devices[$device][] = $entry;
    foreach ($devices as $device => $entries) {
        echo $device."\n";
        foreach ($entries as $entry) storageBenchmarkReportPrintDeviceEntry($entry);
    }
}

/** Print one per-device entry when its test type has a report row. */
function storageBenchmarkReportPrintDeviceEntry(array $entry): void
{
    $test = storageBenchmarkScalarDisplay($entry['test'] ?? '');
    $metrics = storageBenchmarkEntryMetrics($entry);
    if ($test === 'device-seqread-dd') printf("  %-18s seq_MB/s=%.2f t=%.2fs\n", $test, storageBenchmarkMetricFloat($metrics, 'seqread_MBps'), storageBenchmarkMetricFloat($metrics, 'elapsed_s'));
    elseif (strpos($test, 'dev-randread') === 0) printf("  %-18s read_MB/s=%.2f IOPS=%.1f p95=%.2fms\n", $test, storageBenchmarkMetricFloat($metrics, 'read_bw_MBps'), storageBenchmarkMetricFloat($metrics, 'read_iops'), storageBenchmarkMetricFloat($metrics, 'read_p95_ms'));
    elseif ($test === 'device-ioping') printf("  %-18s avg_ms=%.2f\n", $test, storageBenchmarkMetricFloat($metrics, 'ioping_avg_ms'));
}

function storageBenchmarkShowLast(string $jsonLog): int
{
    if (!is_file($jsonLog)) {
        fwrite(STDERR, "No log at {$jsonLog}\n");
        return 1;
    }

    [$lastId, $run] = storageBenchmarkLastRunRead($jsonLog);
    if ($lastId === '') {
        echo "No runs found.\n";
        return 0;
    }

    $first = $run[0];
    $label = storageBenchmarkScalarDisplay($first['label'] ?? '');
    $labelStr = $label !== '' ? ('  Label: '.$label) : '';
    echo "\n== Storage benchmark (last run) ==\nRun ID: {$lastId}  Time: ".storageBenchmarkScalarDisplay($first['run_ts'] ?? '').$labelStr."\n\n";
    storageBenchmarkReportPrintPreflight($run);
    storageBenchmarkReportPrintFileRows($run);
    storageBenchmarkReportPrintDeviceRows($run);
    return 0;
}
