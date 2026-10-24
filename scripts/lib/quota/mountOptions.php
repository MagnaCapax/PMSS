<?php
/** Quota fstab option convergence and its legacy policy messages. @license GPL-3.0-only */
require_once __DIR__.'/../update/logging.php';
require_once __DIR__.'/../update/fstab.php';
require_once __DIR__.'/../update/managedPath.php';

/**
 * Ensure the given mount point in /etc/fstab contains the quota options.
 */
function pmssEnsureQuotaOptions(string $mountPoint, ?array $requiredOptions = null, ?callable $logger = null, ?string $fstabPath = null): void
{
    pmssQuotaFstabOptionsApply($mountPoint, $requiredOptions ?? ['usrjquota=aquota.user', 'grpjquota=aquota.group', 'jqfmt=vfsv1'], 'quota', $logger, $fstabPath);
}

/** Apply the quota mount policies through one read/plan/backup flow, retaining their log text. */
function pmssQuotaFstabOptionsApply(string $mountPoint, array $requiredOptions, string $policy, ?callable $logger, ?string $fstabPath): void
{
    $log = $logger ?: 'logMessage';
    if ($mountPoint === '') {
        return;
    }
    $fstab = $fstabPath ?? '/etc/fstab';
    [$context, $missing, $unchanged, $changed] = [
        'quota' => ['quota', 'quota updates', 'Quota options', 'Updated quota options'],
        'journal' => ['journal commit', 'journal commit update', 'Journal commit option', 'Updated journal commit option'],
        'nofail' => ['nofail', 'nofail update', 'nofail option', 'Added nofail option'],
    ][$policy];
    $lines = pmssFstabLinesRead($fstab, $log, $context.' configuration.');
    if ($lines === null) {
        return;
    }

    $replacePrefixedOptions = [];
    foreach ($requiredOptions as $requiredOption) {
        $equalsPosition = strpos($requiredOption, '=');
        if ($equalsPosition !== false) {
            $replacePrefixedOptions[substr($requiredOption, 0, $equalsPosition + 1)] = $requiredOption;
        }
    }
    // nofail adds only its flag; quota and journal updates also drop a lone defaults token.
    $plan = pmssFstabMountOptionsEnsure($lines, $mountPoint, $requiredOptions, [], $policy !== 'nofail', null, $replacePrefixedOptions);
    if ($plan === null) {
        $log('[WARN] Mount point '.$mountPoint.' not found in '.$fstab.'; skipping '.$missing.'.');
        return;
    }

    if (!$plan['changed']) {
        $log('[SKIP] '.$unchanged.' already present for '.$mountPoint);
        return;
    }

    $log('[WARN] '.$changed.' for '.$mountPoint.pmssFstabPlanChangeSuffix($plan));
    pmssWriteManagedPathFileWithBackup($fstab, $lines, 'fstab', $log, true);
}

/**
 * Ensure the given mount point in /etc/fstab carries an ext4 journal commit interval.
 *
 * A longer commit interval (default 60s vs the ext4 default 5s) batches journal commits, easing
 * jbd2 write-convoy contention on shared RAID5 seedbox hosts. `commit=` is remount-able, so
 * pmssConfigureQuotaMount's remount applies it live — no reboot needed. Uses the same
 * backup-protected fstab helpers as pmssEnsureQuotaOptions.
 *
 * NOTE: `data=writeback` is deliberately NOT set here. It trades crash-consistency for speed and
 * requires a reboot to take effect — an operator policy decision, not an automatic default.
 */
function pmssEnsureJournalCommitOption(string $mountPoint, int $seconds = 60, ?callable $logger = null, ?string $fstabPath = null): void
{
    if ($mountPoint === '' || $seconds < 1) {
        return;
    }
    pmssQuotaFstabOptionsApply($mountPoint, ['commit='.$seconds], 'journal', $logger, $fstabPath);
}

/**
 * Ensure the given mount point carries `nofail` so a failed mount at boot does not hang the host.
 *
 * PMSS hosts are remote-managed: a `/home` mount failure should leave the host REACHABLE (SSH up)
 * for recovery, not drop to an interactive emergency prompt that needs console/IPMI. The Phase 5.3
 * pre-reboot checklist expects `nofail` on `/home` for exactly this reason (a host observed in the
 * fleet without it stalled the pre-reboot check). `nofail` is a boot-time option (NOT remount-able,
 * unlike `commit=`), so this only writes fstab — it takes effect on the next boot. Minimal delta:
 * it adds `nofail` and touches no other option.
 */
function pmssEnsureMountNofailOption(string $mountPoint, ?callable $logger = null, ?string $fstabPath = null): void
{
    pmssQuotaFstabOptionsApply($mountPoint, ['nofail'], 'nofail', $logger, $fstabPath);
}
