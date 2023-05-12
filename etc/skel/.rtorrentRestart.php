#!/usr/bin/php
<?php
if (!file_exists('www/.rtorrentRestart')) die();
//unlink('www/.rtorrentRestart');
system('rm -rf www/.rtorrentRestart');

require_once '.scriptsInc.php';


if (file_exists('session/rtorrent.lock')) $rtorrentLock = file_get_contents('session/rtorrent.lock');
 else $rtorrentLock = false;

writeLog('rtorrentLock read: ' . $rtorrentLock);


if ($rtorrentLock != false) {
 $pid = explode(':+', $rtorrentLock);
 $pid = (int) $pid[1];

 writeLog('rtorrent pid: ' . $pid);


 if ($pid > 0) {
  system('kill ' . $pid);
  sleep(15); // allow time to cleanly shut
  system('kill -9 ' . $pid); // make certain it died
  sleep(61); // Allow rtorrent execute to restart, before determining it's not running
 } else die(); 
}

if (file_exists('.rtorrentExecuteRun')) $lastRun = file_get_contents('.rtorrentExecuteRun');
 else $lastRun = 0;

writeLog('Last rTorrent Execution read: ' . $lastRun);

if ($lastRun == 0 or
    (time() - $lastRun) > 60) system('screen -fa -d -m ./.rtorrentExecute.php');
