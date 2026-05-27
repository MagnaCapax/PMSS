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

function pmssTrackerCleanerWriteUserVerboseLog(string $username, string $payload): void
{
    if ($payload === '') return;

    $userHome = "/home/{$username}";
    $userLogsDir = $userHome.'/.logs';
    $userLogFile = $userLogsDir.'/trackerCleaner.log';

    $tmpLogPath = @tempnam(sys_get_temp_dir(), 'pmss-trackerCleaner-');
    if ($tmpLogPath === false) return;
    if (@file_put_contents($tmpLogPath, $payload) === false) {
        @unlink($tmpLogPath);
        return;
    }
    // Allow the target user to read the temp file so we can append it safely as that user.
    $chownOk = @chown($tmpLogPath, $username);
    if (!$chownOk) {
        pmssTrackerCleanerLog("WARN: Unable to chown temp log {$tmpLogPath} for user {$username}; skipping per-user verbose log.");
        @unlink($tmpLogPath);
        return;
    }
    @chgrp($tmpLogPath, $username);
    @chmod($tmpLogPath, 0640);

    pmssUserLifecycleStep('trackerCleaner', $username, 'ensure_user_logs_dir', 'su -s /bin/bash -c '.escapeshellarg('mkdir -p ~/.logs').' '.escapeshellarg($username), false);

    if (!is_dir($userLogsDir) || !pmssPathWithinRootIsSafe($userLogsDir, $userHome, true)) {
        pmssTrackerCleanerLog("WARN: User log directory is unsafe or missing for {$username} ({$userLogsDir}); skipping per-user verbose log.");
        @unlink($tmpLogPath);
        return;
    }
    if (file_exists($userLogFile) && is_link($userLogFile)) {
        pmssTrackerCleanerLog("WARN: User log file is symlink for {$username} ({$userLogFile}); skipping per-user verbose log.");
        @unlink($tmpLogPath);
        return;
    }

    pmssUserLifecycleStep('trackerCleaner', $username, 'append_user_verbose_log', 'su -s /bin/bash -c '.escapeshellarg('cat '.escapeshellarg($tmpLogPath).' >> ~/.logs/trackerCleaner.log').' '.escapeshellarg($username), false);

    if (file_exists($userLogFile) && !is_link($userLogFile) && pmssPathWithinRootIsSafe($userLogFile, $userHome)) {
        @chown($userLogFile, $username);
        @chgrp($userLogFile, $username);
    }

    @unlink($tmpLogPath);
}

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
    pmssLockHandleWritePid($lockHandle);
}

$filterList = pmssTrackerCleanerFilterList();
$filterDomainList = pmssTrackerCleanerFilterDomainList();

// Get & parse users list
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

// Limit to 1 user per pass - thus we limit IOPS used as well!
if (count($users) > 1) $users = array_slice($users, 0, 1);

$runDeadline = time() + (30 * 60);
$maxTorrentsPerUser = 500;
$maxModifiedTorrents = 500;
$modifiedCount = 0;
$stopReason = '';
$anyWork = false;
$anyChanges = false;

foreach($users AS $thisUser) {    // Loop users checking their instances

    $userVerboseLog = '';
    $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_start user={$thisUser}\n";

        // if user is suspended, skip it
    if (time() >= $runDeadline) {
        $stopReason = 'runtime_limit';
        break;
    }
    if ($modifiedCount >= $maxModifiedTorrents) {
        $stopReason = 'modify_limit';
        break;
    }

    // Tracker cleaner is a data cleanup script, not a service watchdog — skip suspended
    // users without killing any processes (see GH#210).
    if (pmssUserWebRootUnavailable($thisUser)) {
            pmssTrackerCleanerLog("User {$thisUser} is suspended; skipping.");
	            $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason=suspended\n";
	            pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
	            continue;  //Suspended
	    }
	    if (file_exists("/home/{$thisUser}/.trackerCleanerDisable")) {
	        $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason=disabled\n";
	        pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
	        continue;
	    } // Disabled the cleaner
	    $expectedSessionDir = "/home/{$thisUser}/session";
	    if (!pmssPathWithinRootIsSafe($expectedSessionDir, $expectedSessionDir, true)) {
	        pmssTrackerCleanerLog("SKIP: refusing to operate; session path unsafe for user {$thisUser} ({$expectedSessionDir}).");
	        $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason=session_path_unsafe session={$expectedSessionDir}\n";
	        pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
	        continue;
	    }
	    $expectedBackupsDir = $expectedSessionDir.'/backups';
	    if (file_exists($expectedBackupsDir) && (!is_dir($expectedBackupsDir) || is_link($expectedBackupsDir))) {
	        pmssTrackerCleanerLog("SKIP: refusing to operate; backups path unsafe for user {$thisUser} ({$expectedBackupsDir}).");
	        $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason=backups_path_unsafe backups={$expectedBackupsDir}\n";
	        pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
	        continue;
	    }

    $thisUserTorrents = glob($expectedSessionDir."/*.torrent");
    if (count($thisUserTorrents) == 0) {
	        $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason=no_session_torrents session={$expectedSessionDir}\n";
        pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
        continue; // nothing to do
    }
        // Randomize order and cap scans per run.
    shuffle($thisUserTorrents);
    if (count($thisUserTorrents) > $maxTorrentsPerUser) {
        $thisUserTorrents = array_slice($thisUserTorrents, 0, $maxTorrentsPerUser);
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

	      $scrub = pmssTrackerCleanerScrubTorrent($torrent, $filterList, $filterDomainList);
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
	          $removedList = pmssTrackerCleanerRemovedTrackersText($scrub['removed_trackers']);
          // Backup as the user so ownership/perms stay consistent.
          $sourcePerms = @fileperms($thisTorrent);
          $sourceMode = $sourcePerms === false ? 0640 : ($sourcePerms & 0777);
          $sourceModeText = sprintf('%o', $sourceMode);
          $sourceSize = @filesize($thisTorrent);
          $sourceSizeText = $sourceSize === false ? 'unknown' : (string) $sourceSize;
          if (!is_dir($thisUserTorrentBackupDirectory)) {
              $prepareCmd = 'su -s /bin/bash -c '.escapeshellarg(
                  'mkdir -p '.escapeshellarg($thisUserTorrentBackupDirectory)
                  .' && chmod 750 '.escapeshellarg($thisUserTorrentBackupDirectory)
              ).' '.escapeshellarg($thisUser);
              $prepareRc = pmssUserLifecycleStep('trackerCleaner', $thisUser, 'prepare_backup_dir', $prepareCmd, false);
              if ($prepareRc !== 0) {
                  pmssTrackerCleanerLog("ERR: Failed to prepare backup dir for user {$thisUser} (dir={$thisUserTorrentBackupDirectory}, rc={$prepareRc}).");
                  $userVerboseLog .= pmssTrackerCleanerTimestamp()
                      ." torrent_skip reason=backup_dir_prepare_failed rc={$prepareRc} backup_dir={$thisUserTorrentBackupDirectory}\n";
                  $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_stop reason=backup_failed\n";
                  $stopReason = 'backup_failed';
                  break;
              }
          }
          if (!pmssPathWithinRootIsSafe($thisUserTorrentBackupDirectory, $expectedBackupsDir, true)) {
              pmssTrackerCleanerLog("ERR: Backup path unsafe for user {$thisUser} ({$thisUserTorrentBackupDirectory}).");
              $userVerboseLog .= pmssTrackerCleanerTimestamp()
                  ." torrent_skip reason=backup_path_unsafe backup_dir={$thisUserTorrentBackupDirectory}\n";
              $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_stop reason=backup_failed\n";
              $stopReason = 'backup_failed';
              break;
          }
          $backupTarget = $thisUserTorrentBackupDirectory.'/'.basename($thisTorrent);
          $backupCmd = 'su -s /bin/bash -c '.escapeshellarg(
              'cp -p '.escapeshellarg($thisTorrent).' '.escapeshellarg($backupTarget)
              .' && chmod '.$sourceModeText.' '.escapeshellarg($backupTarget)
          ).' '.escapeshellarg($thisUser);
          $backupRc = pmssUserLifecycleStep('trackerCleaner', $thisUser, 'backup_torrent', $backupCmd, false);
          $backupSize = @filesize($backupTarget);
          $backupSizeText = $backupSize === false ? 'unknown' : (string) $backupSize;
          $backupOk = $backupRc === 0 && $sourceSize !== false && $backupSize !== false && $backupSize === $sourceSize
              && is_file($backupTarget) && !is_link($backupTarget) && pmssPathWithinRootIsSafe($backupTarget, $expectedBackupsDir);
          $userVerboseLog .= pmssTrackerCleanerTimestamp()
              ." torrent_backup rc={$backupRc} src={$thisTorrent} dst={$backupTarget}\n";
          if (!$backupOk) {
              pmssTrackerCleanerLog(
                  "ERR: Backup verification failed for user {$thisUser} (file=".basename($thisTorrent)
                  .", rc={$backupRc}, src_bytes={$sourceSizeText}, dst_bytes={$backupSizeText})."
              );
              $userVerboseLog .= pmssTrackerCleanerTimestamp()
                  ." torrent_skip reason=backup_failed rc={$backupRc} src={$thisTorrent} dst={$backupTarget}"
                  ." src_bytes={$sourceSizeText} dst_bytes={$backupSizeText} removed_trackers=".$removedList
                  ."\n";
              $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_stop reason=backup_failed\n";
              $stopReason = 'backup_failed';
              break;
          }
          $comment = (string) $torrent->getComment();
          $markedComment = pmssTrackerCleanerCommentWithMarker($comment);
          if ($markedComment !== $comment) $torrent->setComment($markedComment);
          $written = @file_put_contents($thisTorrent, $torrent->serialize());
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

    if (count($thisUserTorrentChanges) != 0) {
	      $log = '';
	      foreach($thisUserTorrentChanges AS $thisTorrentInfoHash => $thisTorrentName) $log .= pmssTrackerCleanerTimestamp()." Changed {$thisTorrentName} ({$thisTorrentInfoHash})\n";

      $userLogPath = "/home/{$thisUser}/.trackerCleaner.log";
      if (file_exists($userLogPath) && is_link($userLogPath)) {
          pmssTrackerCleanerLog("SKIP: refusing to write log; path is symlink for user {$thisUser} ({$userLogPath}).");
      } else {
          file_put_contents($userLogPath, $log, FILE_APPEND);
          if (file_exists($userLogPath) && !is_link($userLogPath) && pmssPathWithinRootIsSafe($userLogPath, "/home/{$thisUser}")) {
              @chown($userLogPath, $thisUser);
              @chgrp($userLogPath, $thisUser);
          }
      }
      echo $log;

   }

   $runSuffix = $stopReason !== '' ? " reason={$stopReason}" : '';
   $userVerboseLog .= pmssTrackerCleanerTimestamp()
       ." run_end user={$thisUser} processed={$userProcessedTorrents} private={$userPrivateTorrents} changed={$userChangedTorrents}{$runSuffix}\n";
   pmssTrackerCleanerWriteUserVerboseLog($thisUser, $userVerboseLog);
   $summary = sprintf('tracker cleaner: processed=%d private=%d changed=%d%s', $userProcessedTorrents, $userPrivateTorrents, $userChangedTorrents, $stopReason !== '' ? ' stop_reason='.$stopReason : '');
   pmssUserLog($thisUser, $summary);

   if ($stopReason !== '') {
       break;
   }

}

if ($stopReason === 'runtime_limit') {
    pmssTrackerCleanerLog('WARN: runtime limit reached; stopping early.');
} elseif ($stopReason === 'backup_failed') {
    pmssTrackerCleanerLog('ERR: backup verification failed; stopping early.');
} elseif ($stopReason === 'modify_limit') {
    pmssTrackerCleanerLog('WARN: modification limit reached; stopping early.');
} elseif (!$anyWork) {
    pmssTrackerCleanerLog('SKIP: no eligible torrents processed this run.');
} elseif (!$anyChanges) {
    pmssTrackerCleanerLog('OK: run complete; no tracker changes needed.');
} else {
    pmssTrackerCleanerLog('OK: run complete; tracker changes applied.');
}
