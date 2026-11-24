<?php
/**
 * Re-apply the per-host file quota ratio to existing accounts when the ratio changes (ADR 0342).
 *
 * `userApplyDiskQuota()` runs only in `userConfig.php` full mode, so editing
 * `/etc/seedbox/config/quotaFilesPerGiB` would otherwise reach new or changed accounts only. The
 * update calls `pmssQuotaFilesReapplyRun()` once per run: it compares the effective ratio with the
 * last applied one under /var/lib/pmss and, only when they differ, re-applies every account's
 * stored quota through the same function that set it, then records the new ratio.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/traffic.php';
require_once __DIR__.'/quotaFilesPerGiB.php';
require_once __DIR__.'/selection.php';
require_once __DIR__.'/userConfigStore.php';
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
 * Files each account holds now, keyed by username, from one repquota report of /home. An unreadable
 * report gives an empty map, so the over-limit log is skipped rather than guessed.
 *
 * @param array<int,string> $users
 * @return array<string,int>
 */
function pmssQuotaFilesInUse(array $users): array
{
    $report = pmssQuotaSnapshotFilesystemReport('/home');
    if ($report['timed_out'] || $report['rc'] > 1) {
        return [];
    }
    $byUid = [];
    foreach (pmssQuotaSnapshotParseRepquotaUserRows($report['lines']) as $row) {
        // Row: uid, blocks used, soft, hard, files used, soft, hard.
        $byUid[(int) $row[0]] = (int) $row[4];
    }
    $files = [];
    foreach ($users as $user) {
        $account = function_exists('posix_getpwnam') ? @posix_getpwnam($user) : false;
        if (is_array($account) && isset($byUid[$account['uid']])) {
            $files[$user] = $byUid[$account['uid']];
        }
    }
    return $files;
}

/**
 * Re-apply the file quota ratio to existing accounts when it differs from the last applied one.
 *
 * Block limits are rebuilt from the same stored quota the accounts already have, so only the file
 * limits change. A failed apply leaves the record untouched and the next update retries.
 *
 * @param array<int,string>|null $users Accounts to process; null lists the managed accounts.
 * @param callable|null $apply Takes the account array, returns bool; defaults to userApplyDiskQuota.
 * @param callable|null $filesInUse Takes account names, returns files held by name; defaults to pmssQuotaFilesInUse.
 */
function pmssQuotaFilesReapplyRun(?array $users = null, ?callable $apply = null, ?callable $filesInUse = null): int
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
    $store = new UserConfigStore();
    logMessage("File quota ratio changed from {$applied} to {$ratio} files per GiB; re-applying to ".count($users).' account(s)');

    $failed = 0;
    $limits = [];
    foreach ($users as $name) {
        $stored = $store->get($name);
        $quota = is_array($stored) && is_numeric($stored['quota'] ?? null) ? (int) $stored['quota'] : 0;
        if ($quota <= 0) {
            logMessage("[SKIP] File quota ratio for {$name}: no stored quota; rerun full userConfig.php first");
            continue;
        }
        if ($apply(['name' => $name, 'quota' => $quota]) !== true) {
            $failed++;
            logMessage("[WARN] File quota ratio for {$name}: applying the quota failed");
            continue;
        }
        $limits[$name] = pmssQuotaFilesLimit($quota, $ratio)['soft'];
    }

    // A lowered ratio can leave accounts above their new soft limit: name them so the grace period is no surprise.
    if ($ratio < $applied && $limits !== []) {
        $inUse = ($filesInUse ?? 'pmssQuotaFilesInUse')(array_keys($limits));
        foreach ($limits as $name => $soft) {
            if (isset($inUse[$name]) && $inUse[$name] > $soft) {
                logMessage("[WARN] File quota ratio for {$name}: holds {$inUse[$name]} files, above the new soft limit {$soft}");
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
