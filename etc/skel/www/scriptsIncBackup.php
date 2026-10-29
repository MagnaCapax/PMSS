<?php
/** Backup helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerBackupRelativePaths')) {
/** Return the fixed torrent-client config and resume-state backup set. */
 function pmssCustomerBackupRelativePaths() {
  return array(
   '.rtorrent.rc',
   '.rtorrent.rc.custom',
   'session',
   '.config/deluge',
   '.delugeSession',
   '.sessionDeluge',
   '.config/qBittorrent',
   '.local/share/qBittorrent/BT_backup',
   '.local/share/data/qBittorrent/BT_backup',
   '.config/pmss/rutorrent/users',
   '.local/share/pmss/rutorrent/share',
  );
 }
}

if (!function_exists('pmssCustomerBackupEntriesRead')) {
/** Return readable, non-symlinked backup entries below one customer home. */
 function pmssCustomerBackupEntriesRead($home) {
  $home = rtrim((string) $home, '/');
  if (!is_dir($home) || !pmssCustomerPathIsSafe($home)) return array();

  $entries = array();
  foreach (pmssCustomerBackupRelativePaths() as $relativePath) {
   $path = $home.'/'.$relativePath;
   if ((is_file($path) || is_dir($path)) && is_readable($path) && pmssCustomerPathIsSafe($path)) {
    $entries[] = $relativePath;
   }
  }
  return $entries;
 }
}

if (!function_exists('pmssCustomerBackupTarCommandBuild')) {
/** Build the fixed GNU tar stream command without accepting request data. */
 function pmssCustomerBackupTarCommandBuild($home, $entries) {
  if (!pmssCustomerPathIsSafe($home)) return '';
  $home = realpath((string) $home);
  if (!is_string($home) || !is_dir($home) || !pmssCustomerPathIsSafe($home) || !is_array($entries) || empty($entries)) return '';

  $allowed = array_fill_keys(pmssCustomerBackupRelativePaths(), true);
  $arguments = array('/bin/tar', '--create', '--gzip', '--file=-', '--directory', $home, '--');
  foreach ($entries as $entry) {
   if (!is_string($entry) || !isset($allowed[$entry])) return '';
   $arguments[] = $entry;
  }
  return implode(' ', array_map('escapeshellarg', $arguments));
 }
}

if (!function_exists('pmssCustomerBackupDownload')) {
/** Stream a private torrent-state archive without persisting a server copy. */
 function pmssCustomerBackupDownload($home) {
  $entries = pmssCustomerBackupEntriesRead($home);
  $command = pmssCustomerBackupTarCommandBuild($home, $entries);
  if ($command === '' || !is_executable('/bin/tar') || !pmssFrontendFunctionAvailable('passthru')) {
   http_response_code($entries === array() ? 404 : 503);
   header('Content-Type: text/plain; charset=utf-8');
   header('Cache-Control: private, no-store, max-age=0');
   echo $entries === array() ? "No torrent configuration or session state was found.\n" : "Backup download is unavailable on this host.\n";
   return false;
  }

  while (ob_get_level() > 0) {
   @ob_end_clean();
  }
  header('Content-Type: application/gzip');
  header('Content-Disposition: attachment; filename="pmss-torrent-config-'.gmdate('Ymd-His').'.tar.gz"');
  header('Cache-Control: private, no-store, max-age=0');
  header('Pragma: no-cache');
  header('X-Content-Type-Options: nosniff');

  $returnCode = 1;
  passthru($command, $returnCode);
  if ($returnCode !== 0 && !headers_sent()) http_response_code(500);
  return $returnCode === 0;
 }
}

if (!function_exists('pmssCustomerBackupFileResult')) {
/** Build the stable scheduled-backup result shape consumed by cron/tests. */
 function pmssCustomerBackupFileResult($ok, $message, $bytes = 0, $path = '', $keptCount = 0, $deletedCount = 0) {
  return array(
   'ok' => (bool) $ok,
   'message' => (string) $message,
   'bytes' => max(0, (int) $bytes),
   'path' => (string) $path,
   'keptCount' => max(0, (int) $keptCount),
   'deletedCount' => max(0, (int) $deletedCount),
  );
 }
}

if (!function_exists('pmssCustomerBackupFileDirectoryEnsure')) {
/** Prepare the private per-user scheduled backup directory. */
 function pmssCustomerBackupFileDirectoryEnsure($home, &$error) {
  $error = '';
  $home = rtrim((string) $home, '/');
  $dir = $home.'/.pmss-backups';

  if (!pmssCustomerBackupRestoreTargetPathIsSafe($home, '.pmss-backups')) {
   $error = 'Backup directory path is not safe.';
   return '';
  }
  if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
   $error = 'Backup directory is not a private directory.';
   return '';
  }
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
   $error = 'Could not create backup directory.';
   return '';
  }
  if (!pmssCustomerPathIsInsideHome($home, $dir)) {
   $error = 'Backup directory is outside the customer home.';
   return '';
  }
  if (((@fileperms($dir) & 0777) !== 0700) && !@chmod($dir, 0700)) {
   $error = 'Could not restrict backup directory permissions.';
   return '';
  }
  if (function_exists('posix_geteuid')) {
   $owner = @fileowner($dir);
   if ($owner === false || (int) $owner !== (int) posix_geteuid()) {
    $error = 'Backup directory is not owned by the customer user.';
    return '';
   }
  }

  if (!is_writable($dir)) {
   $error = 'Backup directory is not writable.';
   return '';
  }
  return $dir;
 }
}

if (!function_exists('pmssCustomerBackupFilePathBuild')) {
/** Select a fixed archive namespace; snapshots retain their existing part-file policy. */
 function pmssCustomerBackupFilePathBuild($home, $scheduled = true) {
  $home = rtrim((string) $home, '/');
  $relative = ($scheduled ? '.pmss-backups/config-' : '.pmss-pre-restore-').gmdate('Ymd-His');
  for ($i = 0; $i < 100; $i++) {
   $name = $relative.($i === 0 ? '' : '-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)).'.tar.gz';
   $path = $home.'/'.$name;
   if (!file_exists($path)
       && (!$scheduled || !file_exists($path.'.part'))
       && !is_link($path)
       && pmssCustomerBackupRestoreTargetPathIsSafe($home, $name)) {
    return $path;
   }
  }
  return '';
 }
}

if (!function_exists('pmssCustomerBackupArchiveWrite')) {
/** Stream, close, reap and publish once; return the caller's existing failure text or empty success. */
 function pmssCustomerBackupArchiveWrite($command, $archivePath, $scheduled, &$size = null) {
  $tmpPath = $archivePath.'.part';
  $out = @fopen($tmpPath, $scheduled ? 'xb' : 'wb');
  if (!is_resource($out)) return $scheduled ? 'Could not create the backup archive file.' : 'Could not create the pre-restore snapshot file.';
  $pipe = null;
  try {
   if ($scheduled) @chmod($tmpPath, 0600);
   $pipe = @popen($command, 'r');
   if (is_resource($pipe)) $bytes = @stream_copy_to_stream($pipe, $out);
  } finally {
   // Closing the output must not prevent reaping an already-started archive process.
   try {
    $closeOk = @fclose($out);
   } finally {
    if (is_resource($pipe)) $returnCode = @pclose($pipe);
   }
  }
  if ($pipe === false) {
   @unlink($tmpPath);
   return $scheduled ? 'Could not start the backup archive process.' : 'Could not start the pre-restore snapshot.';
  }

  // Scheduled archives also require nonempty output and an unoccupied final name.
  if ($bytes === false || ($scheduled && (int) $bytes <= 0) || !$closeOk || $returnCode !== 0
      || ($scheduled && file_exists($archivePath)) || !@rename($tmpPath, $archivePath)) {
   @unlink($tmpPath);
   return $scheduled ? 'Backup archive creation failed.' : 'Pre-restore snapshot failed; restore aborted.';
  }

  if ($scheduled) {
   @chmod($archivePath, 0600);
   clearstatcache(true, $archivePath);
   $size = @filesize($archivePath);
   if (!is_file($archivePath) || is_link($archivePath) || !is_numeric($size) || (int) $size <= 0) {
    @unlink($archivePath);
    return 'Backup archive verification failed.';
   }
  }
  return '';
 }
}

if (!function_exists('pmssCustomerBackupFileCreate')) {
/** Persist the fixed allowlist tar stream as a private scheduled archive. */
 function pmssCustomerBackupFileCreate($home) {
  $home = rtrim((string) $home, '/');
  if (!is_dir($home) || !pmssCustomerPathIsSafe($home)) {
   return pmssCustomerBackupFileResult(false, 'Customer home directory is not available.');
  }

  $entries = pmssCustomerBackupEntriesRead($home);
  if ($entries === array()) return pmssCustomerBackupFileResult(true, 'nothing to back up');

  $command = pmssCustomerBackupTarCommandBuild($home, $entries);
  if ($command === '' || !is_executable('/bin/tar') || !pmssFrontendFunctionAvailable('popen')) {
   return pmssCustomerBackupFileResult(false, 'Backup is unavailable on this host.');
  }

  $error = '';
  $dir = pmssCustomerBackupFileDirectoryEnsure($home, $error);
  if ($dir === '') return pmssCustomerBackupFileResult(false, $error);

  $archivePath = pmssCustomerBackupFilePathBuild($home);
  if ($archivePath === '') return pmssCustomerBackupFileResult(false, 'Could not reserve a backup archive path.');

  $error = pmssCustomerBackupArchiveWrite($command, $archivePath, true, $size);
  return $error !== '' ? pmssCustomerBackupFileResult(false, $error)
   : pmssCustomerBackupFileResult(true, 'created config backup', (int) $size, $archivePath);
 }
}

if (!function_exists('pmssCustomerBackupArchivesRead')) {
/** Return private scheduled backup archives newest first. */
 function pmssCustomerBackupArchivesRead($home) {
  $home = rtrim((string) $home, '/');
  $dir = $home.'/.pmss-backups';
  if (!is_dir($dir) || is_link($dir) || !pmssCustomerPathIsInsideHome($home, $dir)) return array();

  $archives = array();
  foreach (glob($dir.'/config-*.tar.gz') ?: array() as $path) {
   $name = basename($path);
   if (preg_match('/\Aconfig-[0-9]{8}-[0-9]{6}(?:-[0-9]{2})?\.tar\.gz\z/', $name) !== 1) continue;
   if (!is_file($path) || is_link($path) || !pmssCustomerPathIsInsideHome($home, $path)) continue;
   $mtime = @filemtime($path);
   $archives[] = array('path' => $path, 'name' => $name, 'mtime' => is_numeric($mtime) ? (int) $mtime : 0);
  }

  usort($archives, function ($a, $b) {
   if ($a['mtime'] === $b['mtime']) return strcmp($b['name'], $a['name']);
   return $b['mtime'] <=> $a['mtime'];
  });
  return $archives;
 }
}

if (!function_exists('pmssCustomerBackupRetentionPrune')) {
/** Keep only the newest scheduled config backup archives. */
 function pmssCustomerBackupRetentionPrune($home, $keep = 7) {
  $keep = max(1, (int) $keep);
  $archives = pmssCustomerBackupArchivesRead($home);
  $delete = array_slice($archives, $keep);
  $deleted = 0;

  foreach ($delete as $archive) {
   if (!@unlink($archive['path'])) {
    return pmssCustomerBackupFileResult(false, 'Could not prune old backup archive.', 0, '', count($archives) - $deleted, $deleted);
   }
   $deleted++;
  }

  return pmssCustomerBackupFileResult(true, $deleted > 0 ? 'pruned old backup archives' : 'nothing to prune', 0, '', count($archives) - $deleted, $deleted);
 }
}

if (!function_exists('pmssCustomerBackupRestoreTargetPathIsSafe')) {
/** Guard restore targets against traversal and existing symlink components. */
 function pmssCustomerBackupRestoreTargetPathIsSafe($home, $relativePath) {
  $homeReal = is_string($home) ? realpath($home) : false;
  if ($homeReal === false || !pmssCustomerPathIsSafe($homeReal)) return false;

  $relativePath = is_string($relativePath) ? trim($relativePath, '/') : '';
  if ($relativePath === '' || strpos($relativePath, "\0") !== false) return false;

  $parts = explode('/', $relativePath);
  foreach ($parts as $part) {
   if ($part === '' || $part === '.' || $part === '..') return false;
  }

  $cursor = rtrim($homeReal, '/');
  foreach ($parts as $part) {
   $candidate = $cursor.'/'.$part;
   if (is_link($candidate)) return false;
   if (!file_exists($candidate)) return pmssCustomerPathIsSafe($cursor) && pmssCustomerPathIsInsideHome($homeReal, $cursor);
   if (!pmssCustomerPathIsInsideHome($homeReal, $candidate)) return false;
   $cursor = $candidate;
  }

  return true;
 }
}
