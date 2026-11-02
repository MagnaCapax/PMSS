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
pmssRequireRelativeFiles(__DIR__.'/diskIostat', ['devices.php', 'sample.php', 'observe.php']);

const PMSS_DISK_IOSTAT_HISTORY_LOG = '/var/log/pmss/iostat-history.log';
const PMSS_DISK_IOSTAT_HISTORY_RAW_LOG = '/var/log/pmss/iostat-history-raw.log';
// Leave startup and parser time while bounding the 120-second iostat interval.
const PMSS_DISK_IOSTAT_TIMEOUT_SECONDS = 180;

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

    $runner = $runner ?? static function (string $command): string {
        $result = pmssCommandCapture($command, PMSS_DISK_IOSTAT_TIMEOUT_SECONDS);
        // Failed probes can leave a parseable first sample; never publish it as fresh.
        $rc = (int) ($result['rc'] ?? 1);
        if ($rc !== 0) {
            throw new RuntimeException('Unable to collect iostat sample (rc='.$rc.')');
        }
        return (string) ($result['stdout'] ?? '');
    };

    try {
        $devices = pmssDiskIostatSampleDevices($devices);
        $iostatRaw = (string) $runner(pmssDiskIostatBuildCommand($devices));
        $iostat = pmssDiskIostatParseLatestSample($iostatRaw, max(1, count($devices)));
    } catch (RuntimeException $exception) {
        echo $exception->getMessage()."\n";
        return 0;
    }

    if (pmssDiskIostatWriteSnapshotFiles($iostatLogFile, $iostat, $iostatRaw) && !@copy($iostatLogFile, '/var/www/iostat')) {
        fwrite(STDERR, "Unable to copy iostat snapshot to /var/www/iostat\n");
    }
    return 0;
}
