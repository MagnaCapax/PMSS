<?php
/** Aggregate physical memory bound for the shared user slice. @license GPL-3.0-only */

/**
 * Tenant bound in MiB: full RAM minus a reserve; 0 when no useful bound exists.
 *
 * The reserve is max(1536, min(10%, 1.25 x min_free + 512)): the high watermark plus room for
 * system.slice and the kernel. A larger reserve would sit below where global reclaim already
 * holds the tenants and cap their page cache in normal operation (ADR 0296).
 */
function pmssSystemdUserSliceAggregateMemoryMiB(int $fullMiB, int $minFreeMiB = 1024): int
{
    // Integer ceilings keep the reserve rounded up and the tenant bound rounded down.
    $tenthMiB = intdiv(max(0, $fullMiB) + 9, 10);
    $watermarkMiB = intdiv(max(0, $minFreeMiB) * 5 + 3, 4) + 512;
    $boundMiB = $fullMiB - max(1536, min($tenthMiB, $watermarkMiB));
    // Below 1 GiB the host is too small to split into a reserve and a tenant share.
    return $boundMiB >= 1024 ? $boundMiB : 0;
}

/**
 * Converge /etc/systemd/system/user.slice.d/15-pmss-aggregate.conf from its template.
 *
 * Physical bound, not an account limit: per-user limits stay as sold and only the sum of all
 * tenants meets this ceiling, so the kernel kills inside user.slice before system.slice starves.
 * v1 and v2 render the same bytes; systemd maps MemoryMax to the controller of the booted hierarchy.
 */
function pmssSystemdUserSliceAggregateInstall(string $dropDir, string $cfgDir, int $totalMiB, string $mode, callable $log): bool
{
    $tpl = $cfgDir.'/template.cgroup.user-slice.aggregate.conf';
    $template = is_file($tpl) ? @file_get_contents($tpl) : false;
    if ($template === false) {
        // A missing or unreadable template must never replace a working drop-in.
        $log('[WARN] Unable to read user.slice aggregate template: '.$tpl);
        return false;
    }
    // A ballooned guest's MemTotal shrinks with the balloon; size from the RAM it holds when deflated,
    // so a bound rendered at the floor does not cap tenants once the host returns the memory.
    $balloonMiB = pmssBalloonHeldMiB();
    $fullMiB = $totalMiB + $balloonMiB;
    $minFreeMiB = pmssMinFreeMiB();
    $boundMiB = pmssSystemdUserSliceAggregateMemoryMiB($fullMiB, $minFreeMiB);
    if ($boundMiB <= 0) {
        $log('[WARN] Skipping user.slice aggregate memory bound: MemTotal '.$totalMiB.'M leaves no useful tenant bound');
        return false;
    }
    // The parent slice directory sits beside user-.slice.d, as user-0.slice.d does.
    $target = dirname($dropDir).'/user.slice.d/15-pmss-aggregate.conf';
    pmssRefreshManagedPathFile(
        $target,
        strtr($template, ['%%USER_SLICE_AGGREGATE_MEMORY_MAX%%' => (string) $boundMiB]),
        'systemd drop-in',
        $log,
        ['writeFailureMessage' => '[WARN] Failed to install user.slice aggregate drop-in '.$target],
        $ready
    );
    $log(sprintf('user.slice aggregate MemoryMax=%dM (MemTotal=%dM, balloon=%dM, min_free=%dM, mode=%s, ready=%s)', $boundMiB, $totalMiB, $balloonMiB, $minFreeMiB, $mode, $ready ? 'yes' : 'no'));
    return $ready;
}
