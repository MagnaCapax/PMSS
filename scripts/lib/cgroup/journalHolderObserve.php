<?php
/** Proc-only journal/holder evidence; never acquire task arguments or environment. @license GPL-3.0-only */
declare(strict_types=1);

const PMSS_JOURNAL_HOLDER_FRAMES = ['ext4_wait_block_bitmap', 'ext4_read_block_bitmap', 'ext4_read_bh',
    '__read_extent_tree_block', 'ext4_mb_init_cache', 'ext4_read_inode_bitmap'];
const PMSS_JOURNAL_HOLDER_WAITERS = ['start_this_handle', 'wait_transaction_locked', 'jbd2_log_wait_commit'];
const PMSS_JOURNAL_HOLDER_SAMPLE_SECONDS = 5;

/** Unknown releases fail closed; vendor suffixes retain the upstream major/minor gate. */
function pmssJournalHolderKernelAffected(string $kernel): bool
{
    return preg_match('/^([0-9]+)\.([0-9]+)(?:[.\-+]|$)/D', $kernel, $m) === 1
        && ((int) $m[1] < 6 || ((int) $m[1] === 6 && (int) $m[2] < 12));
}

/** Parse stat before touching stack, including comm values containing spaces or parentheses. */
function pmssJournalHolderTaskStat(string $task): ?array
{
    $raw = @file_get_contents($task.'/stat');
    if (!is_string($raw) || preg_match('/^([1-9][0-9]*) \((.*)\) ([A-Z]) (.*)$/sD', trim($raw), $m) !== 1
        || $m[1] !== basename($task)) return null;
    $fields = preg_split('/\s+/', $m[4]);
    $flags = pmssUnsignedDecimalIntParse($fields[5] ?? '');
    return $flags === null ? null : ['state' => $m[3], 'name' => $m[2], 'kernel' => ($flags & 0x00200000) !== 0];
}

/** Extract exact frame symbols only; substrings in unrelated symbols are not evidence. */
function pmssJournalHolderStackHas(string $stack, string $symbol): bool
{
    return preg_match('/(?:^|\s)'.preg_quote($symbol, '/').'\+0x[0-9a-f]+\/0x[0-9a-f]+(?:\s|$)/m', $stack) === 1;
}

/** A metadata reader already waiting to enter/commit a transaction cannot hold this journal open. */
function pmssJournalHolderFrame(string $stack): ?string
{
    foreach (PMSS_JOURNAL_HOLDER_WAITERS as $symbol) if (pmssJournalHolderStackHas($stack, $symbol)) return null;
    foreach (PMSS_JOURNAL_HOLDER_FRAMES as $symbol) if (pmssJournalHolderStackHas($stack, $symbol)) return $symbol;
    return null;
}

/** Enumerate thread IDs too: a worker, rather than the process leader, can own the open handle. */
function pmssJournalHolderTasks(string $procRoot): iterable
{
    foreach (glob($procRoot.'/[0-9]*', GLOB_ONLYDIR) ?: [] as $process) {
        $tasks = glob($process.'/task/[0-9]*', GLOB_ONLYDIR) ?: [$process];
        foreach ($tasks as $task) yield $task;
    }
}

/** Match the backing device's kernel journal thread using stat, never a user-controlled comm file. */
function pmssJournalHolderJournalFind(string $procRoot, string $device): ?string
{
    foreach (glob($procRoot.'/[0-9]*', GLOB_ONLYDIR) ?: [] as $task) {
        $stat = pmssJournalHolderTaskStat($task);
        if ($stat !== null && $stat['kernel']
            && preg_match('#^jbd2/'.preg_quote(basename($device), '#').'-[0-9]+$#D', $stat['name']) === 1) return $task;
    }
    return null;
}

/** Accept only a single blkio hierarchy and slice token, cross-checked against the task's real UID. */
function pmssJournalHolderUid(string $task): ?int
{
    $cgroup = @file_get_contents($task.'/cgroup');
    $status = @file_get_contents($task.'/status');
    if (!is_string($cgroup) || !is_string($status) || strpos($cgroup, "\0") !== false) return null;
    $uid = null; $found = false;
    foreach (explode("\n", trim($cgroup)) as $line) {
        if (preg_match('#^[0-9]+:([a-z0-9_,=]*):(/[^\r\n]*)$#D', $line, $m) !== 1) return null;
        if (!in_array('blkio', explode(',', $m[1]), true)) continue;
        if ($found) return null;
        $found = true;
        // The path contributes only this numeric token, never any part of a write target.
        if (preg_match('#^/user\.slice/user-([1-9][0-9]*)\.slice(?:/[^\x00-\x20:]*)?$#D', $m[2], $slice) !== 1
            || preg_match('#(?:^|/)\.\.?(?:/|$)#', $m[2]) === 1
            || preg_match_all('#(?:^|/)user-[^/]*\.slice(?=/|$)#', $m[2]) !== 1) return null;
        $uid = pmssUnsignedDecimalIntParse($slice[1]);
    }
    if ($uid === null || $uid <= 0 || preg_match('/^Uid:\s+([0-9]+)[ \t]+[0-9]+[ \t]+[0-9]+[ \t]+[0-9]+[ \t]*$/m', $status, $real) !== 1) return null;
    return pmssUnsignedDecimalIntParse($real[1]) === $uid ? $uid : null;
}

/** Read the exact /home mount without launching findmnt; keep source/device validation in the shared resolvers. */
function pmssJournalHolderHome(string $procRoot, string $sysBlockRoot): ?array
{
    $mounts = @file_get_contents($procRoot.'/self/mountinfo');
    if (!is_string($mounts)) return null;
    $home = null;
    foreach (explode("\n", $mounts) as $line) {
        $parts = explode(' - ', $line, 2);
        $before = explode(' ', $parts[0]); $after = explode(' ', $parts[1] ?? '');
        if (($before[4] ?? '') === '/home') $home = [$after[0] ?? '', $after[1] ?? '', $before[2] ?? ''];
    }
    if ($home === null || $home[0] !== 'ext4') return null;
    $device = pmssCgroupPolicyMountSourceResolve('/home', static function () use ($home): string { return $home[1]; });
    $majorMinor = pmssCgroupPolicyDeviceMajorMinorResolve($device, static function (string $path) use ($sysBlockRoot): string {
        return trim((string) @file_get_contents($sysBlockRoot.'/'.basename(dirname($path)).'/dev'));
    });
    return $majorMinor !== null && $majorMinor === $home[2] ? [$device, $majorMinor] : null;
}
