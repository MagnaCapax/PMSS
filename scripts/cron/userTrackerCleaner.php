#!/usr/bin/php
<?php
/**
 * Tracker cleanup script — remove known bad/dead trackers from rTorrent sessions.
 *
 * Scans a limited set of torrents per user on each run to avoid excessive I/O
 * and strips problematic trackers from announce lists. Intended to run at a
 * low cadence (e.g., hourly) due to its IOPS footprint.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */
// We should not run this too often, can generate TONS of I/O operations; for example maths:
// 30 users, with each 250+ torrents ALL PRIVATE (best case) is still going to be roughly 30*260 == 7800 IO requests
// 7800 IO in 60 seconds == 130IOPS. We should target more like 0.1, so 7800 / 0.1 = 78 000 seconds per pass or 1300minutes...
// Hence a compromise has to be made, and run this once every two hours or so.

include '/scripts/lib/devristo/Torrent.php';
include '/scripts/lib/devristo/Bee.php';
include '/scripts/lib/devristo/File.php';
require_once __DIR__.'/../lib/userLifecycle.php';
use Devristo\Torrent\Torrent;

function pmssTrackerCleanerTimestamp(): string
{
    return '['.date('Y-m-d H:i:s').']';
}

function pmssTrackerCleanerLog(string $message): void
{
    echo pmssTrackerCleanerTimestamp().' '.$message."\n";
}

function pmssTrackerCleanerShouldScrubTracker(string $trackerUrl, array $filterList, array $filterDomainList): bool
{
    foreach ($filterList as $filter) {
        if ($filter !== '' && strpos($trackerUrl, $filter) !== false) {
            return true;
        }
    }
    foreach ($filterDomainList as $domain) {
        if ($domain !== '' && stripos($trackerUrl, $domain) !== false) {
            return true;
        }
    }
    return false;
}

function pmssTrackerCleanerWriteUserVerboseLog(string $username, string $payload): void
{
    if ($payload === '') {
        return;
    }

    $userHome = "/home/{$username}";
    $userLogsDir = $userHome.'/.logs';
    $userLogFile = $userLogsDir.'/trackerCleaner.log';

    $tmpLogPath = @tempnam(sys_get_temp_dir(), 'pmss-trackerCleaner-');
    if ($tmpLogPath === false) {
        return;
    }
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

    $ensureDirCmd = 'su -s /bin/bash -c '.escapeshellarg('mkdir -p ~/.logs').' '.escapeshellarg($username);
    pmssUserLifecycleStep('trackerCleaner', $username, 'ensure_user_logs_dir', $ensureDirCmd, false);

    if (!is_dir($userLogsDir) || !pmssTrackerCleanerPathIsSafe($userLogsDir, $userHome.'/')) {
        pmssTrackerCleanerLog("WARN: User log directory is unsafe or missing for {$username} ({$userLogsDir}); skipping per-user verbose log.");
        @unlink($tmpLogPath);
        return;
    }
    if (file_exists($userLogFile) && is_link($userLogFile)) {
        pmssTrackerCleanerLog("WARN: User log file is symlink for {$username} ({$userLogFile}); skipping per-user verbose log.");
        @unlink($tmpLogPath);
        return;
    }

    $appendCmd = 'su -s /bin/bash -c '
        .escapeshellarg('cat '.escapeshellarg($tmpLogPath).' >> ~/.logs/trackerCleaner.log')
        .' '.escapeshellarg($username);
    pmssUserLifecycleStep('trackerCleaner', $username, 'append_user_verbose_log', $appendCmd, false);

    if (file_exists($userLogFile) && !is_link($userLogFile) && pmssTrackerCleanerPathIsSafe($userLogFile, $userHome.'/')) {
        @chown($userLogFile, $username);
        @chgrp($userLogFile, $username);
    }

    @unlink($tmpLogPath);
}

function pmssTrackerCleanerPathIsSafe(string $path, string $expectedPrefix): bool
{
    if ($path === '' || $expectedPrefix === '') {
        return false;
    }
    if (is_link($path)) {
        return false;
    }
    $real = realpath($path);
    if ($real === false) {
        return false;
    }
    return strpos($real, $expectedPrefix) === 0;
}

$lockPath = '/run/lock/pmss-userTrackerCleaner.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false) {
    pmssTrackerCleanerLog("WARN: Unable to open lock file {$lockPath}; continuing without single-instance guard.");
} else {
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        @rewind($lockHandle);
        $existingPid = trim((string) stream_get_contents($lockHandle));
        if ($existingPid === '') {
            $existingPid = 'unknown';
        }
        pmssTrackerCleanerLog("SKIP: already running (lock held, pid={$existingPid}).");
        exit(0);
    }
    @ftruncate($lockHandle, 0);
    @fwrite($lockHandle, (string) getmypid());
    @fflush($lockHandle);
}



$filterList = array(
//    'udp://',
    'udp://public.popcorn-tracker.org:6969/announce', // Watch reliability; flapping trackers can cause stalls.
    'http://sub4all.org',
    'udp://tracker.openbittorrent.com:80/announce',
    'udp://tracker.publicbt.com',
    'udp://tracker.ccc.de',
    'http://tracker.tntvillage.scambioetico.org',
    'http://exodus.desync.com',
    'http://tracker.ftfansub.net',
    'http://nyaa.tracker.wf',
    'udp://tracker.istole.it',
    'udp://mgtracker.org'
);

// Re-enabled (functional again) 2025-12-21:
// - udp://tracker.opentrackr.org
// - udp://open.demonii.com
$filterDomainList = array(
    // Any tracker URL containing this domain (exact URL varies).
    'legittorrents.info',
    'tracker.openbittorrent.com',
    'tracker.leechers-paradise.org',
    'tracker.coppersurfer.tk',
    '9.rarbg.',
    '10.rarbg.',
    'tracker.eddie4.nl',
    'tracker.supertracker.net',
    'concen.org',
    'tracker.tfile.me',
    'tracker.cyberia.is',
);

// Get & parse users list
$usersLines = array();
$usersRc = 0;
@exec('/scripts/listUsers.php 2>&1', $usersLines, $usersRc);
if ($usersRc !== 0) {
    pmssTrackerCleanerLog("ERR: /scripts/listUsers.php failed (rc={$usersRc}); skipping run.");
    exit(0);
}
$users = array();
foreach ($usersLines as $line) {
    $line = trim($line);
    if ($line !== '') {
        $users[] = $line;
    }
}
if (count($users) === 0) {
    pmssTrackerCleanerLog('SKIP: no users returned by /scripts/listUsers.php.');
    exit(0);
}
shuffle($users);

// Limit to 1 user per pass - thus we limit IOPS used as well!
if (count($users) > 1) $users = array_slice($users, 0, 1);

$runStarted = time();
$runDeadline = $runStarted + (30 * 60);
$maxTorrentsPerUser = 500;
$maxModifiedTorrents = 500;
$modifiedCount = 0;
$stopReason = '';
$anyWork = false;
$anyChanges = false;

foreach($users AS $thisUser) {    // Loop users checking their instances
    #TODO(user-logs): log per-user cleaner actions (suspension kills, tracker changes) to /var/log/pmss/user-<username>.log
    $thisUser = trim($thisUser);
    if ($thisUser === '') {
        continue;
    }
    if (!pmssValidateUsername($thisUser)) {
        pmssUserWriteLogs(
            pmssUserBaseContext(
                'trackerCleaner',
                'validate',
                $thisUser,
                [
                    'status'  => 'ERR',
                    'message' => 'Skipping invalid username in userTrackerCleaner',
                ]
            )
        );
        continue;
    }
    
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

    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www") ) {
            pmssTrackerCleanerLog("User {$thisUser} is suspended; killing processes and skipping.");
	            pmssUserLifecycleStep(
	                'trackerCleaner',
	                $thisUser,
	                'kill_suspended_processes',
                'killall -9 -u '.escapeshellarg($thisUser),
                false
	            );  // Ensure nothing for the user is running
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
	    $expectedSessionPrefix = $expectedSessionDir.'/';
	    if (!pmssTrackerCleanerPathIsSafe($expectedSessionDir, $expectedSessionDir)) {
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
      if (!pmssTrackerCleanerPathIsSafe($thisTorrent, $expectedSessionPrefix)) {
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
	              ." name=".str_replace("\n", ' ', (string) $torrent->getName())
	              ."\n";
	          continue;	// Do not touch private torrents
	      }

      $userVerboseLog .= pmssTrackerCleanerTimestamp()
          ." torrent_check public=1 file=".basename($thisTorrent)
          ." infohash=".$torrent->getInfoHash(false)
          ." name=".str_replace("\n", ' ', (string) $torrent->getName())
          ."\n";

      $torrentAnnounceList = $torrent->getAnnounceList();
      $torrentAnnounceListNew = array();
      $changed = false;
      $removedTrackers = array();
      $remainingTrackers = array();
      foreach ($torrentAnnounceList as $tier) {
          if (!is_array($tier)) {
              continue;
          }
          $tierNew = array();
          foreach ($tier as $trackerUrl) {
              if (!is_string($trackerUrl)) {
                  continue;
              }
              if (pmssTrackerCleanerShouldScrubTracker($trackerUrl, $filterList, $filterDomainList)) {
                  $removedTrackers[$trackerUrl] = true;
                  $changed = true;
                  continue;
              }
              $tierNew[] = $trackerUrl;
              $remainingTrackers[$trackerUrl] = true;
          }
          if (count($tierNew) > 0) {
              $torrentAnnounceListNew[] = $tierNew;
          }
	      }
	      if ($changed) {
	          $torrent->setAnnounceList($torrentAnnounceListNew);
      }

      $announce = $torrent->getAnnounce();
      if (is_string($announce) && $announce !== '' && pmssTrackerCleanerShouldScrubTracker($announce, $filterList, $filterDomainList)) {
          $removedTrackers[$announce] = true;
	          $replacement = null;
	          foreach ($torrentAnnounceListNew as $tierNew) {
	              if (isset($tierNew[0]) && is_string($tierNew[0]) && $tierNew[0] !== '') {
	                  $replacement = $tierNew[0];
	                  break;
	              }
	          }
	          if ($replacement !== null) {
	              $torrent->setAnnounce($replacement);
	              $changed = true;
	              $userVerboseLog .= pmssTrackerCleanerTimestamp()
	                  ." announce_replaced from=".$announce
	                  ." to=".$replacement
	                  ."\n";
          } else {
              $userVerboseLog .= pmssTrackerCleanerTimestamp()
                  ." announce_scrubbed_no_replacement announce=".$announce
                  ."\n";
          }
      } elseif (is_string($announce) && $announce !== '') {
          $remainingTrackers[$announce] = true;
      }

      if ($changed && count($remainingTrackers) === 0) {
          // Avoid leaving a torrent trackerless; even flaky trackers can still be better than none.
          $warning = 'Would leave completely trackerless, no tracker cleaning done';
          pmssTrackerCleanerLog("WARN: {$warning} (user={$thisUser} file=".basename($thisTorrent).")");
          $userVerboseLog .= pmssTrackerCleanerTimestamp()
              ." torrent_skip reason=trackerless warning=".$warning
              ."\n";
          continue;
      }

      if ($changed) {
          $removedList = implode(', ', array_keys($removedTrackers));
          if ($removedList === '') {
              $removedList = '(unknown)';
          }
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
              $prepareRc = pmssUserLifecycleStep(
                  'trackerCleaner',
                  $thisUser,
                  'prepare_backup_dir',
                  $prepareCmd,
                  false
              );
              if ($prepareRc !== 0) {
                  pmssTrackerCleanerLog("ERR: Failed to prepare backup dir for user {$thisUser} (dir={$thisUserTorrentBackupDirectory}, rc={$prepareRc}).");
                  $userVerboseLog .= pmssTrackerCleanerTimestamp()
                      ." torrent_skip reason=backup_dir_prepare_failed rc={$prepareRc} backup_dir={$thisUserTorrentBackupDirectory}\n";
                  $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_stop reason=backup_failed\n";
                  $stopReason = 'backup_failed';
                  break;
              }
          }
          if (!pmssTrackerCleanerPathIsSafe($thisUserTorrentBackupDirectory, $expectedBackupsDir.'/')) {
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
          $backupRc = pmssUserLifecycleStep(
              'trackerCleaner',
              $thisUser,
              'backup_torrent',
              $backupCmd,
              false
          );
          $backupSize = @filesize($backupTarget);
          $backupSizeText = $backupSize === false ? 'unknown' : (string) $backupSize;
          $backupOk = $backupRc === 0
              && $sourceSize !== false
              && $backupSize !== false
              && $backupSize === $sourceSize
              && is_file($backupTarget)
              && !is_link($backupTarget)
              && pmssTrackerCleanerPathIsSafe($backupTarget, $expectedBackupsDir.'/');
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
          $marker = 'Trackers cleaned by PMSS tracker cleaner';
          if (strpos($comment, $marker) === false) {
              $suffix = '; '.$marker.' (https://github.com/MagnaCapax/PMSS/blob/main/docs/tracker-cleaner.md)';
              $torrent->setComment($comment.$suffix);
          }
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

    // Let's also log it all!
    $log = '';
    if (count($thisUserTorrentChanges) != 0) {
      $log = '';
      foreach($thisUserTorrentChanges AS $thisTorrentInfoHash => $thisTorrentName)
          $log .= pmssTrackerCleanerTimestamp()." Changed {$thisTorrentName} ({$thisTorrentInfoHash})\n";

      $userLogPath = "/home/{$thisUser}/.trackerCleaner.log";
      if (file_exists($userLogPath) && is_link($userLogPath)) {
          pmssTrackerCleanerLog("SKIP: refusing to write log; path is symlink for user {$thisUser} ({$userLogPath}).");
      } else {
          file_put_contents($userLogPath, $log, FILE_APPEND);
          if (file_exists($userLogPath) && !is_link($userLogPath) && pmssTrackerCleanerPathIsSafe($userLogPath, "/home/{$thisUser}/")) {
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
