<?php
/** Bounded cgroup-v1 read-cap escape for ext4 journal holders (ADR 0242). @license GPL-3.0-only */
declare(strict_types=1);

require_once __DIR__.'/directApply.php';
require_once __DIR__.'/dynamicIopsPolicy.php';
require_once __DIR__.'/journalHolderObserve.php';
require_once __DIR__.'/journalHolderLog.php';

/**
 * Release only witnessed metadata holders after two journal-stall samples.
 * Roots and the five-second pause are injectable for hermetic fixtures, never host policy.
 * The cron supplies the hard external timeout; this deadline also bounds scans and direct calls.
 */
function pmssJournalHolderReleaseCycle(array $policy, string $kernel, string $procRoot = '/proc',
    string $blkioRoot = PMSS_CGROUP_DIRECT_BLKIO_ROOT, string $sysBlockRoot = '/sys/block', ?callable $pause = null): int
{
    $settings = pmssDynamicIopsSettings($policy);
    if ($settings === null || !$settings['journalHolderRelease'] || !pmssJournalHolderKernelAffected($kernel)
        || pmssEnvFlagEnabled('PMSS_DRY_RUN')) return 0;
    $deadline = microtime(true) + 19;
    $home = pmssJournalHolderHome($procRoot, $sysBlockRoot);
    if ($home === null || !is_dir($blkioRoot)) return 0;
    list($device, $majorMinor) = $home;
    $journal = pmssJournalHolderJournalFind($procRoot, $device);
    if ($journal === null || !pmssJournalHolderStackHas((string) @file_get_contents($journal.'/stack'), 'jbd2_journal_wait_updates')) return 0;
    $pause = $pause ?? static function (): bool { return sleep(PMSS_JOURNAL_HOLDER_SAMPLE_SECONDS) === 0; };
    if (!$pause() || microtime(true) >= $deadline
        || !pmssJournalHolderStackHas((string) @file_get_contents($journal.'/stack'), 'jbd2_journal_wait_updates')) return 0;
    $journalStat = pmssJournalHolderTaskStat($journal);
    if ($journalStat === null || !$journalStat['kernel']) return 0;
    $counts = null; $seen = []; $written = 0;
    foreach (pmssJournalHolderTasks($procRoot) as $task) {
        if (microtime(true) >= $deadline) break;
        $stat = pmssJournalHolderTaskStat($task);
        if ($stat === null || $stat['state'] !== 'D') continue;
        $frame = pmssJournalHolderFrame((string) @file_get_contents($task.'/stack'));
        if ($frame === null) continue;
        $uid = pmssJournalHolderUid($task);
        $key = $uid ?? 'outside';
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        if ($counts === null) $counts = pmssJournalHolderDailyCounts($deadline);
        if ($counts === null) break;
        if ($uid === null) {
            pmssJournalHolderLog('journal-holder-outside-user-slice', null, null, $frame, $kernel, 0);
            continue;
        }
        $file = 'blkio.throttle.read_iops_device';
        $path = pmssCgroupDirectUserBlkioFilePath($uid, $file, $blkioRoot);
        if (!pmssCgroupDirectUserBlkioPathAllowed($path, [$file], $blkioRoot) || is_link($path)) continue;
        $cap = is_file($path) ? pmssCgroupDirectDeviceValueParse((string) @file_get_contents($path), $majorMinor) : null;
        if ($cap === null || $cap <= 0) {
            pmssJournalHolderLog('journal-holder-uncapped', $uid, $cap, $frame, $kernel, $counts[$uid] ?? 0);
            continue;
        }
        // Recheck identity after proc reads; a task exit or cgroup move must never redirect a release.
        if (pmssJournalHolderUid($task) !== $uid || microtime(true) >= $deadline
            || !pmssCgroupDirectWritableFileTarget($path)) continue;
        $result = pmssIopsClearThrottle($path, $majorMinor, false, $blkioRoot);
        if (!$result['ok'] || $result['reason'] !== 'written') continue;
        $written++; $counts[$uid] = ($counts[$uid] ?? 0) + 1;
        if (!pmssJournalHolderLog('journal-holder-release', $uid, $cap, $frame, $kernel, $counts[$uid])) {
            syslog(LOG_WARNING, 'journal-holder-release audit append failed');
        }
    }
    return $written;
}
