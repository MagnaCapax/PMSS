#!/usr/bin/env php
<?php
/**
 * Cron task: quota snapshot.
 *
 * Records a daily snapshot of per-user quota usage for /home in a stable,
 * machine-parseable format. This data is used for capacity planning and later
 * time-series aggregation (tracked separately).
 *
 * Output file (root-only): /var/log/pmss/quota-daily.log (0600)
 *
 * Line format:
 *   2026-02-01T00:00:00 <uid> <blocks_used> <blocks_soft> <blocks_hard> <files_used> <files_soft> <files_hard>
 *
 * Notes:
 * - Uses numeric UIDs to reduce privacy surface in logs.
 * - Best-effort: failures are logged (date-prefixed) but do not exit non-zero
 *   to avoid cron noise.
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

/**
 * Capture and persist one snapshot.
 *
 * @return int exit code
 */
function pmssQuotaSnapshotRun(): int
{
    $mountPath = getenv('PMSS_QUOTA_SNAPSHOT_MOUNT') ?: PMSS_QUOTA_SNAPSHOT_MOUNT_DEFAULT;
    $logPath = getenv('PMSS_QUOTA_SNAPSHOT_LOG') ?: PMSS_QUOTA_SNAPSHOT_LOG_DEFAULT;
    $mountLabel = preg_replace('/\\s+/', '', $mountPath);

    $ts = date('Y-m-d\\TH:i:s');

    $oldUmask = null;
    $fh = false;
    try {
        $fh = pmssSnapshotLogOpen(__FILE__, $logPath, $oldUmask);
        if ($fh === false) {
            return 1;
        }

        // Resolve repquota binary without depending on PATH inherited by cron.
        $repquota = trim((string) @shell_exec('command -v repquota 2>/dev/null'));
        if ($repquota === '') {
            @fwrite($fh, $ts.' WARN repquota_missing'.PHP_EOL);
            return 0;
        }

        $cmd = $repquota.' -u -n '.escapeshellarg($mountPath).' 2>&1';
        $output = [];
        $rc = 0;
        @exec($cmd, $output, $rc);
        if ($rc !== 0) {
            $excerpt = trim(preg_replace('/\\s+/', ' ', implode(' ', array_slice($output, 0, 5))));
            @fwrite(
                $fh,
                $ts.' WARN repquota_failed rc='.$rc.' mount='.$mountLabel.($excerpt !== '' ? ' msg='.substr($excerpt, 0, 300) : '').PHP_EOL
            );
            return 0;
        }

        $rows = pmssQuotaSnapshotParseRepquotaUserRows($output);
        if (empty($rows)) {
            @fwrite($fh, $ts.' WARN repquota_no_rows mount='.$mountLabel.PHP_EOL);
            return 0;
        }

        foreach ($rows as $row) {
            @fwrite($fh, $ts.' '.implode(' ', $row).PHP_EOL);
        }

        return 0;
    } finally {
        if ($fh !== false) {
            @fclose($fh);
        }
        if ($oldUmask !== null) {
            umask($oldUmask);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(pmssQuotaSnapshotRun());
}
