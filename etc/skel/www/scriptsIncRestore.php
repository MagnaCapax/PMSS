<?php
/** Restore helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerBackupRestoreResult')) {
/** Build the stable restore result shape consumed by welcome.php and tests. */
 function pmssCustomerBackupRestoreResult($ok, $message, $snapshotPath = '', $restoredCount = 0, $skippedCount = 0) {
  return array(
   'ok' => (bool) $ok,
   'message' => (string) $message,
   'snapshotPath' => (string) $snapshotPath,
   'restoredCount' => max(0, (int) $restoredCount),
   'skippedCount' => max(0, (int) $skippedCount),
  );
 }
}

if (!function_exists('pmssCustomerBackupRestoreLog')) {
/** Append a restore audit line to the user's standard rTorrentLog. */
 function pmssCustomerBackupRestoreLog($home, $result) {
  if (!is_array($result) || !pmssCustomerBackupRestoreTargetPathIsSafe($home, 'rTorrentLog')) return;

  $message = preg_replace('/[\r\n\0]+/', ' ', (string) $result['message']);
  $message = is_string($message) ? trim($message) : '';
  $line = sprintf(
   'config_restore status=%s restored=%d skipped=%d snapshot=%s message=%s',
   !empty($result['ok']) ? 'success' : 'failure',
   (int) $result['restoredCount'],
   (int) $result['skippedCount'],
   (string) $result['snapshotPath'],
   $message
  );
  @file_put_contents(rtrim((string) $home, '/').'/rTorrentLog', date('Y-m-d H:i:s').'|| '.$line."\n", FILE_APPEND);
 }
}

if (!function_exists('pmssCustomerBackupRestoreFinish')) {
/** Return and audit one restore outcome. */
 function pmssCustomerBackupRestoreFinish($home, $ok, $message, $snapshotPath = '', $restoredCount = 0, $skippedCount = 0) {
  $result = pmssCustomerBackupRestoreResult($ok, $message, $snapshotPath, $restoredCount, $skippedCount);
  pmssCustomerBackupRestoreLog($home, $result);
  return $result;
 }
}

if (!function_exists('pmssCustomerBackupRestoreArchiveResolve')) {
/** Resolve a customer-supplied archive path to a real file inside this home. */
 function pmssCustomerBackupRestoreArchiveResolve($home, $archivePath, &$error) {
  $error = '';
  $archivePath = is_string($archivePath) ? trim($archivePath) : '';
  if ($archivePath === '' || strpos($archivePath, "\0") !== false) {
   $error = 'Choose an uploaded .tar.gz backup archive from your home directory.';
   return '';
  }

  if (strpos($archivePath, '~/') === 0) {
   $candidate = rtrim((string) $home, '/').'/'.substr($archivePath, 2);
  } elseif (strpos($archivePath, '/') === 0) {
   $candidate = $archivePath;
  } else {
   $candidate = rtrim((string) $home, '/').'/'.ltrim($archivePath, '/');
  }

  if (!is_file($candidate) || is_link($candidate) || !pmssCustomerPathIsInsideHome($home, $candidate)) {
   $error = 'Restore archive must be a real file inside your own home directory.';
   return '';
  }

  $real = realpath($candidate);
  if ($real === false || preg_match('/\.(?:tar\.gz|tgz)\z/i', $real) !== 1) {
   $error = 'Restore archive must be a .tar.gz or .tgz file.';
   return '';
  }

  return $real;
 }
}

if (!function_exists('pmssCustomerBackupRestoreDiskPrecheck')) {
/** Best-effort headroom check; the per-UID quota remains the extraction backstop. */
 function pmssCustomerBackupRestoreDiskPrecheck($home, $archivePath, &$error) {
  $error = '';
  $free = @disk_free_space($home);
  $size = @filesize($archivePath);
  if (!is_numeric($free) || !is_numeric($size) || (float) $size <= 0.0) {
   $error = 'Could not determine archive size or free disk space; restore aborted.';
   return false;
  }

  if ((float) $free < ((float) $size * 2.0)) {
   $error = 'Not enough free disk space to restore this archive. Free space must be at least twice the archive size.';
   return false;
  }

  return true;
 }
}

if (!function_exists('pmssCustomerBackupRestoreEntriesSelect')) {
/** Select only safe, allowlisted archive members for explicit Phar extraction. */
 function pmssCustomerBackupRestoreEntriesSelect($home, $archivePath, &$skippedCount, &$error, &$rtorrentLiveStateTouched) {
  $skippedCount = 0;
  $error = '';
  $rtorrentLiveStateTouched = false;
  $types = pmssCustomerBackupRestoreTarMemberTypesRead($archivePath);
  if (!is_array($types)) {
   $error = 'Could not read the backup archive member list.';
   return null;
  }
  $rtorrentLiveStateTouched = pmssCustomerBackupRestoreTouchesRtorrentLiveState(array_keys($types));

  try {
   $archive = new PharData($archivePath);
   $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST);
  } catch (Exception $e) {
   $error = 'Could not open the backup archive.';
   return null;
  }

  $entries = array();
  foreach ($iterator as $entry) {
   $member = pmssCustomerBackupRestoreMemberNormalize(pmssCustomerBackupRestoreMemberNameFromPhar($archivePath, $entry));
   $type = $types[$member] ?? null;
   if ($member === ''
       || !pmssCustomerBackupRestoreMemberAllowed($member)
       || !pmssCustomerBackupRestoreMemberTypeAllowed($type)
       || !pmssCustomerBackupRestoreTargetPathIsSafe($home, $member)) {
    $skippedCount++;
    continue;
   }
   $entries[$member] = $member;
  }

  return array_values($entries);
 }
}

if (!function_exists('pmssCustomerBackupRestoreTouchesRtorrentLiveState')) {
/** Return whether selected entries would touch live rTorrent config/session state. */
 function pmssCustomerBackupRestoreTouchesRtorrentLiveState($entries) {
  foreach (is_array($entries) ? $entries : array() as $entry) {
   if ($entry === '.rtorrent.rc' || $entry === '.rtorrent.rc.custom' || strpos($entry, 'session/') === 0 || $entry === 'session') return true;
  }
  return false;
 }
}

if (!function_exists('pmssCustomerBackupRestoreRtorrentProcRunning')) {
/** Detect an rtorrent process owned by the current customer UID without shelling out. */
 function pmssCustomerBackupRestoreRtorrentProcRunning($procRoot = '/proc') {
  if (!function_exists('posix_geteuid')) return false;
  $uid = posix_geteuid();
  $dir = @opendir($procRoot);
  if (!is_resource($dir)) return false;

  while (($entry = readdir($dir)) !== false) {
   if (!ctype_digit($entry)) continue;
   $status = pmssCustomerFileRead(rtrim($procRoot, '/').'/'.$entry.'/status', true);
   $comm = pmssCustomerFileRead(rtrim($procRoot, '/').'/'.$entry.'/comm', true);
   if (is_string($status)
       && is_string($comm)
       && preg_match('/^Uid:\s+'.preg_quote((string) $uid, '/').'\s/m', $status) === 1
       && strpos(trim($comm), 'rtorrent') === 0) {
    closedir($dir);
    return true;
   }
  }

  closedir($dir);
  return false;
 }
}

if (!function_exists('pmssCustomerBackupRestoreRtorrentRunning')) {
/** Refuse live session/config writes when the rTorrent lock or process is present. */
 function pmssCustomerBackupRestoreRtorrentRunning($home) {
  $lock = rtrim((string) $home, '/').'/session/rtorrent.lock';
  if (is_file($lock) && !is_link($lock) && pmssCustomerPathIsInsideHome($home, $lock)) return true;
  return pmssCustomerBackupRestoreRtorrentProcRunning();
 }
}

if (!function_exists('pmssCustomerBackupRestoreSnapshotPathBuild')) {
/** Reserve a pre-restore snapshot path without overwriting prior snapshots. */
 function pmssCustomerBackupRestoreSnapshotPathBuild($home) {
  $base = rtrim((string) $home, '/').'/.pmss-pre-restore-'.gmdate('Ymd-His');
  for ($i = 0; $i < 100; $i++) {
   $path = $base.($i === 0 ? '' : '-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)).'.tar.gz';
   if (!file_exists($path) && !is_link($path) && pmssCustomerBackupRestoreTargetPathIsSafe($home, basename($path))) return $path;
  }
  return '';
 }
}

if (!function_exists('pmssCustomerBackupRestoreSnapshotCreate')) {
/** Create the undo snapshot before any restore extraction is attempted. */
 function pmssCustomerBackupRestoreSnapshotCreate($home, &$error) {
  $error = '';
  $entries = pmssCustomerBackupEntriesRead($home);
  $command = pmssCustomerBackupTarCommandBuild($home, $entries);
  if ($command === '' || !is_executable('/bin/tar') || !pmssFrontendFunctionAvailable('popen')) {
   $error = 'Pre-restore snapshot is unavailable on this host.';
   return '';
  }

  $snapshotPath = pmssCustomerBackupRestoreSnapshotPathBuild($home);
  if ($snapshotPath === '') {
   $error = 'Could not reserve a pre-restore snapshot path.';
   return '';
  }

  $tmpPath = $snapshotPath.'.part';
  $out = @fopen($tmpPath, 'wb');
  if (!is_resource($out)) {
   $error = 'Could not create the pre-restore snapshot file.';
   return '';
  }

  $pipe = @popen($command, 'r');
  if (!is_resource($pipe)) {
   fclose($out);
   @unlink($tmpPath);
   $error = 'Could not start the pre-restore snapshot.';
   return '';
  }

  $bytes = @stream_copy_to_stream($pipe, $out);
  $closeOk = @fclose($out);
  $returnCode = @pclose($pipe);
  if ($bytes === false || !$closeOk || $returnCode !== 0 || !@rename($tmpPath, $snapshotPath)) {
   @unlink($tmpPath);
   $error = 'Pre-restore snapshot failed; restore aborted.';
   return '';
  }

  return $snapshotPath;
 }
}

if (!function_exists('pmssCustomerBackupRestore')) {
/** Restore a PMSS config backup through allowlisted Phar extraction only. */
 function pmssCustomerBackupRestore($home, $archivePath) {
  $home = rtrim((string) $home, '/');
  if (!is_dir($home) || !pmssCustomerPathIsSafe($home)) {
   return pmssCustomerBackupRestoreResult(false, 'Customer home directory is not available.');
  }

  $error = '';
  $archivePath = pmssCustomerBackupRestoreArchiveResolve($home, $archivePath, $error);
  if ($archivePath === '') return pmssCustomerBackupRestoreFinish($home, false, $error);
  if (!class_exists('PharData')) return pmssCustomerBackupRestoreFinish($home, false, 'Restore is unavailable on this host.');
  if (!pmssCustomerBackupRestoreDiskPrecheck($home, $archivePath, $error)) return pmssCustomerBackupRestoreFinish($home, false, $error);

  $skippedCount = 0;
  $rtorrentLiveStateTouched = false;
  $entries = pmssCustomerBackupRestoreEntriesSelect($home, $archivePath, $skippedCount, $error, $rtorrentLiveStateTouched);
  if (!is_array($entries)) return pmssCustomerBackupRestoreFinish($home, false, $error, '', 0, $skippedCount);
  if ($entries === array()) return pmssCustomerBackupRestoreFinish($home, false, 'No restorable torrent configuration entries were found in this archive.', '', 0, $skippedCount);

  if ($rtorrentLiveStateTouched && pmssCustomerBackupRestoreRtorrentRunning($home)) {
   return pmssCustomerBackupRestoreFinish($home, false, 'Stop your torrent client before restoring configuration, then try again.', '', 0, $skippedCount);
  }

  $snapshotPath = pmssCustomerBackupRestoreSnapshotCreate($home, $error);
  if ($snapshotPath === '') return pmssCustomerBackupRestoreFinish($home, false, $error, '', 0, $skippedCount);

  try {
   $archive = new PharData($archivePath);
   $restored = @$archive->extractTo($home, $entries, true);
  } catch (Exception $e) {
   $restored = false;
  }

  if (!$restored) {
   return pmssCustomerBackupRestoreFinish($home, false, 'Restore failed. Pre-restore snapshot: '.$snapshotPath, $snapshotPath, 0, $skippedCount);
  }

  return pmssCustomerBackupRestoreFinish($home, true, 'Restored '.count($entries).' configuration entries. Pre-restore snapshot: '.$snapshotPath, $snapshotPath, count($entries), $skippedCount);
 }
}
