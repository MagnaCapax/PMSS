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

$logDir = '/var/log/pmss/metrics';
if (!pmssEnsureSafeDir($logDir, 0755)) {
    fwrite(STDERR, "Failed to prepare metrics log directory.\n");
    exit(1);
}

$userUids = pmssResourceLogManagedUserUids();
if ($userUids === []) {
    exit(0);
}

$mode = pmssCgroupMode();
$ts = date('Y-m-d\TH:i:s');

foreach ($userUids as $user => $uid) {
    $path = pmssResourceLogFilePath($logDir, $user);
    if ($path === null) {
        continue;
    }

    $metrics = pmssUserMetricsCollect($uid, $mode, null);
    if ($metrics === []) {
        continue;
    }

    if (!pmssJsonLineAppend($path, ['ts' => $ts, 'uid' => $uid] + $metrics)) {
        fwrite(STDERR, "Failed to append metrics for {$user}.\n");
    }
}
