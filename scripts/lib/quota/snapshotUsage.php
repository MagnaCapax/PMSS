<?php
/** Tenant quota usage, including rootless-container owner IDs. @license GPL-3.0-only */
require_once dirname(__DIR__).'/user/subordinateIds.php';
require_once dirname(__DIR__).'/quotaSnapshotCron.php';

/** Cache one bounded numeric user report per filesystem during a snapshot sweep. */
function pmssQuotaSnapshotFilesystemReport(string $filesystem): array
{
    static $reports = [];
    if (!isset($reports[$filesystem])) {
        $reports[$filesystem] = pmssQuotaReportCapture('LC_ALL=C repquota -u -n '.escapeshellarg($filesystem).' 2>&1');
    }
    return $reports[$filesystem];
}

/**
 * @param array|null $details Receives the primary hard limit and used subordinate ID count.
 * @return array{0:int,1:int}|null KiB and inodes, or null for invalid/overflowing counters.
 */
function pmssQuotaSnapshotUsageSum(array $rows, int $primaryId, array $ranges, ?array &$details = null): ?array
{
    $totals = [0, 0];
    $details = ['hard_limit_kib' => null, 'subordinate_ids' => 0];
    $seen = [];
    foreach ($rows as $row) {
        $id = pmssUnsignedDecimalIntParse($row[0]);
        if ($id === null) return null;
        $selected = $id === $primaryId;
        foreach ($ranges as $range) {
            if ($id >= $range[0] && $id - $range[0] < $range[1]) $selected = true;
        }
        // Overlapping allocations and repeated rows must never charge an ID twice.
        if (!$selected || isset($seen[$id])) continue;
        $seen[$id] = true;
        foreach ([1, 4] as $index => $column) {
            $value = pmssUnsignedDecimalIntParse($row[$column]);
            if ($value === null || $totals[$index] > PHP_INT_MAX - $value) return null;
            $totals[$index] += $value;
        }
        // Keep exact numeric limits beside the same deduplicated ownership traversal.
        if ($id === $primaryId) {
            $details['hard_limit_kib'] = pmssUnsignedDecimalIntParse($row[3]);
        } elseif ($row[1] > 0 || $row[4] > 0) {
            ++$details['subordinate_ids'];
        }
    }
    return $totals;
}

/**
 * Replace usage only; keep the primary account's limits and kernel grace/over-quota state.
 * User and group quota records charge the same inodes, so only UID records are summed.
 * Sparse repquota reports cover every allocated UID with usage without spawning a
 * process for each of the usually 65536 subordinate IDs. Missing records mean zero.
 *
 * @param callable|null $capture Optional bounded filesystem report reader for hermetic tests.
 * @param callable|null $log Optional cron logger for report-only aggregate hard-limit diagnostics.
 * @return array{rc:int,lines:array<int,string>,timed_out:bool} Existing capture/failure contract.
 */
function pmssQuotaSnapshotAggregateReport(string $user, array $report, ?callable $capture = null, ?callable $log = null): array
{
    if ($report['timed_out'] || $report['rc'] > 1) return $report;
    $content = implode("\n", $report['lines']);
    if (preg_match('/Disk quotas for user '.preg_quote($user, '/').' \(uid ([0-9]+)\):/', $content, $match) !== 1) {
        return $report;
    }
    $primaryId = pmssUnsignedDecimalIntParse($match[1]);
    if ($primaryId === null) return ['rc' => 2, 'lines' => [], 'timed_out' => false];
    $ranges = pmssSubordinateIdRanges($user, $primaryId, 'uid');
    if ($ranges === []) return $report;

    $capture = $capture ?? 'pmssQuotaSnapshotFilesystemReport';
    // quota wraps long device names onto a separate line unless invoked with -w.
    $report['lines'] = explode("\n", preg_replace('~^(\h*/\S+)\h*\n\h+(?=[0-9])~m', '$1 ', $content));
    $updated = false;
    $overages = [];
    foreach ($report['lines'] as &$line) {
        $columns = pmssQuotaSnapshotDataRows($line)[0] ?? [];
        if ($columns === []) continue;
        // The block grace column is absent when not over quota; file counts may use -s units.
        $fileColumn = preg_match('/^[0-9]+[kMGTPE]?\*?$/', $columns[4] ?? '') === 1 ? 4 : 5;
        if (count($columns) < $fileColumn + 3) return ['rc' => 2, 'lines' => [], 'timed_out' => false];
        $usage = $capture($columns[0]);
        if ($usage['timed_out'] || $usage['rc'] !== 0) {
            return ['rc' => 2, 'lines' => [], 'timed_out' => $usage['timed_out']];
        }
        // A successful but unrecognized report must not replace usage with a false zero.
        if (strpos(implode("\n", $usage['lines']), 'Report for user quotas on device') === false) {
            return ['rc' => 2, 'lines' => [], 'timed_out' => false];
        }
        $details = null;
        $totals = pmssQuotaSnapshotUsageSum(pmssQuotaSnapshotParseRepquotaUserRows($usage['lines']), $primaryId, $ranges, $details);
        if ($totals === null) return ['rc' => 2, 'lines' => [], 'timed_out' => false];
        // Zero means unlimited; a missing/invalid primary limit cannot certify an overage.
        if ($details['hard_limit_kib'] !== null && $details['hard_limit_kib'] > 0 && $totals[0] > $details['hard_limit_kib']) {
            $overages[] = sprintf('filesystem=%s aggregate_kib=%d hard_limit_kib=%d subordinate_ids=%d',
                $columns[0], $totals[0], $details['hard_limit_kib'], $details['subordinate_ids']);
        }
        $columns[1] = $totals[0].'K'.(substr($columns[1], -1) === '*' ? '*' : '');
        $columns[$fileColumn] = $totals[1].(substr($columns[$fileColumn], -1) === '*' ? '*' : '');
        $line = substr($line, 0, strlen($line) - strlen(ltrim($line))).implode(' ', $columns);
        $updated = true;
    }
    unset($line);
    // Report once per account only after every filesystem probe has succeeded.
    if ($updated && $log !== null && $overages !== []) {
        $log('Aggregate quota hard limit exceeded for '.$user.': '.implode('; ', $overages));
    }
    return $updated ? $report : ['rc' => 2, 'lines' => [], 'timed_out' => false];
}
