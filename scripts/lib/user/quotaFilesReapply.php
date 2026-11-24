<?php
/**
 * Re-apply the per-host file quota ratio to existing accounts when the ratio changes (ADR 0342).
 *
 * `userApplyDiskQuota()` runs only in `userConfig.php` full mode, so editing
 * `/etc/seedbox/config/quotaFilesPerGiB` would otherwise reach new or changed accounts only. The
 * update calls `pmssQuotaFilesReapplyRun()` once per run: it compares the effective ratio with the
 * last applied one under /var/lib/pmss and, only when they differ, sets every account's file limits
 * from the new ratio, then records it. Block limits are never recomputed: each account's live kernel
 * block soft and hard limits are read first and written back unchanged, because the stored json quota
 * can be older than the kernel quota (a raw setquota that raised it does not update the json).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/traffic.php';
require_once __DIR__.'/quotaFilesPerGiB.php';
require_once __DIR__.'/selection.php';
require_once dirname(__DIR__).'/quota/snapshotUsage.php';

/** Record of the ratio last applied to the accounts; no record means the historical 500. */
function pmssQuotaFilesAppliedPath(): string
{
    return pmssStateDir().'/quotaFilesPerGiBApplied';
}

/** The ratio last applied to the accounts. */
function pmssQuotaFilesAppliedRead(): int
{
    return pmssQuotaFilesPerGiBParse(pmssReadRegularFileContentsVerified(pmssQuotaFilesAppliedPath(), null, 64))
        ?? PMSS_QUOTA_FILES_PER_GIB_DEFAULT;
}

/**
 * Live block and file counters of each account, keyed by username, from one fresh repquota report
 * of /home (never the cached snapshot report: a stale read could be written back as a limit). An
 * account the report does not carry is missing from the map; an unreadable report gives an empty map.
 *
 * @param array<int,string> $users
 * @return array<string,array{blockSoft:int,blockHard:int,files:int}> KiB limits and files held.
 */
function pmssQuotaLiveRows(array $users): array
{
    $report = pmssQuotaReportCapture('LC_ALL=C repquota -u -n /home 2>&1');
    if ($report['timed_out'] || $report['rc'] > 1 || strpos(implode("\n", $report['lines']), 'Report for user quotas on device') === false) {
        return [];
    }
    $byUid = [];
    foreach (pmssQuotaSnapshotParseRepquotaUserRows($report['lines']) as $row) {
        // Row: uid, blocks used, soft, hard, files used, soft, hard.
        $byUid[(int) $row[0]] = ['blockSoft' => (int) $row[2], 'blockHard' => (int) $row[3], 'files' => (int) $row[4]];
    }
    $live = [];
    foreach ($users as $user) {
        $account = function_exists('posix_getpwnam') ? @posix_getpwnam($user) : false;
        if (is_array($account) && isset($byUid[$account['uid']])) {
            $live[$user] = $byUid[$account['uid']];
        }
    }
    return $live;
}

/**
 * Re-apply the file quota ratio to existing accounts when it differs from the last applied one.
 *
 * Only the file limits change. Each account's block soft and hard limits are read live from the
 * kernel and written back as they are, and the file limits are sized from the live block soft limit:
 * the stored json quota is stale whenever something raised the kernel quota without rewriting it, and
 * writing it back would lower the account's disk quota. An account whose live limits cannot be read
 * is left untouched and counted as failed, so nothing is recorded and the next update retries; an
 * account with no live block soft limit (unlimited) is skipped.
 *
 * @param array<int,string>|null $users Accounts to process; null lists the managed accounts.
 * @param callable|null $apply Takes the account array (name, quota GiB, blockSoftKiB, blockHardKiB), returns bool; defaults to userApplyDiskQuota.
 * @param callable|null $liveRows Takes account names, returns live rows by name (see pmssQuotaLiveRows); defaults to pmssQuotaLiveRows.
 */
function pmssQuotaFilesReapplyRun(?array $users = null, ?callable $apply = null, ?callable $liveRows = null): int
{
    $ratio = pmssQuotaFilesPerGiB();
    $applied = pmssQuotaFilesAppliedRead();
    if ($ratio === $applied) {
        return 0;
    }
    $apply = $apply ?? 'userApplyDiskQuota';
    // A failed account listing must not look like "no accounts": it would record a ratio nobody got.
    $users = $users ?? pmssListManagedUsersFromResult(pmssListManagedUsersResult());
    if ($users === null) {
        logMessage('[WARN] File quota ratio not applied: the account list could not be read; the next update retries');
        return 1;
    }
    logMessage("File quota ratio changed from {$applied} to {$ratio} files per GiB; re-applying to ".count($users).' account(s)');

    $live = ($liveRows ?? 'pmssQuotaLiveRows')($users);
    $failed = 0;
    $limits = [];
    foreach ($users as $name) {
        $row = $live[$name] ?? null;
        if ($row === null) {
            $failed++;
            logMessage("[WARN] File quota ratio for {$name}: live block limits could not be read; account left unchanged, the next update retries");
            continue;
        }
        if ($row['blockSoft'] <= 0) {
            logMessage("[SKIP] File quota ratio for {$name}: no live block soft limit; account left unchanged");
            continue;
        }
        $quotaGiB = intdiv($row['blockSoft'], 1024 * 1024);
        $account = ['name' => $name, 'quota' => $quotaGiB, 'blockSoftKiB' => $row['blockSoft'], 'blockHardKiB' => $row['blockHard']];
        if ($apply($account) !== true) {
            $failed++;
            logMessage("[WARN] File quota ratio for {$name}: applying the file limits failed");
            continue;
        }
        $limits[$name] = pmssQuotaFilesLimit($quotaGiB, $ratio)['soft'];
    }

    // A lowered ratio can leave accounts above their new soft limit: name them so the grace period is no surprise.
    if ($ratio < $applied) {
        foreach ($limits as $name => $soft) {
            if ($live[$name]['files'] > $soft) {
                logMessage("[WARN] File quota ratio for {$name}: holds {$live[$name]['files']} files, above the new soft limit {$soft}");
            }
        }
    }

    if ($failed > 0) {
        logMessage("[WARN] File quota ratio not recorded: {$failed} account(s) failed; the next update retries");
        return 1;
    }
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        return 0;
    }
    if (!pmssAtomicWriteFile(pmssQuotaFilesAppliedPath(), $ratio."\n", 0644)) {
        logMessage('[WARN] File quota ratio applied but the record could not be written; the next update repeats it');
        return 1;
    }
    return 0;
}
