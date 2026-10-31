<?php
/** Inherited OOM immunity repair for updater and tenant processes. @license GPL-3.0-only */

/** Reset inherited SSH OOM immunity on the updater and tenant processes (#901). */
function pmssResetInheritedOomImmunity(string $procRoot = '/proc'): int
{
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        logMessage('[SKIP] Reset inherited OOM immunity (dry run)');
        return 0;
    }

    $reset = 0;
    $failed = 0;
    // Self and selected tenant processes share the same write/readback accounting.
    $resetScore = static function (string $score) use (&$reset, &$failed): void {
        if (@file_put_contents($score, '0') !== false && trim((string) @file_get_contents($score)) === '0') {
            ++$reset;
        } else {
            ++$failed;
        }
    };
    $selfScore = $procRoot.'/self/oom_score_adj';
    if (trim((string) @file_get_contents($selfScore)) === '-1000') {
        $resetScore($selfScore);
    }

    // The updater must lose inherited immunity before it launches tenant services.
    foreach (pmssDirectoryEntriesRead($procRoot) ?: [] as $pid) {
        if (!ctype_digit($pid) || !is_dir($procRoot.'/'.$pid)) {
            continue;
        }
        $path = $procRoot.'/'.$pid;
        $score = $path.'/oom_score_adj';
        if (trim((string) @file_get_contents($score)) !== '-1000') {
            continue;
        }
        $cgroup = @file_get_contents($path.'/cgroup');
        $inUserSlice = is_string($cgroup) && strpos($cgroup, '/user.slice/') !== false;
        $status = $inUserSlice ? '' : @file_get_contents($path.'/status');
        $hasUserUid = is_string($status) && preg_match('/^Uid:\s*(\d+)(?:\s|$)/m', $status, $uid) === 1
            && (int) $uid[1] >= 1000;
        if (!$inUserSlice && !$hasUserUid) {
            continue;
        }
        $resetScore($score);
    }

    logMessage(($failed ? '[WARN]' : '[INFO]').' Reset inherited OOM immunity: '.$reset.' process(es) -1000 -> 0'.
        ($failed ? ' ('.$failed.' failed)' : ''));
    return $reset;
}
