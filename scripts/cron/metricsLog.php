#!/usr/bin/env php
<?php
/**
 * Cron task: comprehensive per-user performance metrics (JSONL time-series).
 *
 * Captures every available per-user cgroup/slice metric (pmssUserMetricsCollect)
 * additively, one JSON object per cycle per user, in /var/log/pmss/metrics/<user>.
 * This is SEPARATE from the billing-critical resource log (resourceLog.php) — that
 * file and its format are intentionally left untouched. Self-describing output means
 * new metrics need no format/parser change.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once '/scripts/lib/resources/log.php';
require_once '/scripts/lib/resources/metrics.php';
require_once '/scripts/lib/resources/oomStatus.php';

// Root-only: per-user performance metrics are customer data (MISSION #1 privacy).
// 0700 dir + 0600 files keep them unreadable cross-tenant — no customer can read
// another customer's resource usage. The customer-facing UI reads its own
// /home/<user>/.resourceData, never this operator-side log.
$logDir = '/var/log/pmss/metrics';
$oomStateDir = '/var/run/pmss/oomStatus';
if (!pmssEnsureSafeDir($logDir, 0700) || !pmssEnsureSafeDir($oomStateDir, 0700)) {
    exit(pmssCliReturnWithStderr("Failed to prepare metrics log directory.\n"));
}
@chmod($logDir, 0700);

$userUids = pmssResourceLogManagedUserUids();
if ($userUids === []) {
    exit(0);
}

$ts = date('Y-m-d\TH:i:s');

foreach ($userUids as $user => $uid) {
    $path = pmssResourceLogFilePath($logDir, $user);
    if ($path === null) {
        continue;
    }

    $metrics = pmssUserMetricsCollect($uid, null);
    if ($metrics === []) {
        continue;
    }

    $newFile = !is_file($path);
    if (!pmssJsonLineAppend($path, ['ts' => $ts, 'uid' => $uid] + $metrics)) {
        fwrite(STDERR, "Failed to append metrics for {$user}.\n");
        continue;
    }
    if ($newFile) {
        @chmod($path, 0600);
    }
    // This private projection is the only collector data exposed to the owning account.
    $parent = $metrics['mem_oom_kill'] ?? null;
    $child = pmssResourceLogReadMemoryStatField('/sys/fs/cgroup/memory/user.slice/user-'.$uid.'.slice/user@'.$uid.'.service/memory.oom_control', 'oom_kill');
    if (($parent !== null || $child !== null) && is_dir(userFilesystem::homePath($user))) {
        if (!pmssOomStatusProject($user, max($parent ?? 0, $child ?? 0), time())) {
            fwrite(STDERR, "Failed to project OOM status for {$user}.\n");
        }
    }
}
