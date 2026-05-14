#!/usr/bin/env php
<?php
/**
 * Cron task: record daily /home quota usage as stable numeric rows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';

const PMSS_QUOTA_SNAPSHOT_LOG_DEFAULT = '/var/log/pmss/quota-daily.log';
const PMSS_QUOTA_SNAPSHOT_MOUNT_DEFAULT = '/home';

/**
 * Parse `repquota -u -n` output into stable numeric rows.
 *
 * @return array<int, array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}>
 */
function pmssQuotaSnapshotParseRepquotaUserRows(array $lines): array
{
    $rows = [];
    foreach ($lines as $line) {
        $tokens = preg_split('/\\s+/', trim((string) $line));
        if (!is_array($tokens) || count($tokens) < 2 || preg_match('/^#?([0-9]+)$/', $tokens[0], $m) !== 1) {
            continue;
        }

        $numbers = array_values(array_filter(array_slice($tokens, 1), 'ctype_digit'));
        if (count($numbers) < 6) {
            continue;
        }

        $rows[] = array_merge([$m[1]], array_slice($numbers, 0, 6));
    }

    return $rows;
}

function pmssQuotaSnapshotRun(): int
{
    $mountPath = getenv('PMSS_QUOTA_SNAPSHOT_MOUNT') ?: PMSS_QUOTA_SNAPSHOT_MOUNT_DEFAULT;
    $logPath = pmssResolvePathFromEnv('PMSS_QUOTA_SNAPSHOT_LOG', PMSS_QUOTA_SNAPSHOT_LOG_DEFAULT);
    $mountLabel = preg_replace('/\\s+/', '', $mountPath);

    $ts = date('Y-m-d\\TH:i:s');

    return pmssWithSnapshotLog(__FILE__, $logPath, static function ($fh) use ($mountLabel, $mountPath, $ts): int {
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
