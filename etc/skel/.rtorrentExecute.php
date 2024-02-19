#!/usr/bin/php
<?php
/**
* PMSS: Start rTorrent
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
**/
require_once '.scriptsInc.php';

writeLog('executor started');
sleep(round(Rand(2,8))); // avoid flooding a bit.

// Infinite Loop -- Always keep watching that pesky little flaky rTorrent
while(1==1) {
  writeLog('executing rTorrent');
  file_put_contents('.rtorrentExecuteRun', time());

  // rtorrent running?
  $rtorrentRunning = (int) trim(shell_exec('pgrep -u$(whoami) -c "rtorrent main"'));
  if ($rtorrentRunning == 1) { writeLog("rtorrent running, executor likely too. Discontinuing"); die(); }	// rtorrent running

  //Multiple processes?
  if ($rtorrentRunning >= 1) {
    writeLog('multiple rtorrent processes, killing all processes');
    shell_exec('killall -u$(whoami) "rtorrent main"; sleep 3; killall -u$(whoami) "rtorrent main"; sleep 3;');
  }

  // Clear process runtime of the session, otherwise ... issues.
  system('rm -rf ~/session/rtorrent.*');

  // Actually start rtorrent -- but not via nohup, therefore keeping this rtorrent execute script running too for quick restarts
  passthru('rtorrent');
  writeLog('rTorrent shutdown... Sleeping 5 seconds and restarting');
  sleep(5); // avoid overloading the server

  $lastRun = file_get_contents('.rtorrentExecuteRun');
  if ( (time() - $lastRun) < 60 ) die(writeLog('There as another executor running, exiting... or we are in infinite restart loop') );

  
}


