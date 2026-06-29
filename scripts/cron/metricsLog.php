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

// Root-only: per-user performance metrics are customer data (MISSION #1 privacy).
// 0700 dir + 0600 files keep them unreadable cross-tenant — no customer can read
// another customer's resource usage. The customer-facing UI reads its own
// /home/<user>/.resourceData, never this operator-side log.
$logDir = '/var/log/pmss/metrics';
if (!pmssEnsureSafeDir($logDir, 0700)) {
    fwrite(STDERR, "Failed to prepare metrics log directory.\n");
    exit(1);
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
}
