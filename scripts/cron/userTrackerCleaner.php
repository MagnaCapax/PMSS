#!/usr/bin/env php
<?php
/** Remove known bad public trackers from a bounded rTorrent session sample. */

include '/scripts/lib/devristo/Torrent.php';
include '/scripts/lib/devristo/Bee.php';
include '/scripts/lib/devristo/File.php';
require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';
require_once __DIR__.'/../lib/trackerCleaner.php';
use Devristo\Torrent\Torrent;

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

foreach($users AS $thisUser) {
    $userVerboseLog = pmssTrackerCleanerTimestamp()." run_start user={$thisUser}\n";

    if (time() >= $runDeadline) {
        $stopReason = 'runtime_limit';
        break;
    }
    if ($modifiedCount >= $maxModifiedTorrents) {
        $stopReason = 'modify_limit';
        break;
    }

    $sessionPlan = pmssTrackerCleanerUserSessionPlan($thisUser);
    $expectedSessionDir = $sessionPlan['session_dir'];
    $expectedBackupsDir = $sessionPlan['backups_dir'];
    if ($sessionPlan['skip']) {
        if ($sessionPlan['message'] !== '') {
            pmssTrackerCleanerLog($sessionPlan['message']);
        }
        $suffix = in_array($sessionPlan['reason'], ['session_path_unsafe', 'no_session_torrents'], true)
            ? ' session='.$expectedSessionDir
            : ($sessionPlan['reason'] === 'backups_path_unsafe' ? ' backups='.$expectedBackupsDir : '');
        $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason={$sessionPlan['reason']}{$suffix}\n";
        pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
        continue;
    }

    $thisUserTorrents = pmssTrackerCleanerTorrentCandidates($expectedSessionDir, $maxTorrentsPerUser);
    if ($thisUserTorrents === []) {
        $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason=no_session_torrents session={$expectedSessionDir}\n";
        pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
        continue; // nothing to do
    }

	    $thisUserTorrentChanges = array();
	    $thisUserTorrentBackupDirectory = $expectedBackupsDir.'/' . date('Y-m-d_Hi');

	    pmssTrackerCleanerLog("User {$thisUser}, ".count($thisUserTorrents).' torrents to be checked.');
	    $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_selected candidates=".count($thisUserTorrents)." backups_dir={$thisUserTorrentBackupDirectory}\n";

	    $userProcessedTorrents = 0;
	    $userPrivateTorrents = 0;
		    $userChangedTorrents = 0;

    foreach($thisUserTorrents AS $thisTorrent) {
      $didModify = false;
      if (time() >= $runDeadline) {
          $stopReason = 'runtime_limit';
          $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_stop reason=runtime_limit\n";
          break;
      }
      if ($modifiedCount >= $maxModifiedTorrents) {
          $stopReason = 'modify_limit';
          $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_stop reason=modify_limit\n";
          break;
      }
      $thisTorrent = trim($thisTorrent);
      if ($thisTorrent === '' || !is_file($thisTorrent) || is_link($thisTorrent)) {
          continue;
      }
      if (!pmssPathWithinRootIsSafe($thisTorrent, $expectedSessionDir)) {
          continue;
      }
      try {
          $torrent = Torrent::fromFile($thisTorrent);
      } catch (\Throwable $e) {
          $message = $e->getMessage();
          pmssTrackerCleanerLog("WARN: Failed to parse torrent for user {$thisUser} (file=".basename($thisTorrent)."): {$message}");
          $userVerboseLog .= pmssTrackerCleanerTimestamp()
              ." torrent_skip reason=parse_error file=".basename($thisTorrent)
              ." message=".str_replace("\n", ' ', $message)
              ."\n";
          continue;
      }
      $userProcessedTorrents++;
      $anyWork = true;

	      if ($torrent->isPrivate()) {
	          $userPrivateTorrents++;
	          $userVerboseLog .= pmssTrackerCleanerTimestamp()
	              ." torrent_skip reason=private_flag_present file=".basename($thisTorrent)
	          ." infohash=".$torrent->getInfoHash(false)
	          ." name=".pmssTrackerCleanerLogValue($torrent->getName())
	              ."\n";
	          continue;	// Do not touch private torrents
	      }

      $userVerboseLog .= pmssTrackerCleanerTimestamp()
          ." torrent_check public=1 file=".basename($thisTorrent)
          ." infohash=".$torrent->getInfoHash(false)
	          ." name=".pmssTrackerCleanerLogValue($torrent->getName())
          ."\n";

	      $scrub = pmssTrackerCleanerScrubTorrent($torrent, $blockRules);
	      foreach ($scrub['events'] as $event) {
	          $userVerboseLog .= pmssTrackerCleanerTimestamp().' '.$event."\n";
	      }

	      if ($scrub['would_trackerless']) {
	          // Avoid leaving a torrent trackerless; even flaky trackers can still be better than none.
	          $warning = 'Would leave completely trackerless, no tracker cleaning done';
	          pmssTrackerCleanerLog("WARN: {$warning} (user={$thisUser} file=".basename($thisTorrent).")");
	          $userVerboseLog .= pmssTrackerCleanerTimestamp()
              ." torrent_skip reason=trackerless warning=".$warning
              ."\n";
	          continue;
	      }

	      if ($scrub['changed']) {
	          $removedList = $scrub['removed_trackers'] === [] ? '(unknown)' : implode(', ', $scrub['removed_trackers']);
          $backup = pmssTrackerCleanerBackupTorrent($thisUser, $thisTorrent, $thisUserTorrentBackupDirectory, $expectedBackupsDir, $removedList);
          $userVerboseLog .= $backup['verbose_log'];
          if (!$backup['ok']) {
              $stopReason = $backup['stop_reason'];
              break;
          }
          $comment = (string) $torrent->getComment();
          $markedComment = pmssTrackerCleanerCommentWithMarker($comment);
          if ($markedComment !== $comment) $torrent->setComment($markedComment);
          $written = pmssTrackerCleanerWriteCleanedTorrent($thisTorrent, $torrent->serialize(), $expectedSessionDir);
          $writtenBytes = $written === false ? -1 : (int) $written;
          $userVerboseLog .= pmssTrackerCleanerTimestamp()
              ." torrent_write bytes={$writtenBytes} file={$thisTorrent}\n";
          if ($written === false) {
              pmssTrackerCleanerLog("WARN: Failed to write cleaned torrent for user {$thisUser} (file=".basename($thisTorrent).").");
              $userVerboseLog .= pmssTrackerCleanerTimestamp()
                  ." torrent_skip reason=write_failed removed_trackers=".$removedList
                  ."\n";
              continue;
          }
          $thisUserTorrentChanges[$torrent->getInfoHash(false)] = $torrent->getName();
          $userChangedTorrents++;
          $anyChanges = true;
          $modifiedCount++;
          $didModify = true;
          $userVerboseLog .= pmssTrackerCleanerTimestamp()
              ." torrent_change removed_trackers=".$removedList
              ."\n";
      } else {
          $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_ok no_changes=1\n";
      }
      if ($didModify) {
          // Throttle only after successful public-torrent modifications.
          usleep(25000);
      }

    }

    echo pmssTrackerCleanerAppendUserChangeLog($thisUser, $thisUserTorrentChanges);

   $runSuffix = $stopReason !== '' ? " reason={$stopReason}" : '';
   $userVerboseLog .= pmssTrackerCleanerTimestamp()
       ." run_end user={$thisUser} processed={$userProcessedTorrents} private={$userPrivateTorrents} changed={$userChangedTorrents}{$runSuffix}\n";
   pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
   $summary = pmssTrackerCleanerUserSummary($userProcessedTorrents, $userPrivateTorrents, $userChangedTorrents, $stopReason);
   pmssUserLog($thisUser, $summary);

   if ($stopReason !== '') {
       break;
   }

}

pmssTrackerCleanerLog(pmssTrackerCleanerRunOutcomeLogLine($stopReason, $anyWork, $anyChanges));
