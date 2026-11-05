<?php
/** Shared direct cgroup-v1 blkio guardrails.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/log.php';
require_once dirname(__DIR__).'/user/identity.php';

const PMSS_CGROUP_DIRECT_BLKIO_ROOT = '/sys/fs/cgroup/blkio';

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
    foreach (pmssCgroupDirectUserConfigs($usersDir, $errors) as $entry) {
        list($user, $json) = $entry;
        if (($plan = $planner($user, $json)) === null) continue;
        $total++;
        if (($uid = $uidResolver($user, $errors)) === null) continue;
        yield [$user, $uid, $plan];
    }
}

/** Resolve a managed user's positive UID, logging legacy-compatible failures. */
function pmssCgroupDirectUserUidOrError(string $user, int &$errors): ?int
{
    if (($pwd = posix_getpwnam($user)) === false) {
        $errors++;
        syslog(LOG_WARNING, "no passwd entry $user");
        return null;
    }

    if (($uid = pmssPasswdEntryPositiveUid($pwd)) === null) {
        $errors++;
        syslog(LOG_WARNING, "unsafe passwd uid $user");
        return null;
    }

    return $uid;
}

/** Build the cgroup-v1 user.slice directory for one positive UID. */
function pmssCgroupDirectUserSliceDir(int $uid): string { return PMSS_CGROUP_DIRECT_BLKIO_ROOT.'/user.slice/user-'.$uid.'.slice'; }

/** Build one direct blkio target path below a managed user's cgroup-v1 slice. */
function pmssCgroupDirectUserBlkioFilePath(int $uid, string $fileName): string { return pmssCgroupDirectUserSliceDir($uid).'/'.$fileName; }

/** Allow only explicitly pinned files below positive-UID per-user blkio slices. */
function pmssCgroupDirectUserBlkioPathAllowed(string $cgPath, array $allowedFiles): bool
{
    $patterns = [];
    foreach ($allowedFiles as $fileName) {
        if (is_string($fileName) && $fileName !== '' && strpos($fileName, '/') === false && strpos($fileName, "\0") === false) $patterns[] = preg_quote($fileName, '#');
    }

    return $patterns !== []
        && preg_match('#^'.preg_quote(PMSS_CGROUP_DIRECT_BLKIO_ROOT, '#').'/user\.slice/user-[1-9][0-9]*\.slice/(?:'.implode('|', $patterns).')$#', $cgPath) === 1;
}

/** Refuse links, directories, and missing targets before direct cgroup writes. */
function pmssCgroupDirectWritableFileTarget(string $path): bool
{
    return $path !== ''
        && strpos($path, "\0") === false
        && is_file($path)
        && !is_link($path)
        && is_writable($path);
}

/** Write one bounded integer target and reject failed or short sysfs writes. */
function pmssCgroupDirectIntegerFileWrite(string $path, int $value, int $minimum, int $maximum): bool
{
    return $minimum <= $maximum && $value >= $minimum && $value <= $maximum
        && pmssCgroupDirectWritableFileTarget($path)
        && pmssFileWriteComplete($path, (string) $value);
}

/** Read only this device's old integer from the writer's already captured state. */
function pmssCgroupDirectDeviceValueParse(string $raw, string $device): ?int
{
    if (strpos($raw, "\0") !== false) return null;
    foreach (pmssConfigTextRows($raw, 2, []) as $columns) {
        if ($columns[0] === $device) return count($columns) === 2 ? pmssUnsignedDecimalIntParse($columns[1]) : null;
    }
    return null;
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
