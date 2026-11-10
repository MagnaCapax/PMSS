<?php
/** Pure ext4 journal (jbd2) statistics parsing for the stats log. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

/** Order of the slash-joined `jbd2_home` field; each average carries the unit the kernel prints. */
const PMSS_SYSTEM_STATS_JBD2_AVERAGES = [
    'waiting for transaction' => 'ms', 'request delay' => 'ms', 'running transaction' => 'ms',
    'transaction was being locked' => 'ms', 'flushing data (in ordered mode)' => 'ms',
    'logging transaction' => 'ms', 'average transaction commit time' => 'us',
];

/**
 * Parse /proc/fs/jbd2/<dev>/info into the append-only `transactions/requested/wait_ms/request_delay_ms/
 * running_ms/locked_ms/flush_ms/logging_ms/commit_us` field.
 *
 * `transactions` and `requested` are exact counters since mount. The five ms values are
 * cumulative since-mount averages (kernel prints floor(sum_jiffies / transactions) as ms), and
 * `commit_us` is a smoothed recent value. A missing or malformed sub-value is `na`; an unreadable
 * counter line makes the whole field `na`.
 */
function pmssSystemStatsJbd2FromRaw(?string $raw): string
{
    if ($raw === null || strpos($raw, "\0") !== false) return 'na';

    $lines = pmssTextLines($raw);
    if (preg_match('/\A([0-9]+) transactions \(([0-9]+) requested\), each up to [0-9]+ blocks\z/', $lines[0], $head) !== 1
        || ($transactions = pmssUnsignedDecimalIntParse($head[1])) === null
        || ($requested = pmssUnsignedDecimalIntParse($head[2])) === null) return 'na';

    $found = [];
    foreach (array_slice($lines, 1) as $line) {
        if (preg_match('/\A[ \t]*([0-9]+)(ms|us) (.+?)[ \t]*\z/', $line, $row) === 1 && !isset($found[$row[3]])) $found[$row[3]] = [$row[2], $row[1]];
    }

    $values = [(string) $transactions, (string) $requested];
    foreach (PMSS_SYSTEM_STATS_JBD2_AVERAGES as $label => $unit) {
        // The kernel prints no averages for zero transactions; stale rows would be meaningless then.
        $number = $transactions > 0 && ($found[$label][0] ?? '') === $unit ? pmssUnsignedDecimalIntParse($found[$label][1]) : null;
        $values[] = $number === null ? 'na' : (string) $number;
    }
    return implode('/', $values);
}

/**
 * Return the major:minor of the visible /home mount when it is a journaled ext3/ext4 filesystem.
 *
 * The last /home row wins because a later mount shadows an earlier one. Anything else
 * (no /home mount, another filesystem type, malformed row) is null.
 */
function pmssSystemStatsJbd2HomeDeviceFromMountinfo(?string $raw): ?string
{
    if ($raw === null || strpos($raw, "\0") !== false) return null;

    $home = null;
    foreach (pmssTextLines($raw) as $line) {
        $fields = explode(' ', $line);
        $separator = array_search('-', $fields, true);
        if ($separator === false || $separator < 6 || !isset($fields[$separator + 2]) || $fields[4] !== '/home') continue;
        $home = [$fields[2], $fields[$separator + 1]];
    }
    return $home !== null && in_array($home[1], ['ext3', 'ext4'], true) && preg_match('/\A[0-9]+:[0-9]+\z/', $home[0]) === 1 ? $home[0] : null;
}

/**
 * Pick the /proc/fs/jbd2 directory of an internal journal: `<kernel block name>-<journal inode>`.
 *
 * Exactly one match is required, so an ambiguous or missing directory is null. External
 * journals are named after the journal device and never match.
 *
 * @param string[] $entries
 */
function pmssSystemStatsJbd2DirectoryFor(string $device, array $entries): ?string
{
    // Kernel names may carry '!' where the device path has '/'; '.' and '..' are never devices.
    if (preg_match('/\A[A-Za-z0-9._+!-]+\z/', $device) !== 1 || $device === '.' || $device === '..') return null;

    $matches = array_values(preg_grep('/\A'.preg_quote($device, '/').'-[0-9]+\z/', $entries));
    return count($matches) === 1 ? $matches[0] : null;
}
