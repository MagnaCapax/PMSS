<?php
/** Bounded latency acquisition and parsing; loaded by environment.php. @license GPL-3.0-only */

const PMSS_IOPING_PROBE_COUNT = 60;
const PMSS_IOPING_PROBE_INTERVAL = '0.1';
// Hard timeout: long enough that a stalled request is measured, not cut off (ADR 0274).
const PMSS_IOPING_PROBE_TIMEOUT = 60;
// ioping stops sending after this many seconds and prints what completed (ADR 0274), so a slow disk yields
// fewer samples instead of a killed probe; the timeout above stays as the hard backstop.
const PMSS_IOPING_PROBE_WORK_TIME = '12';

/**
 * Run the canonical direct-I/O 4 KiB latency probe and return raw output. $keep reuses ioping.tmp in a
 * directory target (-k) so a periodic probe does not create, write and sync a new file every run (ADR 0274).
 */
function pmssIopingProbeOutput(?string $target, bool $keep = false): ?string
{
    // Keep malformed paths on the null-result path before shell quoting can throw.
    if ($target !== null && pmssFilesystemPathHasNulByte($target)) return null;
    $bin = pmssCommandPath('ioping');
    if ($bin === '' || $target === null || trim($target) === '') return null;
    $argv = [$bin, '-c', (string) PMSS_IOPING_PROBE_COUNT, '-i', PMSS_IOPING_PROBE_INTERVAL, '-w', PMSS_IOPING_PROBE_WORK_TIME];
    if ($keep) $argv[] = '-k';
    $command = pmssCommandArgvShellQuote(array_merge($argv, ['-D', $target]));
    // Single-instance guard (#751): bind the lock to the ioping process itself, so a
    // direct-I/O probe orphaned in D-state past the timeout wrapper blocks only its
    // OWN next tick instead of piling up N-deep and DoSing the host it measures.
    // A PHP-level flock() would release when the wrapper is proc_terminate'd at the
    // timeout while the stuck probe still holds the device queue — so keep the lock
    // on the exec'd command (same idiom as the storage-health probes, Refs #758).
    $flock = pmssCommandPath('flock');
    if ($flock !== '') {
        $lockPath = pmssRuntimeLockPath('pmss-ioping-'.(preg_replace('#[^A-Za-z0-9]+#', '-', trim((string) $target, '/')) ?: 'root').'.lock');
        $command = escapeshellarg($flock).' -xn -E 75 '.escapeshellarg($lockPath).' '.$command;
    }
    // flock -xn exits 75 without launching ioping when a prior probe still holds the
    // lock; that produces empty output -> null -> callers report the existing 'na'
    // sentinel for that tick (a skipped probe under contention is correct).
    $result = pmssCommandCapture($command, PMSS_IOPING_PROBE_TIMEOUT);
    $rc = (int) ($result['rc'] ?? 1);
    // Killed at the hard timeout (124 TERM, 137 KILL): a request was still pending past the work time, so the
    // disk took at least the timeout to answer. Keep the samples that completed and add that wait as one censored
    // sample, so the slowest moments read slow instead of missing (ADR 0274). Only sample lines are returned, so
    // average-based callers still get null.
    if ($rc === 124 || $rc === 137) {
        $lines = [];
        foreach (pmssIopingSamplesMsFromOutput((string) ($result['stdout'] ?? '')) as $ms) $lines[] = 'time='.sprintf('%.6F', $ms).' ms';
        $lines[] = 'censored time='.(PMSS_IOPING_PROBE_TIMEOUT * 1000).' ms';
        return implode("\n", $lines);
    }
    // Any other failure (no ioping, flock skip 75, bad target) is not a measurement.
    if ($rc !== 0) return null;
    $out = trim($result['stdout'] !== '' ? $result['stdout'] : $result['stderr']);
    return $out === '' ? null : $out;
}

function pmssIopingValueToMs(float $value, string $unit): float { return strtolower($unit) === 'us' ? $value / 1000.0 : (strtolower($unit) === 's' ? $value * 1000.0 : $value); }
/** Reject malformed or overflowing averages before publishing a latency sample. */
function pmssIopingAverageMsFromOutput(string $output): ?float
{
    if (!preg_match('/min\/avg\/max\/mdev\s*=\s*[^\/]+\/\s*([0-9]+(?:\.[0-9]*)?|\.[0-9]+)\s*(us|ms|s)\s*\//i', $output, $m)) return null;
    return pmssFiniteFloat(pmssIopingValueToMs((float) $m[1], $m[2]));
}

/** Parse per-request ioping samples, ignoring summary lines. */
function pmssIopingSamplesMsFromOutput(string $output): array
{
    preg_match_all('/\btime\s*=\s*([0-9]+(?:\.[0-9]*)?|\.[0-9]+)\s*(us|ms|s)\b/i', $output, $matches, PREG_SET_ORDER);
    $values = [];
    foreach ($matches as $match) {
        // Seconds-to-milliseconds conversion can overflow even a finite input.
        $value = pmssFiniteFloat(pmssIopingValueToMs((float) $match[1], $match[2]));
        if ($value !== null) $values[] = $value;
    }
    return $values;
}

function pmssIopingMedianMsFromSamples(array $values): ?float
{
    if (empty($values)) return null;
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $mid = (int) floor(($count - 1) / 2);
    if ($count % 2 === 1) return (float) $values[$mid];
    $sum = (float) $values[$mid] + (float) $values[$mid + 1];
    // Keep legacy rounding unless adding two finite latencies overflows.
    return is_finite($sum) ? $sum / 2.0 : (float) $values[$mid] / 2.0 + (float) $values[$mid + 1] / 2.0;
}

function pmssIopingAverageMs(?string $target): ?float
{
    $out = pmssIopingProbeOutput($target);
    return $out === null ? null : pmssIopingAverageMsFromOutput($out);
}

function pmssIopingMedianMs(?string $target, bool $keep = false): ?float
{
    $out = pmssIopingProbeOutput($target, $keep);
    if ($out === null) return null;
    return pmssIopingMedianMsFromSamples(pmssIopingSamplesMsFromOutput($out)) ?? pmssIopingAverageMsFromOutput($out);
}
