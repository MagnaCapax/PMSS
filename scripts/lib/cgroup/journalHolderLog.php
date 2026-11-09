<?php
/** Private evidence for journal releases; no task names, arguments, or paths. @license GPL-3.0-only */
declare(strict_types=1);

const PMSS_JOURNAL_HOLDER_LOG_DEFAULT = '/var/log/pmss/dynamic-iops.jsonl';

/** Count successful releases in today's UTC log, including earlier invocations; malformed rows do not count. */
function pmssJournalHolderDailyCounts(float $deadline): ?array
{
    $path = pmssResolvePathFromEnv('PMSS_DYNAMIC_IOPS_LOG', PMSS_JOURNAL_HOLDER_LOG_DEFAULT);
    $counts = []; $day = gmdate('Y-m-d');
    try {
        foreach ([$path.'.1', $path] as $file) {
            if (!pmssLockFilePathIsSafe($file)) return null;
            if (!file_exists($file)) continue;
            if (!pmssJsonLineFileEach($file, static function (array $row) use (&$counts, $day, $deadline): void {
                if (microtime(true) >= $deadline) throw new \RuntimeException('journal history deadline');
                if (($row['event'] ?? '') !== 'journal-holder-release'
                    || !is_string($row['ts'] ?? null) || substr($row['ts'], 0, 10) !== $day
                    || !is_int($row['uid'] ?? null) || $row['uid'] <= 0) return;
                $uid = $row['uid']; $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            })) return null;
        }
        return microtime(true) < $deadline ? $counts : null;
    } catch (\RuntimeException $error) {
        return null;
    }
}

/** Append one event using the dynamic allocator's safe mode-0600 snapshot writer. */
function pmssJournalHolderLog(string $event, ?int $uid, ?int $cap, string $frame, string $kernel, int $count): bool
{
    $payload = pmssJsonEncodeSafe(['ts' => gmdate('c'), 'event' => $event, 'uid' => $uid,
        'cap_before' => $cap, 'frame' => $frame, 'stall_s' => PMSS_JOURNAL_HOLDER_SAMPLE_SECONDS,
        'kernel' => $kernel, 'count_today' => $count]);
    if ($payload === null) return false;
    return pmssRunSnapshotLogTask(__FILE__, 'PMSS_DYNAMIC_IOPS_LOG', PMSS_JOURNAL_HOLDER_LOG_DEFAULT,
        static function ($handle) use ($payload): int { return pmssStreamWriteAll($handle, $payload."\n") ? 0 : 1; }) === 0;
}
