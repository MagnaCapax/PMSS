<?php
/**
 * Bounded history reading and atomic cache publication for passive I/O estimates.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/ioCeiling.php';
require_once __DIR__.'/policy.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';

const PMSS_IO_CEILING_STATE_PATH_DEFAULT = '/var/run/pmss/io-ceiling.json';

/** Read the live log and newest uncompressed rotation without scanning years. */
function pmssIoCeilingHistorySamples(string $path): iterable
{
    // delaycompress keeps .1 readable. Older compressed rotations are not scanned.
    // Bound both bytes and line length even when a collector/log is malformed.
    foreach ([$path.'.1', $path] as $file) {
        if (!pmssPathTargetIsSafe($file, false, true) || !is_file($file)) {
            continue;
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            continue;
        }
        try {
            $stat = fstat($handle);
            $offset = max(0, ($stat['size'] ?? 0) - 16 * 1024 * 1024);
            if (fseek($handle, $offset) !== 0) {
                continue;
            }
            $remaining = ($stat['size'] ?? 0) - $offset;
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
                $sample = @unserialize(substr($line, 23), ['allowed_classes' => false]);
                if (!is_array($sample) || !is_int($sample['time'] ?? null)) {
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

/** Refresh a derived cache only; missing/thin history removes stale publication. */
function pmssIoCeilingRefresh(
    array $policy,
    string $historyPath = '/var/log/pmss/iostat-history.log',
    string $statePath = PMSS_IO_CEILING_STATE_PATH_DEFAULT,
    ?int $now = null
): bool {
    $result = pmssIoCeilingCompute(pmssIoCeilingHistorySamples($historyPath), $policy, $now ?? time());
    if (!pmssPathTargetIsSafe($statePath, false, true)) {
        return false;
    }
    if ($result === null) {
        return !file_exists($statePath) || @unlink($statePath);
    }
    return pmssEnsureSafeDir(dirname($statePath), 0755)
        && pmssAtomicJsonFileWrite($statePath, $result, 0644);
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
    $qualified = 0;
    foreach ($days as $day) {
        if (is_array($day) && is_int($day['samples'] ?? null) && $day['samples'] >= $minSamples) {
            $qualified++;
        }
    }
    if ($qualified < $minDays) {
        return null;
    }

    $value = $state['published']['read_iops'] ?? null;
    if ((!is_int($value) && !is_float($value) && !is_string($value))
        || !is_numeric($value)
        || !is_finite((float) $value)
        || (float) $value < 0) {
        return null;
    }

    return (float) $value;
}
