#!/usr/bin/env php
<?php
/**
 * Cron task: 24 h live-stats rings for the customer panel (node total vs own share).
 *
 * Runs one minute after the five-minute collectors (resourceLog, trafficLog,
 * trafficIngressLog) and only reads what they already wrote, plus three /proc
 * files for the node totals. Privacy: the node file holds sums only; each user
 * file is root-owned, group-readable by that user alone. Customers never see
 * other users' samples. Design record: docs/adr/0122-live-stats-node-vs-own-share.md
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */
require_once '/scripts/lib/liveStats.php';
require_once '/scripts/lib/networkInfo.php';
require_once '/scripts/lib/lighttpd/userFileWrite.php';
require_once '/scripts/lib/resources/log.php';
require_once '/scripts/lib/runtime/directories.php';
require_once '/scripts/lib/runtime/locks.php';

$lock = pmssCronLockAcquire('liveStatsLog');
$publicDir = '/var/lib/pmss/public';
$userDir = '/var/lib/pmss/live';
$stateFile = '/var/run/pmss/liveStats/node-counters.json';
$now = time();

if (!pmssEnsureSafeDir(dirname($stateFile), 0700) || !pmssEnsureSafeDir($userDir, 0711)
    || !pmssCheckDirectoriesEnsurePublicDirectory($publicDir, static function (string $m): void { fwrite(STDERR, $m.PHP_EOL); })) {
    exit(1);
}

$cur = pmssLiveStatsNodeCountersParse(
    (string) @file_get_contents('/proc/stat'), (string) @file_get_contents('/proc/meminfo'),
    (string) @file_get_contents('/proc/net/dev'), getenv('PMSS_LIVESTATS_IFACE') ?: detectPrimaryInterface()
);
if ($cur === null) exit(pmssCliReturnWithStderr("liveStats: cannot read node counters\n"));
$cur['time'] = $now;

$prev = pmssJsonFileReadAssoc($stateFile, true);
pmssAtomicJsonFileWrite($stateFile, $cur, 0600);
$nodeSample = $prev === null ? null : pmssLiveStatsNodeSample($prev, $cur, $now);
if ($nodeSample === null) exit(0); // first run, reboot or gap: wait for the next clean interval

$nodePath = $publicDir.'/node-live.json';
if (!pmssAtomicWriteFile($nodePath, pmssLiveStatsRingEncode(pmssLiveStatsRingRead($nodePath), $nodeSample), 0644)) {
    fwrite(STDERR, "liveStats: node write failed\n");
}

$managed = pmssResourceLogManagedUserUids();
foreach ($managed as $user => $uid) {
    $path = $userDir.'/'.$user.'.json';
    $ring = pmssLiveStatsRingRead($path);
    $last = $ring === [] ? 0 : (int) end($ring)['t'];
    $json = pmssLiveStatsRingEncode($ring, pmssLiveStatsUserSample($user, $now, '/var/log/pmss', $last));
    if (!pmssWriteManagedFile($path, $json, 'root', $user, 0640)) fwrite(STDERR, "liveStats: write failed for {$user}\n");
}

// A deleted account's history must not reach a later account that reuses the name.
foreach (glob($userDir.'/*.json') ?: [] as $file) {
    if (!isset($managed[basename($file, '.json')])) @unlink($file);
}
