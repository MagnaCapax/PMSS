#!/usr/bin/env php
<?php
/**
* PMSS: Start and supervise rTorrent
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
**/
require_once '.scriptsInc.php';

// Remember this version before waiting for the first child to start.
$executorHash = @hash_file('sha256', __FILE__);

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

// Infinite loop: start rTorrent and restart when it exits.
while (true) {
  // Only hand over between children; unreadable probes are not proof of staleness.
  clearstatcache(true, __FILE__);
  $executorExe = @readlink('/proc/self/exe');
  $currentExecutorHash = @hash_file('sha256', __FILE__);
  if (($executorExe !== false && substr($executorExe, -10) === ' (deleted)') ||
      ($executorHash !== false && $currentExecutorHash !== false && $executorHash !== $currentExecutorHash)) {
    writeLog('executor stale; exiting for watchdog restart');
    break;
  }

  file_put_contents('.rtorrentExecuteRun', (string) time());

  // Recheck the account selection after each exit; keep the system command on resolver failure.
  $rtorrentCommand = '/usr/local/bin/rtorrent';
  try {
    if (is_file(__DIR__.'/www/torrentClientsResolve.php')) {
      @include_once __DIR__.'/www/torrentClientsResolve.php';
      if (function_exists('pmssRtorrentExecutorCommand')) {
        $rtorrentCommand = pmssRtorrentExecutorCommand(__DIR__);
      }
    }
  } catch (Throwable $error) {
    $rtorrentCommand = '/usr/local/bin/rtorrent';
  }

  // Clear process runtime of the session, otherwise ... issues.
  system('rm -rf ~/session/rtorrent.*');

  writeLog('starting rtorrent');
  $rtorrentRc = 0;
  passthru($rtorrentCommand, $rtorrentRc);
  writeLog('rtorrent exited (rc='.$rtorrentRc.'); sleeping 5 seconds then restarting');
  sleep(5); // avoid overloading the server
}

// Let panel restart and the watchdog start a fresh executor under the same lock.
file_put_contents('.rtorrentExecuteRun', '0');
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

