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

const PMSS_DISK_IOSTAT_HISTORY_LOG = '/var/log/pmss/iostat-history.log';
const PMSS_DISK_IOSTAT_HISTORY_RAW_LOG = '/var/log/pmss/iostat-history-raw.log';
// Leave startup and parser time while bounding the 120-second iostat interval.
const PMSS_DISK_IOSTAT_TIMEOUT_SECONDS = 180;

/** Keep block device names argv-safe before passing them to iostat. */
function pmssDiskIostatDeviceNameIsSafe(string $device): bool
{
    return $device !== '' && preg_match('/^[A-Za-z0-9._+-]+$/', $device) === 1;
}

/**
 * Discover data block devices from /sys/block without crossing a shell.
 *
 * @return array<int, string>
 */
function pmssDiskIostatDiscoverDevices(string $sysBlockDir = '/sys/block'): array
{
    $entries = @scandir($sysBlockDir);
    if (!is_array($entries)) {
        return [];
    }

    $devices = [];
    foreach ($entries as $entry) {
        if (!pmssDiskIostatDeviceNameIsSafe($entry)
            || !pmssBlockDeviceNameIsDataDevice($entry)
            || !is_dir($sysBlockDir.'/'.$entry)) {
            continue;
        }
        $devices[] = $entry;
    }

    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);
    return $devices;
}

/**
 * Build the iostat command with every shell argument escaped.
 *
 * @param array<int, string> $devices
 */
function pmssDiskIostatBuildCommand(array $devices, string $iostatBinary = ''): string
{
    foreach ($devices as $device) {
        if (!is_string($device) || !pmssDiskIostatDeviceNameIsSafe($device)) {
            throw new RuntimeException('Unsafe block device name for iostat');
        }
    }

    if ($iostatBinary === '') {
        $resolved = pmssCommandPath('iostat');
        $iostatBinary = $resolved !== '' ? $resolved : 'iostat';
    }

    $deviceArgs = $devices ? ' '.implode(' ', array_map('escapeshellarg', $devices)) : '';
    return escapeshellarg($iostatBinary).' -xm 120 2 -g grp1'.$deviceArgs.' 2>&1';
}

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
    if (!is_string($raw) || !preg_match('/^full\s.*?avg300=([0-9.]+)/m', $raw, $matches)) {
        return null;
    }
    return (float) $matches[1];
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
    if (!is_string($raw) || !preg_match('/^([0-9.]+)ms$/', $raw, $matches)) {
        return null;
    }
    return (float) $matches[1];
}

/**
 * Parse the second-sample iostat group row by column name.
 *
 * @return array<string, int|string|float|null>
 */
function pmssDiskIostatParseLatestSample(string $iostatRaw, int $deviceCount, ?int $timestamp = null): array
{
    $lines = explode("\n", $iostatRaw);
    $lastHeaderIdx = -1;
    foreach ($lines as $i => $line) {
        if (preg_match('/^Device\s/', trim($line))) {
            $lastHeaderIdx = $i;
        }
    }
    if ($lastHeaderIdx === -1) {
        throw new RuntimeException('No iostat Device header found');
    }

    $header = pmssConfigLineColumns($lines[$lastHeaderIdx], 1, []);
    $colMap = array_flip($header);
    $grp1Line = null;
    for ($i = $lastHeaderIdx + 1; $i < count($lines); $i++) {
        if (preg_match('/^\s*grp1\s/', $lines[$i])) {
            $grp1Line = $lines[$i];
            break;
        }
    }
    if ($grp1Line === null) {
        throw new RuntimeException('No grp1 line after iostat header');
    }

    $values = pmssConfigLineColumns($grp1Line, 1, []);
    $getAny = static function (array $colNames) use ($colMap, $values): string {
        foreach ($colNames as $name) {
            if (isset($colMap[$name])) {
                return $values[$colMap[$name]] ?? '0';
            }
        }
        return '0';
    };

    // Column-name lookup keeps sysstat 12+ additions from shifting meanings;
    // legacy await/svctm names remain fallbacks for older hosts.
    return [
        'iopsRead'        => $getAny(['r/s']),
        'iopsWrite'       => $getAny(['w/s']),
        'throughputRead'  => $getAny(['rMB/s']),
        'throughputWrite' => $getAny(['wMB/s']),
        'diskAwait'       => $getAny(['r_await', 'await']),
        'diskServiceTime' => $getAny(['w_await', 'svctm']),
        'diskUtil'        => $getAny(['%util']),
        'avgQueueSize'    => $getAny(['aqu-sz', 'avgqu-sz']),
        'psiFullAvg300'   => pmssDiskIostatReadPsiFullAvg300(),
        'psiMemFullAvg300'=> pmssDiskIostatReadPsiFullAvg300('/proc/pressure/memory'),
        'psiCpuFullAvg300'=> pmssDiskIostatReadPsiFullAvg300('/proc/pressure/cpu'),
        'iopingHomeMs'    => pmssDiskIostatReadIopingHomeMs(),
        'diskQuantity'    => $deviceCount,
        'time'            => $timestamp ?? time(),
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

    foreach ($writes as $write) {
        if (@file_put_contents($write[0], $write[1], $write[2]) === false) {
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
