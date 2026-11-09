<?php
/** Shared direct cgroup-v1 blkio guardrails.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/log.php';
require_once dirname(__DIR__).'/user/identity.php';
require_once __DIR__.'/directPolicy.php';
require_once __DIR__.'/directFiles.php';

/** Inspect the runtime boundary in a form unit tests can exercise. */
function pmssCgroupDirectRuntimeCheck(string $skipMessage, ?int $euid = null, string $blkioRoot = PMSS_CGROUP_DIRECT_BLKIO_ROOT): array
{
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {
        return ['exitCode' => 2, 'message' => "FATAL: POSIX extension required to resolve managed user UIDs"];
    }
    if (($euid ?? posix_geteuid()) !== 0) {
        return ['exitCode' => 2, 'message' => "FATAL: must run as root (writes to /sys/fs/cgroup/blkio/)"];
    }
    if (!is_dir($blkioRoot)) {
        return ['exitCode' => 0, 'message' => rtrim($skipMessage, "\n")];
    }

    return ['exitCode' => null, 'message' => ''];
}

/** Require the common runtime boundary before any managed-account sysfs writes. */
function pmssCgroupDirectRequireRuntime(string $skipMessage): void
{
    $check = pmssCgroupDirectRuntimeCheck($skipMessage);
    if ($check['exitCode'] !== null) {
        fwrite(STDERR, $check['message']."\n");
        exit((int) $check['exitCode']);
    }
}

/** Yield valid user config payloads and count malformed entries as errors. */
function pmssCgroupDirectUserConfigs(string $usersDir, int &$errors): iterable
{
    foreach (glob(rtrim($usersDir, '/').'/*.json') ?: [] as $cfgPath) {
        $user = basename($cfgPath, '.json');
        if (!pmssValidateUsername($user)) {
            $errors++;
            syslog(LOG_WARNING, 'invalid username config '.str_replace(["\r", "\n", "\0"], '?', $user));
            continue;
        }

        if (!is_array($json = pmssJsonFileReadAssoc($cfgPath))) {
            $errors++;
            syslog(LOG_WARNING, "bad json $user");
            continue;
        }

        yield [$user, $json];
    }
}

/** Yield counted user plans after shared config filtering and UID validation. */
function pmssCgroupDirectPlannedUsers(string $usersDir, int &$total, int &$errors, callable $planner, ?callable $uidResolver = null): iterable
{
    $uidResolver = $uidResolver ?: 'pmssCgroupDirectUserUidOrError';
    foreach (pmssCgroupDirectUserConfigs($usersDir, $errors) as [$user, $json]) {
        if (($plan = $planner($user, $json)) === null) continue;
        $total++;
        if (($uid = $uidResolver($user, $errors)) === null) continue;
        yield [$user, $uid, $plan];
    }
}

/** Resolve a managed user's positive UID, logging legacy-compatible failures. */
function pmssCgroupDirectUserUidOrError(string $user, int &$errors): ?int
{
    $pwd = posix_getpwnam($user);
    $uid = $pwd === false ? null : pmssPasswdEntryPositiveUid($pwd);
    if ($uid === null) {
        $errors++;
        syslog(LOG_WARNING, ($pwd === false ? 'no passwd entry ' : 'unsafe passwd uid ').$user);
    }
    return $uid;
}

/** Audit successful direct writes privately; failures warn without affecting enforcement. */
function pmssCgroupDirectAudit(string $user, int $uid, string $control, ?int $old, int $new, string $source): bool
{
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) return true;
    try {
        $payload = pmssJsonEncodeSafe(['ts' => gmdate('c'), 'user' => $user, 'uid' => $uid,
            'control' => $control, 'old' => $old, 'new' => $new, 'source' => basename($source)]);
        if ($payload !== null && pmssRunSnapshotLogTask(__FILE__, 'PMSS_CGROUP_APPLY_LOG', '/var/log/pmss/cgroup-apply.jsonl',
            static function ($handle) use ($payload): int { return pmssStreamWriteAll($handle, $payload."\n") ? 0 : 1; }) === 0) return true;
    } catch (\Throwable $error) {
        // The cap is already applied. Do not turn an audit failure into a failed cycle.
    }
    syslog(LOG_WARNING, 'cgroup-apply audit log failed');
    return false;
}

/** Log and optionally print the legacy cycle summary, then return process rc. */
function pmssCgroupDirectFinishCycle(bool $dryRun, int $total, int $written, int $skippedNoSlice, int $errors): int
{
    $tag = $dryRun ? 'DRY-RUN' : 'apply';
    $msg = "$tag cycle total=$total written=$written skipped_no_slice=$skippedNoSlice errors=$errors";
    syslog(LOG_INFO, $msg);
    if ($dryRun) echo "$msg\n";
    closelog();
    return $errors > 0 ? 1 : 0;
}

/**
 * Write "MAJ:MIN VALUE" to the cgroup blkio.throttle file IF current state differs.
 * Returns true if written or already correct; false on error.
 */
function pmssIopsWriteThrottle(string $cgPath, string $majMin, int $iops, bool $dryRun): array
{
    return pmssIopsThrottleApply($cgPath, $majMin, $iops, $dryRun);
}

/**
 * Remove OUR device's read or write throttle line ("MAJ:MIN 0" in cgroup v1); absent is already clear.
 */
function pmssIopsClearThrottle(string $cgPath, string $majMin, bool $dryRun): array
{
    return pmssIopsThrottleApply($cgPath, $majMin, null, $dryRun);
}

/** One throttle lifecycle; null requests clearing, while zero remains an invalid set value. */
function pmssIopsThrottleApply(string $cgPath, string $majMin, ?int $iops, bool $dryRun): array
{
    if (!pmssCgroupPolicyMajorMinorIsValid($majMin)
        || ($iops !== null && $iops <= 0)
        || !pmssCgroupDirectUserBlkioPathAllowed($cgPath, ['blkio.throttle.read_iops_device', 'blkio.throttle.write_iops_device'])
    ) {
        return ['ok' => false, 'reason' => 'invalid-target', 'cur' => null];
    }
    // Setting checks writability before reading; clearing checks it only when a write is needed.
    if (!is_file($cgPath) || ($iops !== null && !is_writable($cgPath))) {
        return ['ok' => $iops === null, 'reason' => $iops === null ? 'no-slice' : 'unwritable', 'cur' => null];
    }
    $cur = trim((string) @file_get_contents($cgPath));
    $desired = $majMin.' '.($iops ?? 0);
    if ($iops === null && pmssCgroupDirectDeviceValueParse($cur, $majMin) === null) {
        return ['ok' => true, 'reason' => 'already-clear', 'cur' => $cur];
    }
    if ($iops !== null) {
        // Setting matches any exact device line; clearing retains first-record parsing.
        foreach (pmssTextLines($cur) as $line) {
            if (trim($line) === $desired) return ['ok' => true, 'reason' => 'already-set', 'cur' => $cur];
        }
    }
    if ($dryRun) {
        return ['ok' => true, 'reason' => 'dry-run', 'cur' => $cur];
    }
    if (($iops === null && !is_writable($cgPath)) || @file_put_contents($cgPath, $desired) === false) {
        return ['ok' => false, 'reason' => 'write-failed', 'cur' => $cur];
    }
    return ['ok' => true, 'reason' => 'written', 'cur' => $cur];
}
