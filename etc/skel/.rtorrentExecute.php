#!/usr/bin/php
<?php
require_once '.scriptsInc.php';

writeLog('executor started');
sleep(round(Rand(3,10))); // avoid flooding a bit.

while(1==1) {
 writeLog('executing rTorrent');
 file_put_contents('.rtorrentExecuteRun', time());

 $rtorrentRunning = (int) trim(shell_exec('pgrep -u$(whoami) -c "rtorrent main"'));
 if ($rtorrentRunning == 1) { writeLog("rtorrent running, executor likely too. Discontinuing"); die(); }	// rtorrent running
 if ($rtorrentRunning >= 1) {
   writeLog('multiple rtorrent processes, killing all processes');
   shell_exec('killall -u$(whoami) "rtorrent main"; sleep 3; killall -u$(whoami) "rtorrent main"; sleep 3;');
 }
 system('rm -rf ~/session/rtorrent.*');
 passthru('rtorrent');
 writeLog('rTorrent shutdown... Sleeping 10 seconds and restarting');
 sleep(10); // avoid overloading the server

 $lastRun = file_get_contents('.rtorrentExecuteRun');
 if ( (time() - $lastRun) < 60 ) die(writeLog('There as another executor running, exiting...') );

}


