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
// Hence a compromise has to be made, and run this once an hour or so.

include '/scripts/lib/devristo/Torrent.php';
include '/scripts/lib/devristo/Bee.php';
include '/scripts/lib/devristo/File.php';
require_once __DIR__.'/../lib/userLifecycle.php';
use Devristo\Torrent\Torrent;

// Not enabled yet
//die();


$instances = shell_exec('pgrep -f userTrackerCleaner.php');

if(!empty($instances)) {    // No instances at all? Ok time to start rTorrent!
    die();	// Already running, do not launch
}



$filterList = array(
//    'udp://',
    'udp://public.popcorn-tracker.org:6969/announce',
    'http://sub4all.org',
    'udp://tracker.publicbt.com',
    'udp://tracker.ccc.de',
    'udp://tracker.opentrackr.org',
    'http://tracker.tntvillage.scambioetico.org',
    'http://exodus.desync.com',
    'http://tracker.ftfansub.net',
    'http://nyaa.tracker.wf',
    'udp://tracker.istole.it',
    'udp://open.demonii.com',
    'udp://mgtracker.org'
);

// Get & parse users list
$usersRaw = shell_exec('/scripts/listUsers.php');
$users = $usersRaw === null ? [] : explode("\n", trim($usersRaw));
shuffle($users);

// Limit to 2 users to be checked at one pass - thus we limit IOPS used as well!
if (count($users) > 2) $users = array_slice($users, 0, 2);

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
    
        // if user is suspended, skip it
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www") ) {
            echo date('Y-m-d H:i:s') . ": User {$thisUser} is suspended\n";
            pmssUserLifecycleStep(
                'trackerCleaner',
                $thisUser,
                'kill_suspended_processes',
                'killall -9 -u '.escapeshellarg($thisUser),
                false
            );  // Ensure nothing for the user is running
            continue;  //Suspended
    }
    if (file_exists("/home/{$thisUser}/.trackerCleanerDisable")) continue; // Disabled the cleaner

    $thisUserTorrents = glob("/home/{$thisUser}/session/*.torrent");
    if (count($thisUserTorrents) == 0) continue; // nothing to do
        // We shall only check maximum of 20 at a time
      elseif (count($thisUserTorrents) > 20) { shuffle($thisUserTorrents); $thisUserTorrents = array_slice($thisUserTorrents, 0, 20); }

    $thisUserTorrentChanges = array();
    $thisUserTorrentBackupDirectory = "/home/{$thisUser}/session/backups/" . date('Y-m-d_Hi');

    echo date('Y-m-d H:i') . ": User {$thisUser}, " . count($thisUserTorrents) . " torrents to be checked.\n";


    foreach($thisUserTorrents AS $thisTorrent) {
      $torrent = Torrent::fromFile($thisTorrent);

      if ($torrent->isPrivate()) continue;	// Do not touch private torrents

      $torrentAnnounceList = $torrent->getAnnounceList();
      $torrentAnnounceListNew = array();
      foreach($torrentAnnounceList AS $thisAnnounce) {

        // I know, this is a bit of hack, but we just want to know if it matched anywhere
        $thisAnnounceCheck = str_replace($filterList, '', $thisAnnounce[0]);

//        if (strpos($thisAnnounce[0], 'udp://') === false) {
        if ($thisAnnounceCheck != $thisAnnounce[0]) {
          $torrentAnnounceListNew[] = array( $thisAnnounce[0] );
          
        } else $thisUserTorrentChanges[ $torrent->getInfoHash() ] = $torrent->getName();
      }

      $torrent->setAnnounceList( $torrentAnnounceListNew );
 // var_dump($torrentAnnounceListNew);
 // var_dump( $torrent->serialize() );
      // If we changed it
      if (isset( $thisUserTorrentChanges[ $torrent->getInfoHash() ] )) {
        // First we backup – ensure the backup directory exists via PHP, then
        // copy using a single cp command so only one shell primitive runs.
        if (!is_dir($thisUserTorrentBackupDirectory)) {
            @mkdir($thisUserTorrentBackupDirectory, 0755, true);
        }
        $backupCmd = 'cp -p '.escapeshellarg($thisTorrent).' '.escapeshellarg($thisUserTorrentBackupDirectory).'/';
        pmssUserLifecycleStep(
            'trackerCleaner',
            $thisUser,
            'backup_torrent',
            $backupCmd,
            false
        );
        $torrent->setComment( $torrent->getComment() . "; Trackers cleaned by Pulsed Media Tracker Cleaner" );
        file_put_contents( $thisTorrent, $torrent->serialize() );

      }


    }

    // Let's also log it all!
    $log = '';
    if (count($thisUserTorrentChanges) != 0) {
      $log = '';
      foreach($thisUserTorrentChanges AS $thisTorrentInfoHash => $thisTorrentName)
          $log .= date('Y-m-d H:i:s') . ": Changed {$thisTorrentName} ($thisTorrentInfoHash)\n";

      file_put_contents("/home/{$thisUser}/.trackerCleaner.log", $log, FILE_APPEND);
      echo $log;

   }
   
    
}
