#!/usr/bin/env php
<?php
/**
* PMSS: Restart rTorrent
*
* Overtly complicated way to restart rTorrent ... This is some legacy stuff; But ought to work still so ...
* refactor or not, that the question is. rTorrent is flaky as it is.
*
* Copyright (C) 2010-2024 Magna Capax Finland Oy
**/

if (!file_exists('www/.rtorrentRestart')) die();
@unlink('www/.rtorrentRestart');

require_once '.scriptsInc.php';

/**
 * Read the legacy rtorrent.lock PID without trusting it as authoritative.
 */
function pmssRtorrentRestartLockPid(string $lockPath): int
{
  if (!file_exists($lockPath)) {
    return 0;
  }

  $rtorrentLock = file_get_contents($lockPath);
  if ($rtorrentLock === false) {
    writeLog('rtorrentLock read failed');
    return 0;
  }

  writeLog('rtorrentLock read: ' . $rtorrentLock);
  $parts = explode(':+', $rtorrentLock);
  return isset($parts[1]) ? max(0, (int) $parts[1]) : 0;
}

/**
 * Verify a PID is an rTorrent process owned by the current customer UID.
 */
function pmssRtorrentRestartPidIsRtorrent(int $pid, int $uid): bool
{
  if ($pid <= 0) {
    return false;
  }

  $output = [];
  $rc = 1;
  @exec('ps -p ' . (int) $pid . ' -o euid=,comm=', $output, $rc);
  if ($rc !== 0) {
    return false;
  }

  foreach ($output as $line) {
    if (preg_match('/^\s*(\d+)\s+(\S+)\s*$/', (string) $line, $m) !== 1) {
      continue;
    }
    if ((int) $m[1] === $uid && $m[2] === 'rtorrent') {
      return true;
    }
  }

  return false;
}

/**
 * Return live rTorrent PIDs for this customer account.
 */
function pmssRtorrentRestartUserPids(int $uid): array
{
  $output = [];
  $rc = 1;
  @exec('pgrep -u ' . (int) $uid . ' -x rtorrent', $output, $rc);
  if ($rc !== 0) {
    return [];
  }

  $pids = [];
  foreach ($output as $pid) {
    $pid = trim((string) $pid);
    if ($pid !== '' && ctype_digit($pid)) {
      $pids[(int) $pid] = (int) $pid;
    }
  }

  return array_values($pids);
}

/**
 * Send a signal to the original verified rTorrent PID set.
 */
function pmssRtorrentRestartSignalPids(array $pids, int $signal): void
{
  foreach ($pids as $pid) {
    $pid = (int) $pid;
    if ($pid <= 0) {
      continue;
    }
    if (function_exists('posix_kill')) {
      @posix_kill($pid, $signal);
      continue;
    }
    @exec('kill -' . (int) $signal . ' ' . (int) $pid . ' 2>/dev/null');
  }
}

$uid = function_exists('posix_getuid') ? (int) posix_getuid() : (int) getmyuid();
$lockPid = pmssRtorrentRestartLockPid('session/rtorrent.lock');
$rtorrentPids = pmssRtorrentRestartUserPids($uid);

if ($lockPid > 0 && pmssRtorrentRestartPidIsRtorrent($lockPid, $uid)) {
  $rtorrentPids[$lockPid] = $lockPid;
} elseif ($lockPid > 0) {
  writeLog('rtorrent lock pid is stale or not rtorrent: ' . $lockPid);
}

if (!empty($rtorrentPids)) {
  $rtorrentPids = array_values(array_unique(array_map('intval', $rtorrentPids)));
  writeLog('rtorrent pids: ' . implode(',', $rtorrentPids));
  pmssRtorrentRestartSignalPids($rtorrentPids, 15);
  sleep(5); // allow time to cleanly shut

  $survivors = [];
  foreach ($rtorrentPids as $pid) {
    if (pmssRtorrentRestartPidIsRtorrent((int) $pid, $uid)) {
      $survivors[] = (int) $pid;
    }
  }
  pmssRtorrentRestartSignalPids($survivors, 9); // make certain original process died
  sleep(15); // Allow rtorrent execute to restart, before determining it's not running
} else {
  writeLog('no live rtorrent pids found for restart');
}

if (file_exists('.rtorrentExecuteRun')) $lastRun = file_get_contents('.rtorrentExecuteRun');
 else $lastRun = 0;

writeLog('Last rTorrent Execution read: ' . $lastRun);

if ($lastRun == 0 or
    (time() - $lastRun) > 60) system('screen -fa -d -m ./.rtorrentExecute.php');
