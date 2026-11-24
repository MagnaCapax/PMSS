<?php
/**
 * Grow or set one account's disk quota: kernel limits first, then the stored record (ADR 0342).
 *
 * `userQuotaApply.php <user> <quotaGiB>` is the call for a quota that changes after provisioning. `userApplyDiskQuota()`
 * writes the block limits from the quota and the file limits at the host ratio
 * (`/etc/seedbox/config/quotaFilesPerGiB`); the store then gets `quota` and `quotaBurst`, the two
 * keys userConfig.php writes for the quota, so the stored value cannot fall behind the kernel's.
 * It starts no process and never touches rTorrent.
 *
 * Exit codes: 0 applied and stored; 1 the kernel apply failed, nothing stored; 2 bad arguments or
 * account; 3 the kernel limits were applied but the store write failed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/identity.php';
require_once __DIR__.'/traffic.php';
require_once __DIR__.'/userConfigStore.php';

const PMSS_USER_QUOTA_APPLY_USAGE = "Usage: userQuotaApply.php USERNAME QUOTA_GiB\n";

/**
 * Resolve an existing account the way userConfig.php does: normalized valid name, passwd entry,
 * uid of at least 1000, and a home directory that is /home/<name> and exists.
 *
 * @return array{name:string,uid:int}|null
 */
function pmssUserQuotaApplyAccount(string $rawName): ?array
{
    $name = pmssNormalizeUsername($rawName);
    $passwd = pmssPasswdEntryLookup($name);
    if ($passwd === null) {
        return null;
    }
    $account = pmssUserAccountLookup($name) ?? $passwd;
    $uid = (int) ($account['uid'] ?? 0);
    $home = '/home/'.$name;
    if ($uid < 1000 || rtrim((string) ($passwd['dir'] ?? ($account['dir'] ?? '')), '/') !== $home || !file_exists($home)) {
        return null;
    }
    return ['name' => $name, 'uid' => $uid];
}

/**
 * @param array<int,string> $argv
 * @param callable|null $resolve Takes the raw name, returns the account array or null; defaults to pmssUserQuotaApplyAccount.
 * @param callable|null $apply Takes ['name','quota'], returns bool; defaults to userApplyDiskQuota.
 * @param UserConfigStore|null $store Defaults to the host store.
 */
function pmssUserQuotaApplyCli(array $argv, ?callable $resolve = null, ?callable $apply = null, ?UserConfigStore $store = null): int
{
    $args = array_slice($argv, 1);
    if (count($args) !== 2 || preg_match('/^[1-9][0-9]{0,6}$/D', $args[1]) !== 1) {
        return pmssCliReturnWithStderr(PMSS_USER_QUOTA_APPLY_USAGE.'QUOTA_GiB is a positive whole number of GiB.'."\n", 2);
    }
    $account = ($resolve ?? 'pmssUserQuotaApplyAccount')($args[0]);
    if ($account === null) {
        return pmssCliReturnWithStderr("Error: no such PMSS account\n", 2);
    }
    $name = $account['name'];
    $quota = (int) $args[1];

    if (($apply ?? 'userApplyDiskQuota')(['name' => $name, 'quota' => $quota]) !== true) {
        return pmssCliReturnWithStderr("Error: applying the disk quota failed for {$name}; nothing stored\n", 1);
    }

    // Same keys and burst rule as userConfig.php; every other stored key stays as it is.
    $store = $store ?? new UserConfigStore();
    $payload = array_merge($store->get($name) ?? [], ['quota' => $quota, 'quotaBurst' => (int) round($quota * 1.25)]);
    if (!$store->persist($name, $payload)) {
        return pmssCliReturnWithStderr("Error: disk quota applied for {$name} but the user store write failed\n", 3);
    }
    return 0;
}
