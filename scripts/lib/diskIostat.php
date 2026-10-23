<?php
/**
 * Disk iostat collection helpers for the cron snapshot task.
 *
 * The cron entry remains intentionally small while this library owns device
 * filtering, shell-boundary construction, parser compatibility, and checked
 * writes for the files consumed by hallinta.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/systemStats.php';   // for pmssSystemStatsIopingMs() — reused, not re-implemented (DRY)
require_once __DIR__.'/diskIostat/devices.php';
require_once __DIR__.'/diskIostat/sample.php';

const PMSS_DISK_IOSTAT_HISTORY_LOG = '/var/log/pmss/iostat-history.log';
const PMSS_DISK_IOSTAT_HISTORY_RAW_LOG = '/var/log/pmss/iostat-history-raw.log';
// Leave startup and parser time while bounding the 120-second iostat interval.
const PMSS_DISK_IOSTAT_TIMEOUT_SECONDS = 180;

/**
 * Read node-level I/O pressure (PSI) full avg300 from /proc/pressure/io.
 *
 * full_avg300 = percentage of the last 300s that ALL non-idle tasks were stalled
 * on I/O — the sustained saturation signal consumed by hallinta's oversale gate.
 * Returns null when PSI is unavailable (kernel <4.20 / CONFIG_PSI=n) so the brain
 * treats it as "no signal" and falls back to its other gates (fail-safe: a missing
 * value must never gate provisioning closed).
 *
 * @return float|null
 */
function pmssDiskIostatReadPsiFullAvg300(string $psiPath = '/proc/pressure/io'): ?float
{
    if (!is_readable($psiPath)) {
        return null;
    }
    $raw = @file_get_contents($psiPath);
    // Share validation while retaining this consumer's unrounded scalar result.
    return is_string($raw) ? pmssSystemStatsPsiMetricFromRaw($raw, 'full', 'avg300') : null;
}

/**
 * Read median ioping latency to /home in milliseconds for hallinta's I/O-health gate.
 *
 * Reuses the canonical pmssSystemStatsIopingMs() probe (DRY) rather than parsing it
 * positionally out of system-stats.log (which would reintroduce the column-shift
 * fragility class). Returns null when ioping is unavailable or the probe yields 'na'
 * so the brain treats a missing measurement as no-signal (fail-safe — never gates
 * provisioning on an absent value, in EITHER direction).
 *
 * @return float|null
 */
function pmssDiskIostatReadIopingHomeMs(): ?float
{
    $raw = pmssSystemStatsIopingMs('/home');           // e.g. "0.2ms" or "na"
    if (!is_string($raw) || !preg_match('/\A([0-9]+(?:\.[0-9]+)?)ms\z/', $raw, $matches)) {
        return null;
    }
    $value = (float) $matches[1];
    return is_finite($value) ? $value : null;
}

/** Append host probes and metadata to the parsed fields in legacy serialization order. */
function pmssDiskIostatParseLatestSample(string $iostatRaw, int $deviceCount, ?int $timestamp = null): array
{
    return pmssDiskIostatParseSampleFields($iostatRaw) + [
        'psiFullAvg300' => pmssDiskIostatReadPsiFullAvg300(),
        'psiMemFullAvg300' => pmssDiskIostatReadPsiFullAvg300('/proc/pressure/memory'),
        'psiCpuFullAvg300' => pmssDiskIostatReadPsiFullAvg300('/proc/pressure/cpu'),
        'iopingHomeMs' => pmssDiskIostatReadIopingHomeMs(),
        'diskQuantity' => $deviceCount, 'time' => $timestamp ?? time(),
    ];
}

/**
 * Persist the runtime snapshot and append the historical audit files.
 *
 * @param array<string, int|string> $iostat
 */
function pmssDiskIostatWriteSnapshotFiles(
    string $iostatLogFile,
    array $iostat,
    string $iostatRaw,
    string $historyLogFile = PMSS_DISK_IOSTAT_HISTORY_LOG,
    string $historyRawLogFile = PMSS_DISK_IOSTAT_HISTORY_RAW_LOG
): bool
{
    $serialized = serialize($iostat);
    $historyPrefix = date('Y-m-d H:i:s').' || ';
    $writes = [
        [$iostatLogFile, $serialized, 0],
        [$historyLogFile, $historyPrefix.$serialized."\n", FILE_APPEND],
        [$historyRawLogFile, $historyPrefix.$iostatRaw."\n---\n", FILE_APPEND],
    ];

    // Reject redirected destinations before publishing any of the three files.
    foreach ($writes as $write) {
        if (!pmssPathTargetIsSafe($write[0], false, true)) {
            fwrite(STDERR, "Unable to write iostat snapshot file: invalid path\n");
            return false;
        }
    }

    foreach ($writes as $write) {
        if (!pmssFileWriteComplete($write[0], $write[1], $write[2])) {
            fwrite(STDERR, 'Unable to write iostat snapshot file: '.$write[0]."\n");
            return false;
        }
    }

    return true;
}

/** Main cron entry point; legacy fatal messages remain stdout-visible. */
function pmssDiskIostatMain(?callable $runner = null): int
{
    $iostatLogFile = '/var/run/pmss/iostat';
    $devices = pmssDiskIostatDiscoverDevices();

    try {
        $devices = pmssDiskIostatSampleDevices($devices);
        $command = pmssDiskIostatBuildCommand($devices);
        if ($runner === null) {
            $result = pmssCommandCapture($command, PMSS_DISK_IOSTAT_TIMEOUT_SECONDS);
            $iostatRaw = (string) ($result['stdout'] ?? '');
        } else {
            $iostatRaw = (string) $runner($command);
        }
        $iostat = pmssDiskIostatParseLatestSample($iostatRaw, max(1, count($devices)));
    } catch (RuntimeException $exception) {
        echo $exception->getMessage()."\n";
        return 0;
    }

    if (pmssDiskIostatWriteSnapshotFiles($iostatLogFile, $iostat, $iostatRaw)) {
        if (!@copy($iostatLogFile, '/var/www/iostat')) {
            fwrite(STDERR, "Unable to copy iostat snapshot to /var/www/iostat\n");
        }
    }
    return 0;
}
