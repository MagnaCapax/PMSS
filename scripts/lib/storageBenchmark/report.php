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
require_once dirname(__DIR__).'/runtime/cli.php';

/** Convert untrusted JSONL display values to printable scalar text. */
function storageBenchmarkScalarDisplay($value): string
{
    return is_scalar($value) ? (string) $value : '';
}

/** Read a numeric metric from untrusted JSONL entries without PHP coercion warnings. */
function storageBenchmarkMetricFloat(array $metrics, string $key): float
{
    $value = $metrics[$key] ?? 0;
    if (!is_int($value) && !is_float($value)
        && !(is_string($value) && is_numeric(trim($value)))) return 0.0;
    $number = (float) (is_string($value) ? trim($value) : $value);
    // Numeric JSON tokens and strings can overflow during decoding or casting.
    return is_finite($number) ? $number : 0.0;
}

/** Return the metrics object only when the decoded JSONL shape is valid. */
function storageBenchmarkEntryMetrics(array $entry): array { return is_array($entry['metrics'] ?? null) ? $entry['metrics'] : []; }

/** Return a printable metric for the preflight summary, defaulting when malformed. */
function storageBenchmarkPreflightDisplay(array $entry, string $key): string
{
    $value = storageBenchmarkScalarDisplay($entry[$key] ?? null);
    return $value !== '' ? $value : 'n/a';
}

/** Return a safe device grouping key from an untrusted benchmark entry. */
function storageBenchmarkEntryDeviceName(array $entry): ?string
{
    $device = storageBenchmarkScalarDisplay($entry['device'] ?? null);
    return ($device !== '' && $device !== '0') ? $device : null;
}

/** Render the selected run in encounter order without executing benchmarks. */
function storageBenchmarkShowLast(string $jsonLog): int
{
    if (!is_file($jsonLog)) {
        return pmssCliReturnWithStderr("No log at {$jsonLog}\n", 1);
    }

    // Select the latest timestamp while retaining every row of that run.
    $runs = [];
    $lastId = '';
    $lastTs = '';
    foreach (pmssJsonLineFileRead($jsonLog) as $entry) {
        if (!is_string($entry['run_id'] ?? null) || $entry['run_id'] === '') continue;
        $runs[$entry['run_id']][] = $entry;
        $runTs = is_string($entry['run_ts'] ?? null) ? $entry['run_ts'] : '';
        if ($runTs > $lastTs) {
            $lastTs = $runTs;
            $lastId = $entry['run_id'];
        }
    }

    if ($lastId === '') {
        echo "No runs found.\n";
        return 0;
    }

    $run = $runs[$lastId];
    unset($runs); // Release other runs before rendering, as the former reader did.
    $label = storageBenchmarkScalarDisplay($run[0]['label'] ?? '');
    echo "\n== Storage benchmark (last run) ==\nRun ID: {$lastId}  Time: ".storageBenchmarkScalarDisplay($run[0]['run_ts'] ?? '').($label !== '' ? '  Label: '.$label : '')."\n\n";

    // Print only the first preflight recorded in the selected run.
    foreach ($run as $entry) {
        if (storageBenchmarkScalarDisplay($entry['test'] ?? '') !== 'preflight-idle') continue;
        echo "Preflight: ioping=".storageBenchmarkPreflightDisplay($entry, 'ioping_avg_ms')." ms util=".storageBenchmarkPreflightDisplay($entry, 'iostat_util_pct')."%\n\n";
        break;
    }

    // File rows require a printable rw parameter and no device grouping key.
    echo "File-backed tests\n";
    echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95\twrite_p95\n";
    foreach ($run as $entry) {
        $test = storageBenchmarkScalarDisplay($entry['test'] ?? '');
        if ($test === '' || storageBenchmarkEntryDeviceName($entry) !== null
            || !is_array($entry['params'] ?? null)
            || storageBenchmarkScalarDisplay($entry['params']['rw'] ?? '') === '') continue;
        $metrics = storageBenchmarkEntryMetrics($entry);
        printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n", $test, storageBenchmarkMetricFloat($metrics, 'read_bw_MBps'), storageBenchmarkMetricFloat($metrics, 'write_bw_MBps'), storageBenchmarkMetricFloat($metrics, 'read_iops'), storageBenchmarkMetricFloat($metrics, 'write_iops'), storageBenchmarkMetricFloat($metrics, 'read_p95_ms'), storageBenchmarkMetricFloat($metrics, 'write_p95_ms'));
    }

    // Preserve device encounter order, including headings for unknown test types.
    echo "\nPer-device tests\n";
    $devices = [];
    foreach ($run as $entry) if (($device = storageBenchmarkEntryDeviceName($entry)) !== null) $devices[$device][] = $entry;
    foreach ($devices as $device => $entries) {
        echo $device."\n";
        foreach ($entries as $entry) {
            $test = storageBenchmarkScalarDisplay($entry['test'] ?? '');
            $metrics = storageBenchmarkEntryMetrics($entry);
            if ($test === 'device-seqread-dd') printf("  %-18s seq_MB/s=%.2f t=%.2fs\n", $test, storageBenchmarkMetricFloat($metrics, 'seqread_MBps'), storageBenchmarkMetricFloat($metrics, 'elapsed_s'));
            elseif (strpos($test, 'dev-randread') === 0) printf("  %-18s read_MB/s=%.2f IOPS=%.1f p95=%.2fms\n", $test, storageBenchmarkMetricFloat($metrics, 'read_bw_MBps'), storageBenchmarkMetricFloat($metrics, 'read_iops'), storageBenchmarkMetricFloat($metrics, 'read_p95_ms'));
            elseif ($test === 'device-ioping') printf("  %-18s avg_ms=%.2f\n", $test, storageBenchmarkMetricFloat($metrics, 'ioping_avg_ms'));
        }
    }
    return 0;
}

/** Live summaries retain the same formatting as the CLI's historical output. */
function storageBenchmarkPrintFileSummary(string $targetDir, string $jsonLog, string $label, array $summary): void { echo "\n== Storage benchmark summary ".($label !== '' ? '(' . $label . ' on '.$targetDir.')' : "(on {$targetDir})")." ==\n"; echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95_ms\twrite_p95_ms\n"; foreach ($summary as $row) printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n", ...$row); echo "\nJSON log: {$jsonLog}\n"; }
/** Compare available peer measurements; missing samples do not enter the median. */
function storageBenchmarkPeerMedian(array $peer, string $key): float
{
    $values = array_filter(array_column($peer, $key), static function ($value): bool { return $value !== null; });
    sort($values);
    $n = count($values);
    if ($n === 0) return 0.0;
    $m = (int) floor(($n - 1) / 2);
    return $n % 2 ? (float) $values[$m] : (($values[$m] + $values[$m + 1]) / 2);
}

function storageBenchmarkPrintPeerWarnings(array $peer): void
{
    $medDd = storageBenchmarkPeerMedian($peer, 'dd_mb'); $medIop = storageBenchmarkPeerMedian($peer, 'iop_ms'); $med4k = storageBenchmarkPeerMedian($peer, 'fio4k_mb');
    foreach ($peer as $p => $r) { if ($medDd > 0 && isset($r['dd_mb']) && $r['dd_mb'] < 0.6 * $medDd) echo "WARN: {$p} seqread < 60% median\n"; if ($medIop > 0 && isset($r['iop_ms']) && $r['iop_ms'] > max(50, 2 * $medIop)) echo "WARN: {$p} ioping > 2x median\n"; if ($med4k > 0 && isset($r['fio4k_mb']) && $r['fio4k_mb'] < 0.5 * $med4k) echo "WARN: {$p} 4k randread < 50% median\n"; }
}
