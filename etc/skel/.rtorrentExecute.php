#!/usr/bin/env php
<?php
/**
* PMSS: Start and supervise rTorrent
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
**/
require_once '.scriptsInc.php';

// Ensure we never run multiple executors for the same user. Multiple executors
// will spawn multiple rtorrent processes and corrupt the session directory.
$lockHandle = @fopen('.rtorrentExecute.lock', 'c');
if ($lockHandle === false) {
  writeLog('ERROR: failed to open .rtorrentExecute.lock (executor exiting)');
  exit(1);
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
  writeLog('executor already running; exiting');
  exit(0);
}

writeLog('executor started');
sleep((int) round(rand(2, 8))); // avoid flooding a bit.

// Resolve UID for safe pgrep usage (avoid relying on `whoami` parsing).
$uid = function_exists('posix_getuid') ? (int) posix_getuid() : (int) getmyuid();

// Infinite loop: start rTorrent and restart when it exits.
while (true) {
  file_put_contents('.rtorrentExecuteRun', (string) time());

  // If rTorrent is already running (started elsewhere), do not start another.
  $rtorrentPids = [];
  $rc = 0;
  @exec('pgrep -u '.(int) $uid.' -x rtorrent', $rtorrentPids, $rc);
  if ($rc === 0 && !empty($rtorrentPids)) {
    writeLog('rtorrent already running; refusing to start a duplicate instance (executor exiting)');
    exit(0);
  }

  // Clear process runtime of the session, otherwise ... issues.
  system('rm -rf ~/session/rtorrent.*');

  writeLog('starting rtorrent');
  $rtorrentRc = 0;
  passthru('rtorrent', $rtorrentRc);
  writeLog('rtorrent exited (rc='.$rtorrentRc.'); sleeping 5 seconds then restarting');
  sleep(5); // avoid overloading the server
}

