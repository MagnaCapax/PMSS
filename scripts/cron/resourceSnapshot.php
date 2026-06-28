#!/usr/bin/env php
<?php
/**
 * Cron task: resource snapshot.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/resources/log.php';
require_once __DIR__.'/../lib/resources.php';
require_once __DIR__.'/../lib/runtime.php';

const PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT = '/var/log/pmss/resource-daily.log';

function pmssResourceSnapshotRun(): int
{
    return pmssRunSnapshotLogTask(__FILE__, 'PMSS_RESOURCE_SNAPSHOT_LOG', PMSS_RESOURCE_SNAPSHOT_LOG_DEFAULT, static function ($fh, string $ts): int {
        $userUids = pmssResourceLogManagedUserUids();
        if ($userUids === []) {
            return 0;
        }
        $stats = new resourceStatistics();
        $homeDir = pmssDirPathResolve(null, 'PMSS_HOME_DIR', '/home');

        foreach ($userUids as $user => $uid) {
            $dataPath = $homeDir.'/'.$user.'/.resourceData';
            $metrics = is_array($data = pmssReadSerializedArrayFile($dataPath)) ? pmssResourceStoredPayloadWindowMetrics($data, 'day') : null;

            if ($metrics === null && ($dataLines = $stats->getData($user, 350)) !== '') {
                $threshold = time() - (24 * 60 * 60);
                if (($results = $stats->collectWindowResultsFromData($dataLines, ['day' => $threshold])) !== null) {
                    $metrics = pmssResourceResultsWindowMetrics($results, 'day');
                }
            }

            if ($metrics === null) {
                pmssSnapshotWriteWarn($fh, $ts, 'resource_missing', ['user' => $user]);
                continue;
            }

            pmssSnapshotWriteLine(
                $fh,
                sprintf(
                    '%s %d %d %d %d %d %.4f %.2f %d %d',
                    $ts,
                    $uid,
                    (int) round($metrics['io_read']),
                    (int) round($metrics['io_write']),
                    (int) round($metrics['cpu']),
                    (int) round($metrics['memory']),
                    $metrics['ram_hours'],
                    $metrics['tasks'],
                    (int) round($metrics['io_read_ops']),
                    (int) round($metrics['io_write_ops'])
                )
            );
        }

        return 0;
    });
}

pmssRunCliEntrypoint(__FILE__, 'pmssResourceSnapshotRun');
