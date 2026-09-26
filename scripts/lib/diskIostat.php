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
require_once __DIR__.'/cgroup/policy.php';

const PMSS_DISK_IOSTAT_HISTORY_LOG = '/var/log/pmss/iostat-history.log';
const PMSS_DISK_IOSTAT_HISTORY_RAW_LOG = '/var/log/pmss/iostat-history-raw.log';
// Leave startup and parser time while bounding the 120-second iostat interval.
const PMSS_DISK_IOSTAT_TIMEOUT_SECONDS = 180;

/** Keep block device names argv-safe before passing them to iostat. */
function pmssDiskIostatDeviceNameIsSafe(string $device): bool
{
    return $device !== '' && preg_match('/\A[A-Za-z0-9._+-]+\z/', $device) === 1;
}

/**
 * Discover data block devices from /sys/block without crossing a shell.
 *
 * @return array<int, string>
 */
function pmssDiskIostatDiscoverDevices(string $sysBlockDir = '/sys/block'): array
{
    // Match the existing discovery-failure result before PHP rejects the path.
    if ($sysBlockDir === '' || pmssFilesystemPathHasNulByte($sysBlockDir)) {
        return [];
    }
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
    // Reject malformed executable paths before shell quoting can throw.
    if (pmssFilesystemPathHasNulByte($iostatBinary)) {
        throw new RuntimeException('Unsafe iostat binary path');
    }
    foreach ($devices as $device) {
        if (!is_string($device) || !pmssDiskIostatDeviceNameIsSafe($device)) {
            throw new RuntimeException('Unsafe block device name for iostat');
        }
    }

    if ($iostatBinary === '') {
        $resolved = pmssCommandPath('iostat');
        $iostatBinary = $resolved !== '' ? $resolved : 'iostat';
    }

    $deviceArgs = $devices ? ' '.pmssCommandArgvShellQuote($devices) : '';
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

/** Resolve only sampled leaf disks behind /home; unknown topology stays unknown. */
function pmssDiskIostatHomeDevices(array $sampled, string $sysClassBlock = '/sys/class/block', ?callable $mountRunner = null, string $devRoot = '/dev'): ?array
{
    $source = pmssCgroupPolicyMountSourceResolve('/home', $mountRunner);
    $devRoot = rtrim($devRoot, '/');
    if ($source === '' || pmssFilesystemPathHasNulByte($source) || $devRoot === ''
        || strpos($source, $devRoot.'/') !== 0 || pmssFilesystemPathHasNulByte($sysClassBlock)) return null;
    if (strpos($source, $devRoot.'/mapper/') === 0) {
        $source = (string) realpath($source);
    }
    $root = rtrim($sysClassBlock, '/');
    $leaves = [];
    $seen = [];
    $walk = static function (string $device, int $depth) use (&$walk, &$leaves, &$seen, $root): bool {
        if ($depth > 8 || !pmssDiskIostatDeviceNameIsSafe($device)) return false;
        if (isset($seen[$device])) return true; // Shared leaves are counted once.
        $seen[$device] = true;
        $path = $root.'/'.$device;
        if (!is_dir($path)) return false;
        $slaves = glob($path.'/slaves/*') ?: [];
        if ($slaves) {
            foreach ($slaves as $slave) {
                if (!$walk(basename($slave), $depth + 1)) return false;
            }
            return true;
        }
        if (is_file($path.'/partition')) {
            $real = realpath($path);
            if ($real === false) return false;
            $device = basename(dirname($real));
        }
        if (!pmssDiskIostatDeviceNameIsSafe($device) || !is_dir($root.'/'.$device)) return false;
        $leaves[$device] = true;
        return true;
    };
    if (!$walk(basename($source), 0) || !$leaves) return null;
    foreach ($leaves as $device => $_) {
        if (!in_array($device, $sampled, true)) return null;
    }
    return array_keys($leaves);
}

/** Use /home leaves when resolvable; otherwise retain the discovered list. */
function pmssDiskIostatSampleDevices(array $devices, string $sysClassBlock = '/sys/class/block', ?callable $mountRunner = null, string $devRoot = '/dev'): array
{
    return pmssDiskIostatHomeDevices($devices, $sysClassBlock, $mountRunner, $devRoot) ?: $devices;
}

/**
 * Parse the second-sample iostat group row by column name.
 * diskAwait is r_await and diskServiceTime is w_await over /home leaf disks.
 * When /home cannot be resolved, the group row covers all discovered devices.
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
