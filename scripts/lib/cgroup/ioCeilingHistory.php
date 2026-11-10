<?php
/**
 * Bounded history reading and atomic cache publication for passive I/O estimates.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/ioCeiling.php';
require_once __DIR__.'/policy.php';
require_once __DIR__.'/dynamicIopsPolicy.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

const PMSS_IO_CEILING_STATE_PATH_DEFAULT = '/var/run/pmss/io-ceiling.json';
// Survives reboot, unlike the derived cache under /var/run.
const PMSS_IO_CEILING_ANCHOR_PATH_DEFAULT = '/var/lib/pmss/io-ceiling-anchor.json';
// ADR 0239 (update 2026-11-09): the anchor holds for this many days, then decays by this factor per day, never below this share of the
// highest value it ever held. A cap-cut measurement can lower the ceiling by at most that share.
const PMSS_IO_CEILING_ANCHOR_HOLD_DAYS = 14;
const PMSS_IO_CEILING_ANCHOR_DECAY_PER_DAY = 0.9;
const PMSS_IO_CEILING_ANCHOR_FLOOR_SHARE = 0.5;

/** Read the live log and newest uncompressed rotation without scanning years. */
function pmssIoCeilingHistorySamples(string $path): iterable
{
    // delaycompress keeps .1 readable. Older compressed rotations are not scanned.
    // Bound both bytes and line length even when a collector/log is malformed.
    foreach ([$path.'.1', $path] as $file) {
        if (!pmssPathTargetIsSafe($file, false, true)
            || !is_array($entry = @lstat($file)) || (($entry['mode'] ?? 0) & 0170000) !== 0100000) {
            continue;
        }
        $handle = pmssRegularFileOpenVerified($file, $entry, $stat);
        if ($handle === false) {
            continue;
        }
        try {
            $size = $stat['size'] ?? 0;
            $offset = max(0, $size - 16 * 1024 * 1024);
            if (fseek($handle, $offset) !== 0) {
                continue;
            }
            $remaining = $size - $offset;
            $discard = $offset > 0;
            $cutDay = null;
            while ($remaining > 0 && ($line = fgets($handle, min(8193, $remaining + 1))) !== false) {
                $remaining -= strlen($line);
                $complete = substr($line, -1) === "\n";
                $skip = $discard || !$complete;
                $discard = !$complete;
                if ($skip || strpos($line, ' || ') !== 19) {
                    continue;
                }
                $sample = pmssSerializedArrayDecode(substr($line, 23));
                if ($sample === null || !is_int($sample['time'] ?? null)) {
                    continue;
                }
                // Never compute a percentile from a day cut by the byte budget.
                if ($offset > 0) {
                    $day = intdiv($sample['time'], 86400);
                    $cutDay = $cutDay ?? $day;
                    if ($day <= $cutDay) {
                        continue;
                    }
                }
                yield $sample;
            }
        } finally {
            fclose($handle);
        }
    }
}

/**
 * The anchor's value after its hold: it falls 10% a day from day 15 of its age, never below half the highest value it
 * held and never above $kept. The age counts from the day the anchored value was measured. A day in the future (clock
 * step) or an unreadable day keeps the value.
 */
function pmssIoCeilingAnchorEffective(float $kept, float $peak, string $fromDay, int $now): float
{
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDay) === 1 ? strtotime($fromDay.' 00:00:00 UTC') : false;
    if ($start === false) {
        return $kept;
    }
    $decayDays = intdiv($now, 86400) - intdiv($start, 86400) - PMSS_IO_CEILING_ANCHOR_HOLD_DAYS;
    if ($decayDays <= 0) {
        return $kept;
    }
    $decayed = $kept * pow(PMSS_IO_CEILING_ANCHOR_DECAY_PER_DAY, $decayDays);
    return min($kept, max($decayed, $peak * PMSS_IO_CEILING_ANCHOR_FLOOR_SHARE));
}

/** Refresh a derived cache only; missing/thin history removes stale publication. */
function pmssIoCeilingRefresh(
    array $policy,
    string $historyPath = '/var/log/pmss/iostat-history.log',
    string $statePath = PMSS_IO_CEILING_STATE_PATH_DEFAULT,
    ?int $now = null,
    string $anchorPath = PMSS_IO_CEILING_ANCHOR_PATH_DEFAULT
): bool {
    $now = $now ?? time();
    $result = pmssIoCeilingCompute(pmssIoCeilingHistorySamples($historyPath), $policy, $now);
    if (!pmssPathTargetIsSafe($statePath, false, true)) {
        return false;
    }
    if ($result === null) {
        return !file_exists($statePath) || @unlink($statePath);
    }
    // Enforced read caps cut the reads this ceiling measures, so while they apply it may rise at once but falls only
    // slowly and to a floor (ADR 0239, bounded way down in the ADR 0239 update). Only measured host reads move it; no account's cap
    // enters it, so lowering it never lifts anyone's cap.
    $anchorKeep = null;
    $anchorPeak = pmssFiniteNonNegativeFloat($result['published']['read_iops'] ?? null);
    if (($settings = pmssDynamicIopsSettings($policy)) !== null && $settings['mode'] === 'enforce') {
        // The first run anchors today's value, so an update changes no cap; it only stops later falls.
        $anchor = pmssJsonFileReadAssoc($anchorPath, true);
        $kept = pmssFiniteNonNegativeFloat($anchor['read_iops'] ?? null);
        $day = $anchor['from_day'] ?? null;
        if ($kept !== null && is_string($day)) {
            $peak = max($kept, pmssFiniteNonNegativeFloat($anchor['peak_read_iops'] ?? null) ?? 0.0);
            $anchorPeak = max($peak, $anchorPeak ?? 0.0);
            $effective = pmssIoCeilingAnchorEffective($kept, $peak, $day, $now);
            if ($effective > ($result['published']['read_iops'] ?? INF)) {
                $result['published']['read_iops'] = $effective;
                $result['published']['from_day']['read_iops'] = $day;
                $result['read_iops_anchored'] = true;
                // Measured demand below the anchor's hold keeps the anchor itself, so its age keeps counting.
                $anchorKeep = ['read_iops' => $kept, 'from_day' => $day];
            }
        }
    }
    if (!pmssEnsureSafeDir(dirname($statePath), 0755) || !pmssAtomicJsonFileWrite($statePath, $result, 0644)) {
        return false;
    }
    return !isset($result['published']['read_iops'])
        || (pmssPathTargetIsSafe($anchorPath, false, true) && pmssEnsureSafeDir(dirname($anchorPath), 0755)
        && pmssAtomicJsonFileWrite($anchorPath, ($anchorKeep ?? ['read_iops' => $result['published']['read_iops'],
            'from_day' => $result['published']['from_day']['read_iops']]) + ['peak_read_iops' => $anchorPeak], 0600));
}

/** Read the persisted published host read-IOPS ceiling, failing closed on thin caches. */
function pmssIoCeilingPublishedReadIops(string $statePath = PMSS_IO_CEILING_STATE_PATH_DEFAULT): ?float
{
    $state = pmssJsonFileReadAssoc($statePath, true);
    if (!is_array($state)) {
        return null;
    }

    // A consumer must re-apply the sample gates stored with the publication.
    $minDays = is_int($state['min_days'] ?? null) ? $state['min_days'] : 0;
    $minSamples = is_int($state['min_samples_per_day'] ?? null) ? $state['min_samples_per_day'] : 0;
    $days = $state['days'] ?? null;
    if ($minDays <= 0 || $minSamples <= 0 || !is_array($days)) {
        return null;
    }
    foreach ($days as $day) {
        if (is_array($day) && is_int($day['samples'] ?? null) && $day['samples'] >= $minSamples
            && --$minDays === 0) return pmssFiniteNonNegativeFloat($state['published']['read_iops'] ?? null);
    }
    return null;
}
