<?php
/** Declared quota types, live enforcement and recalculation eligibility. @license GPL-3.0-only */
require_once __DIR__.'/../update/fstab.php';
require_once __DIR__.'/commands.php';

/**
 * List the quota types a mount declares in fstab, in user, group order.
 *
 * @return ?list<string> null when fstab or its mount entry cannot be read
 */
function pmssQuotaMountDeclaredTypes(string $mountPoint, callable $log, ?string $fstabPath, string $context, bool $journaledOnly = false): ?array
{
    $lines = pmssFstabLinesRead($fstabPath ?? '/etc/fstab', $log, $context);
    $entry = $lines === null ? null : pmssFstabMountEntryRead($lines, $mountPoint);
    if ($entry === null) {
        return null;
    }

    // Each type counts once even if both journaled and plain options appear.
    $pattern = $journaledOnly ? '/^(usr|grp)jquota=/' : '/^(usr|grp)(?:jquota=|quota(?:=|$))/';
    $types = [];
    foreach (explode(',', $entry['columns'][3]) as $option) {
        if (preg_match($pattern, $option, $match) === 1) {
            $types[] = $match[1];
        }
    }
    return array_keys(array_intersect(['user' => 'usr', 'group' => 'grp'], $types));
}

/** Preserve the maintenance API's /home target and nullable declaration result. */
function pmssQuotaHomeDeclaredTypes(callable $log, ?string $fstabPath, string $context): ?array
{
    return pmssQuotaMountDeclaredTypes('/home', $log, $fstabPath, $context);
}

/**
 * Count the quota types /home declares in fstab and, when any, how many the kernel has on.
 *
 * @return ?array{declared:int,enabled:int} null when fstab or its /home entry cannot be read
 */
function pmssQuotaHomeEnforcementCounts(?callable $runner, callable $log, ?string $fstabPath, string $context): ?array
{
    $types = pmssQuotaHomeDeclaredTypes($log, $fstabPath, $context);
    if ($types === null) {
        return null;
    }
    // quotaon -p returns the number of enabled types, not a success status.
    return ['declared' => count($types), 'enabled' => $types === [] ? 0 : pmssQuotaCommandRun('quotaon -p /home', $runner)['rc']];
}

/** Stamp quotaFix writes after a completed recalculation with enforcement verified on. */
function pmssQuotaRecalculationStampPath(): string
{
    return pmssStateDir().'/quota-recalculation.complete';
}

/**
 * Whether a routine update needs quotaFix's quotaoff/quotacheck/quotaon cycle (ADR 0140).
 *
 * quotacheck writes back the limits it read when it started, so any limit set while it runs
 * is lost. The cycle runs when the last one did not complete (no stamp: killed, failed, never
 * run) or /home has fewer quota types on than fstab declares. An unreadable fstab or a /home
 * declaring none keeps the previous always-run behaviour. Call it before the update remounts
 * /home: a remount turns journaled quota back on without recounting usage.
 */
function pmssQuotaHomeRecalculationNeeded(?callable $runner = null, ?callable $logger = null, ?string $fstabPath = null, ?string $stampPath = null): bool
{
    if (!is_file($stampPath ?? pmssQuotaRecalculationStampPath())) {
        return true;
    }
    $counts = pmssQuotaHomeEnforcementCounts($runner, $logger ?: 'logMessage', $fstabPath, 'quota recalculation check.');

    return $counts === null || $counts['declared'] === 0 || $counts['enabled'] < $counts['declared'];
}

/**
 * Warn when /home declares more quota types than the kernel has enabled.
 *
 * @return ?array{declared:int,enabled:int}
 */
function pmssQuotaHomeEnforcementWarn(?callable $runner = null, ?callable $logger = null, ?string $fstabPath = null): ?array
{
    $log = $logger ?: 'logMessage';
    $counts = pmssQuotaHomeEnforcementCounts($runner, $log, $fstabPath, 'quota enforcement verification.');
    if ($counts !== null && $counts['declared'] > 0 && $counts['enabled'] < $counts['declared']) {
        $log('[quotaFix] WARNING: /home declares '.$counts['declared'].' quota type(s) in fstab but '.$counts['enabled'].' are on');
    }

    return $counts;
}
