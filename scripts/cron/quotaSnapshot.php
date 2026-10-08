#!/usr/bin/env php
<?php
/**
 * Cron task: record daily /home quota usage as stable numeric rows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/quotaSnapshotCron.php';

const PMSS_QUOTA_SNAPSHOT_LOG_DEFAULT = '/var/log/pmss/quota-daily.log';
const PMSS_QUOTA_SNAPSHOT_MOUNT_DEFAULT = '/home';

function pmssQuotaSnapshotRun(?string $fstabPath = null): int
{
    $mountPath = getenv('PMSS_QUOTA_SNAPSHOT_MOUNT') ?: PMSS_QUOTA_SNAPSHOT_MOUNT_DEFAULT;
    $mountLabel = preg_replace('/\\s+/', '', $mountPath);

    return pmssRunSnapshotLogTask(__FILE__, 'PMSS_QUOTA_SNAPSHOT_LOG', PMSS_QUOTA_SNAPSHOT_LOG_DEFAULT, static function ($fh, string $ts) use ($mountLabel, $mountPath, $fstabPath): int {
        $repquota = pmssCommandPath('repquota');
        if ($repquota === '') {
            pmssSnapshotWriteWarn($fh, $ts, 'repquota_missing');
            return 0;
        }

        $cmd = $repquota.' -u -n '.escapeshellarg($mountPath).' 2>&1';
        $output = [];
        $rc = 0;
        @exec($cmd, $output, $rc);
        if ($rc !== 0) {
            pmssSnapshotWriteWarn($fh, $ts, 'repquota_failed', [
                'rc' => $rc,
                'mount' => $mountLabel,
            ], $output);
            if (pmssQuotaSnapshotMountDeclaresJournaledQuota($mountPath, $fstabPath ?? '/etc/fstab')) {
                return pmssCliReturnWithStderr('###PMSS_QUOTA_ALERT repquota_failed mount='.pmssSnapshotWarnToken($mountPath, 'mount').' rc='.$rc.PHP_EOL);
            }
            return 0;
        }

        $rows = pmssQuotaSnapshotParseRepquotaUserRows($output);
        if (empty($rows)) {
            pmssSnapshotWriteWarn($fh, $ts, 'repquota_no_rows', ['mount' => $mountLabel]);
            return 0;
        }

        foreach ($rows as $row) {
            pmssSnapshotWriteLine($fh, $ts.' '.implode(' ', $row));
        }

        return 0;
    });
}

pmssRunCliEntrypoint(__FILE__, 'pmssQuotaSnapshotRun');
