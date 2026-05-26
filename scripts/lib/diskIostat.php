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

const PMSS_DISK_IOSTAT_HISTORY_LOG = '/var/log/pmss/iostat-history.log';
const PMSS_DISK_IOSTAT_HISTORY_RAW_LOG = '/var/log/pmss/iostat-history-raw.log';

/** Keep block device names argv-safe before passing them to iostat. */
function pmssDiskIostatDeviceNameIsSafe(string $device): bool
{
    return $device !== '' && preg_match('/^[A-Za-z0-9._+-]+$/', $device) === 1;
}

/**
 * Discover the legacy sd* device set from /sys/block without crossing a shell.
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
            || strpos($entry, 'sd') === false
            || strpos($entry, 'loop') !== false
            || strpos($entry, 'md') !== false
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
 * Parse the second-sample iostat group row by column name.
 *
 * @return array<string, int|string>
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

    $header = preg_split('/\s+/', trim($lines[$lastHeaderIdx]));
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

    $values = preg_split('/\s+/', trim($grp1Line));
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
            $rawOutput = @shell_exec($command);
            $iostatRaw = is_string($rawOutput) ? $rawOutput : '';
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
