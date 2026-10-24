<?php
/** Quota mount orchestration shared by installer and updater. @license GPL-3.0-only */
require_once __DIR__.'/mountOptions.php';
require_once __DIR__.'/commands.php';
require_once __DIR__.'/files.php';

/**
 * Ensure quota options exist for the requested mount and remount it.
 */
function pmssConfigureQuotaMount(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    if (!pmssEnvValueIsFalsey(getenv('PMSS_SKIP_QUOTA'))) {
        $log('[SKIP] Quota configuration skipped via PMSS_SKIP_QUOTA');
        return;
    }

    $mount = trim(pmssResolvePathFromEnv('PMSS_QUOTA_MOUNT', '/home'));
    pmssEnsureQuotaOptions($mount, null, $log);
    // Default ext4 journal commit interval (60s vs the 5s ext4 default) — batches jbd2 commits to
    // ease RAID5 write-convoy contention on shared seedbox hosts. The remount below applies it live
    // (commit= is remount-able). Skippable via PMSS_SKIP_QUOTA (same gate that guards this whole step).
    pmssEnsureJournalCommitOption($mount, 60, $log);
    // Ensure `nofail` so a boot-time mount failure of this mount leaves the host REACHABLE for
    // remote recovery instead of hanging at an emergency prompt (Phase 5.3 pre-reboot expectation).
    // Boot-only option — no live remount needed, so it is applied before the remount below.
    pmssEnsureMountNofailOption($mount, $log);
    if (!is_dir($mount)) {
        $log('[WARN] Skipping remount for '.$mount.' (mount path not found)');
        return;
    }

    // Ext4 refuses to re-apply journaled-quota options once any quota type is on.
    // A fresh install has the options in fstab but still needs this live remount
    // before quotacheck can create aquota files. Check kernel quota state too.
    $fstabLines = pmssFstabLinesRead('/etc/fstab', $log, 'quota remount journaled-quota check');
    $fstabEntry = $fstabLines !== null ? pmssFstabMountEntryRead($fstabLines, $mount) : null;
    $fstabOptions = $fstabEntry['columns'][3] ?? '';
    if (pmssJournaledQuotaRemountBlocked($mount, $fstabOptions)) {
        $log('[INFO] Skipping remount for '.$mount.' (journaled quota is active; options cannot be re-applied until the next mount)');
    } else {
        runStep('Remounting '.$mount.' to refresh quota options', sprintf('mount -o remount %s', escapeshellarg($mount)));
    }
    pmssWarnUnexpectedQuotaFiles($mount, $log);
}

/** Skip a journaled-quota remount only when user or group quota is live. */
function pmssJournaledQuotaRemountBlocked(string $mount, string $fstabOptions, ?callable $runner = null): bool
{
    if (preg_match('/(?:^|,)(?:jqfmt=|usrjquota=|grpjquota=)/', $fstabOptions) !== 1) {
        return false;
    }
    // quotaon -p exits with the number of enabled quota types (0, 1, or 2).
    $state = pmssQuotaCommandRun('quotaon -p '.escapeshellarg($mount), $runner);
    return in_array($state['rc'], [1, 2], true);
}
