#!/usr/bin/env php
<?php
/** Remove known bad public trackers from a bounded rTorrent session sample. */

include '/scripts/lib/devristo/Torrent.php';
include '/scripts/lib/devristo/Bee.php';
include '/scripts/lib/devristo/File.php';
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/trackerCleaner.php';

$lockPath = '/run/lock/pmss-userTrackerCleaner.lock';
$lockBusy = false;
$lockHandle = pmssLockFileAcquire($lockPath, true, 'c', false, false, $lockBusy);
if ($lockHandle === false) {
    pmssTrackerCleanerLog("WARN: Unable to open lock file {$lockPath}; continuing without single-instance guard.");
} else {
    if ($lockBusy) {
        @rewind($lockHandle);
        $existingPid = trim((string) stream_get_contents($lockHandle));
        if ($existingPid === '') {
            $existingPid = 'unknown';
        }
        pmssTrackerCleanerLog("SKIP: already running (lock held, pid={$existingPid}).");
        @fclose($lockHandle);
        exit(0);
    }
    if (!pmssLockHandleWritePid($lockHandle)) {
        pmssTrackerCleanerLog("WARN: Unable to record lock pid in {$lockPath}; continuing without single-instance guard.");
        @fclose($lockHandle);
        $lockHandle = false;
    }
}

$blockRules = pmssTrackerCleanerBlockRules();

$listUsersResult = pmssListManagedUsersResult('/scripts/listUsers.php');
if ((int) $listUsersResult['exitCode'] !== 0) {
    pmssTrackerCleanerLog('ERR: /scripts/listUsers.php failed (rc='.(int) $listUsersResult['exitCode'].'); skipping run.');
    exit(0);
}
$users = $listUsersResult['users'];
if (count($users) === 0) {
    pmssTrackerCleanerLog('SKIP: no users returned by /scripts/listUsers.php.');
    exit(0);
}
shuffle($users);
$users = array_slice($users, 0, 1);

$runDeadline = time() + (30 * 60);
$maxTorrentsPerUser = 500;
$maxModifiedTorrents = 500;
$modifiedCount = 0;
$stopReason = '';
$anyWork = false;
$anyChanges = false;

foreach ($users as $thisUser) {
    $stopReason = pmssTrackerCleanerStopReason($runDeadline, $modifiedCount, $maxModifiedTorrents);
    if ($stopReason !== '') {
        break;
    }

    $summary = '';
    $stopReason = pmssTrackerCleanerRunUser(
        $thisUser,
        $blockRules,
        $runDeadline,
        $maxTorrentsPerUser,
        $maxModifiedTorrents,
        $modifiedCount,
        $anyWork,
        $anyChanges,
        $summary
    );
    if ($summary !== '') pmssUserLog($thisUser, $summary);
    if ($stopReason !== '') {
        break;
    }
}

pmssTrackerCleanerLog(pmssTrackerCleanerRunOutcomeLogLine($stopReason, $anyWork, $anyChanges));
