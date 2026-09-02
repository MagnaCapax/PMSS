<?php
/** 
 * PMSS User Home Scripts Inc.
 * 
 * Copyright 2010-2024 Magna Capax Finland Oy
 */
function writeLog($msg) {
 $msg = date('Y-m-d H:i:s') . '|| ' . $msg . "\n";
 file_put_contents('rTorrentLog', $msg, FILE_APPEND);

}

/**
 * Frontend action wrappers require an explicit action request.
 * This keeps the lightweight toggle endpoints on one contract.
 */
function pmssFrontendActionRequest() {
 if (!isset($_REQUEST['action'])) die();

 return (string) $_REQUEST['action'];
}

/** Check whether a customer-side PHP action may call one runtime function. */
function pmssFrontendFunctionAvailable($function) {
 if (!is_string($function) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $function) !== 1) return false;
 $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
 return function_exists($function) && !in_array($function, $disabled, true);
}

/**
 * Check whether customer PHP may run shell commands on this host.
 */
function pmssFrontendShellExecAvailable() {
 return pmssFrontendFunctionAvailable('shell_exec');
}

/**
 * Run a customer-side shell command only when the PHP runtime allows it.
 */
function pmssFrontendShellExec($command) {
 return pmssFrontendShellExecAvailable() ? shell_exec($command) : null;
}

if (!function_exists('pmssFormatBytes')) {
 /**
  * Format byte counts with binary IEC units for customer-facing pages.
  */
 function pmssFormatBytes($bytes, $precision = 1, $minimumUnitIndex = 0, $trimTrailingZeros = false) {
  $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
  $bytes = max((float) $bytes, 0.0);
  $index = 0;
  $minimumUnitIndex = max(0, min((int) $minimumUnitIndex, count($units) - 1));
  while ($index < $minimumUnitIndex && $index < count($units) - 1) {
   $bytes /= 1024.0;
   $index++;
  }
  while ($bytes >= 1024 && $index < count($units) - 1) {
   $bytes /= 1024.0;
   $index++;
  }

  $formatted = number_format($bytes, (int) $precision, '.', '');
  if ($trimTrailingZeros) {
   $formatted = rtrim(rtrim($formatted, '0'), '.');
  }

  return ($formatted === '' ? '0' : $formatted).' '.$units[$index];
 }
}

if (!function_exists('pmssWelcomeSerializedArrayHasObject')) {
 /**
  * Reject object payloads that should never appear in customer snapshots.
  */
 function pmssWelcomeSerializedArrayHasObject($value, $depth = 0) {
  if (is_object($value)) return true;
  if (!is_array($value)) return false;
  if ($depth > 32 || count($value) > 256) return true;
  foreach ($value as $child) {
   if (pmssWelcomeSerializedArrayHasObject($child, $depth + 1)) return true;
  }
  return false;
 }
}

if (!function_exists('pmssWelcomeSerializedArrayDecode')) {
 /**
  * Decode serialized customer-facing arrays without object wakeups.
  */
 function pmssWelcomeSerializedArrayDecode($raw, $maxBytes = 8192) {
  if (!is_string($raw) || $raw === '' || strlen($raw) > $maxBytes) return null;
  $data = @unserialize($raw, array('allowed_classes' => false));
  return is_array($data) && !pmssWelcomeSerializedArrayHasObject($data) ? $data : null;
 }
}

if (!function_exists('pmssCustomerSerializedArrayFileRead')) {
 /** Read a guarded customer-visible serialized array file. */
 function pmssCustomerSerializedArrayFileRead($path, $maxBytes = 8192, $allowSymlink = false) {
  return pmssWelcomeSerializedArrayDecode(pmssCustomerFileRead($path, $allowSymlink), $maxBytes);
 }
}

if (!function_exists('pmssJsonDecodeAssoc')) {
 /** Decode JSON through associative arrays, rejecting invalid or scalar payloads. */
 function pmssJsonDecodeAssoc($payload) { $decoded = json_decode((string) $payload, true); return is_array($decoded) ? $decoded : null; }
}

if (!function_exists('pmssJsonEncodePretty')) {
 /** Encode data with PMSS's standard pretty file-output flags. */
 function pmssJsonEncodePretty($payload, $extraFlags = 0) { $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | (int) $extraFlags); return is_string($encoded) ? $encoded : null; }
}

if (!function_exists('pmssCustomerFileRead')) {
 /** Read a local customer-visible file without following symlinks unless requested. */
 function pmssCustomerFileRead($path, $allowSymlink = false) {
  if (!is_string($path) || $path === '' || ($allowSymlink ? !file_exists($path) : (!is_file($path) || is_link($path)))) return null;
  $raw = @file_get_contents($path);
  return is_string($raw) ? $raw : null;
 }
}

if (!function_exists('pmssCustomerTrimmedFileRead')) {
 /** Read a local file as trimmed text while preserving the caller's symlink policy. */
 function pmssCustomerTrimmedFileRead($path, $allowSymlink = false) {
  $raw = pmssCustomerFileRead($path, $allowSymlink);
  return is_string($raw) ? trim($raw) : null;
 }
}

if (!function_exists('pmssCustomerUnsignedIntegerValue')) {
 /** Convert scalar cgroup/config text to an unsigned integer. */
 function pmssCustomerUnsignedIntegerValue($value) {
  return is_scalar($value) && ctype_digit((string) $value) ? (int) $value : null;
 }
}

if (!function_exists('pmssCustomerUnsignedIntegerFileRead')) {
 /** Read an unsigned integer from a customer-visible file. */
 function pmssCustomerUnsignedIntegerFileRead($path, $allowSymlink = false) {
  $trimmed = pmssCustomerTrimmedFileRead($path, $allowSymlink);
  return is_string($trimmed) ? pmssCustomerUnsignedIntegerValue($trimmed) : null;
 }
}

if (!function_exists('pmssCustomerPositiveIntegerFileRead')) {
 /** Read a positive integer from a customer-visible file. */
 function pmssCustomerPositiveIntegerFileRead($path, $allowSymlink = false) {
  $value = pmssCustomerUnsignedIntegerFileRead($path, $allowSymlink);
  return $value !== null && $value > 0 ? $value : null;
 }
}

if (!function_exists('pmssCustomerBonusDisplayStateRead')) {
 /** Read the loyalty percentage, falling back to the aggregate additional GiB allocation. */
 function pmssCustomerBonusDisplayStateRead($userBonusPath = '../.userBonus', $bonusQuotaPath = '../.bonusQuota') {
  $userBonus = pmssCustomerUnsignedIntegerFileRead($userBonusPath);
  if ($userBonus !== null) {
   return array('unit' => 'percent', 'value' => $userBonus, 'state' => $userBonus > 0 ? 'applied' : 'zero');
  }

  $bonusQuota = pmssCustomerPositiveIntegerFileRead($bonusQuotaPath);
  if ($bonusQuota !== null) {
   return array('unit' => 'gib', 'value' => $bonusQuota, 'state' => 'applied');
  }

  return array('unit' => 'percent', 'value' => 0, 'state' => 'absent');
 }
}

if (!function_exists('pmssCustomerBonusDisplayTextBuild')) {
 /** Keep loyalty percentages distinct from aggregate additional disk space. */
 function pmssCustomerBonusDisplayTextBuild($state) {
  $unit = is_array($state) && isset($state['unit']) && $state['unit'] === 'gib' ? 'gib' : 'percent';
  $value = is_array($state) && isset($state['value']) && is_numeric($state['value'])
   ? max(0, (int) $state['value'])
   : 0;

  return $unit === 'gib'
   ? 'ADDITIONAL DISK SPACE: '.number_format($value, 0, '.', '').' GiB'
   : 'BONUS: '.number_format($value).'%';
 }
}

if (!function_exists('pmssCustomerBonusDisplayNoteBuild')) {
 /** Explain whether the displayed bonus is present on this server. */
 function pmssCustomerBonusDisplayNoteBuild($state) {
  if (!is_array($state) || !isset($state['state'])) return '';

  switch ((string) $state['state']) {
   case 'applied':
    return isset($state['unit']) && $state['unit'] === 'gib'
     ? 'Additional disk space is included in your quota on this server.'
     : 'Bonus is applied on this server.';
   case 'zero':
    return 'No bonus is applied on this server.';
   case 'absent':
    return 'No bonus is applied on this server yet. Earned bonus is added on top of your base resources, never taken from them.';
   default:
    return '';
  }
 }
}

if (!function_exists('pmssCustomerKeyValueFileRead')) {
 /** Parse simple whitespace-delimited kernel/config key-value files. */
 function pmssCustomerKeyValueFileRead($path) {
  $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  $map = array();
  foreach (is_array($raw) ? $raw : array() as $line) {
   $parts = preg_split('/\s+/', trim((string) $line), 2);
   if (count($parts) === 2) $map[$parts[0]] = $parts[1];
  }
  return $map;
 }
}

if (!function_exists('pmssCustomerCgroupSelfEntries')) {
 /** Parse the current process cgroup membership into normalized entries. */
 function pmssCustomerCgroupSelfEntries($path = '/proc/self/cgroup') {
  $lines = @file((string) $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  $entries = array();
  foreach (is_array($lines) ? $lines : array() as $line) {
   $parts = explode(':', (string) $line, 3);
   if (count($parts) !== 3 || trim($parts[2]) === '') continue;
   $entries[] = array('hierarchy' => trim($parts[0]), 'controllers' => $parts[1] === '' ? array() : array_values(array_filter(array_map('trim', explode(',', $parts[1])), 'strlen')), 'path' => '/'.ltrim(trim($parts[2]), '/'));
  }
  return $entries;
 }
}

if (!function_exists('pmssCustomerCgroupDirOwnsMemoryController')) {
 /** Return whether a cgroup directory exposes readable memory-controller data. */
 function pmssCustomerCgroupDirOwnsMemoryController($cgroupDir) {
  if (!is_string($cgroupDir) || !is_dir($cgroupDir)) return false;

  $cgroupDir = rtrim($cgroupDir, '/');
  $memoryStatPath = $cgroupDir.'/memory.stat';
  if (!is_file($memoryStatPath) || @file_get_contents($memoryStatPath) === false) return false;

  $controllersPath = $cgroupDir.'/cgroup.controllers';
  if (!is_file($controllersPath)) return true;

  $controllers = @file_get_contents($controllersPath);
  return is_string($controllers)
   && preg_match('/(?:^|\s)memory(?:\s|$)/', trim($controllers)) === 1;
 }
}

if (!function_exists('pmssCustomerHtmlAttr')) { /** Escape customer GUI text and attributes with the shared PMSS flags. */ function pmssCustomerHtmlAttr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } }

if (!function_exists('pmssCustomerContextualHelpLinkBuild')) {
 /** Render help only for panel items with a known published wiki page. */
 function pmssCustomerContextualHelpLinkBuild($item) {
  $targets = array(
   'panelFrameSource' => array(
    'url' => 'https://wiki.pulsedmedia.com/index.php/Panel_Frame_Source',
    'title' => 'Read about panel frame sources in the Pulsed Media Wiki',
   ),
  );
  if (!is_string($item) || !isset($targets[$item])) return '';

  $target = $targets[$item];
  return ' <a class="pmss-contextual-help" href="'.pmssCustomerHtmlAttr($target['url']).'" target="_blank" rel="noopener noreferrer" title="'.pmssCustomerHtmlAttr($target['title']).'" aria-label="'.pmssCustomerHtmlAttr($target['title']).'">(?)</a>';
 }
}

if (!function_exists('pmssJsonFileReadAssoc')) {
 /** Read a JSON object file as an associative array, optionally requiring a customer-safe path. */
 function pmssJsonFileReadAssoc($path, $safePathRequired = false) {
  if (!is_string($path) || $path === '' || ($safePathRequired && !pmssCustomerPathIsSafe($path))) return null;
  $raw = pmssCustomerTrimmedFileRead($path);
  if (!is_string($raw) || $raw === '') return null;
  return pmssJsonDecodeAssoc($raw);
 }
}

if (!function_exists('pmssCustomerHomeRoot')) {
 /** Return the configured customer home root without a trailing slash. */
 function pmssCustomerHomeRoot() { $homeRoot = getenv('PMSS_HOME_DIR'); return (is_string($homeRoot) && trim($homeRoot) !== '') ? rtrim($homeRoot, '/') : '/home'; }
}

if (!function_exists('pmssCustomerHomePath')) {
 /** Build a path under a customer home while preserving legacy suffix handling. */
 function pmssCustomerHomePath($home, $suffix) { return rtrim((string) $home, '/').'/'.(string) $suffix; }
}

if (!function_exists('pmssCustomerPathIsSafe')) {
 /**
  * Keep customer-side file access below the configured home root.
  */
 function pmssCustomerPathIsSafe($path) {
  if (!is_string($path) || $path === '' || strpos($path, "\0") !== false || is_link($path)) return false;
  $homeRoot = pmssCustomerHomeRoot();
  $real = realpath($path);
  if ($real === false) {
   $real = realpath(dirname($path));
   if ($real === false) return false;
   $real .= '/'.basename($path);
  }
  return strpos($real, $homeRoot.'/') === 0;
 }
}

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
  if (!is_string($home) || !is_dir($home) || !pmssCustomerPathIsSafe($home) || !is_array($entries) || count($entries) === 0) return '';

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

if (!function_exists('pmssCustomerPathIsInsideHome')) {
 /** Require a resolved path to stay inside one customer's home directory. */
 function pmssCustomerPathIsInsideHome($home, $path) {
  if (!is_string($home) || !is_string($path) || $home === '' || $path === '' || strpos($path, "\0") !== false || is_link($path)) return false;
  if (!pmssCustomerPathIsSafe($home) || !pmssCustomerPathIsSafe($path)) return false;

  $homeReal = realpath($home);
  $pathReal = realpath($path);
  if ($homeReal === false || $pathReal === false) return false;

  $homeReal = rtrim($homeReal, '/');
  return $pathReal === $homeReal || strpos($pathReal, $homeReal.'/') === 0;
 }
}

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

if (!function_exists('pmssCustomerBackupRestoreTarSize')) {
 /** Parse a POSIX tar octal size field. */
 function pmssCustomerBackupRestoreTarSize($raw) {
  $raw = trim((string) $raw, " \0");
  if ($raw === '' || preg_match('/\A[0-7]+\z/', $raw) !== 1) return null;
  return octdec($raw);
 }
}

if (!function_exists('pmssCustomerBackupRestoreTarSkipPayload')) {
 /** Advance a gzipped tar stream over one padded payload. */
 function pmssCustomerBackupRestoreTarSkipPayload($handle, $size) {
  $remaining = (int) (ceil(max(0, (int) $size) / 512) * 512);
  while ($remaining > 0) {
   $chunk = @gzread($handle, min(8192, $remaining));
   if (!is_string($chunk) || $chunk === '') return false;
   $remaining -= strlen($chunk);
  }
  return true;
 }
}

if (!function_exists('pmssCustomerBackupRestoreTarPayloadRead')) {
 /** Read a bounded tar metadata payload and consume its padding. */
 function pmssCustomerBackupRestoreTarPayloadRead($handle, $size) {
  if ((int) $size < 0 || (int) $size > 65536) return false;
  $padded = (int) (ceil((int) $size / 512) * 512);
  $raw = '';
  while (strlen($raw) < $padded) {
   $chunk = @gzread($handle, min(8192, $padded - strlen($raw)));
   if (!is_string($chunk) || $chunk === '') return false;
   $raw .= $chunk;
  }
  return substr($raw, 0, (int) $size);
 }
}

if (!function_exists('pmssCustomerBackupRestorePaxPayloadParse')) {
 /** Parse the small subset of PAX headers that can override member paths. */
 function pmssCustomerBackupRestorePaxPayloadParse($payload) {
  $headers = array();
  $offset = 0;
  $length = strlen((string) $payload);
  while ($offset < $length) {
   $space = strpos($payload, ' ', $offset);
   if ($space === false) break;
   $recordLength = (int) substr($payload, $offset, $space - $offset);
   if ($recordLength <= 0 || $offset + $recordLength > $length) break;

   $record = substr($payload, $space + 1, $recordLength - ($space + 1 - $offset));
   $equals = strpos($record, '=');
   if ($equals !== false) {
    $headers[substr($record, 0, $equals)] = rtrim(substr($record, $equals + 1), "\n");
   }
   $offset += $recordLength;
  }
  return $headers;
 }
}

if (!function_exists('pmssCustomerBackupRestoreMemberNormalize')) {
 /** Normalize one archive member name, returning an empty string for unsafe paths. */
 function pmssCustomerBackupRestoreMemberNormalize($member) {
  $member = is_string($member) ? str_replace('\\', '/', $member) : '';
  if ($member === '' || strpos($member, "\0") !== false || strpos($member, '/') === 0) return '';
  while (strpos($member, './') === 0) {
   $member = substr($member, 2);
  }

  $parts = array();
  foreach (explode('/', $member) as $part) {
   if ($part === '' || $part === '.') continue;
   if ($part === '..') return '';
   $parts[] = $part;
  }
  return $parts === array() ? '' : implode('/', $parts);
 }
}

if (!function_exists('pmssCustomerBackupRestoreTarMemberTypesRead')) {
 /** Read tar headers so link members can be skipped before Phar extraction. */
 function pmssCustomerBackupRestoreTarMemberTypesRead($archivePath) {
  $handle = @gzopen($archivePath, 'rb');
  if (!is_resource($handle)) return null;

  $types = array();
  $pendingName = null;
  while (!@gzeof($handle)) {
   $header = @gzread($handle, 512);
   if (!is_string($header) || $header === '') break;
   if (strlen($header) !== 512) { @gzclose($handle); return null; }
   if (trim($header, "\0") === '') break;

   $type = substr($header, 156, 1);
   $type = ($type === '' || $type === "\0") ? '0' : $type;
   $size = pmssCustomerBackupRestoreTarSize(substr($header, 124, 12));
   if ($size === null) { @gzclose($handle); return null; }

   $name = rtrim(substr($header, 0, 100), " \0");
   $prefix = rtrim(substr($header, 345, 155), " \0");
   if ($prefix !== '') $name = $prefix.'/'.$name;

   if ($type === 'L') {
    $payload = pmssCustomerBackupRestoreTarPayloadRead($handle, $size);
    if (!is_string($payload)) { @gzclose($handle); return null; }
    $pendingName = rtrim($payload, "\0");
    continue;
   }

   if ($type === 'x') {
    $payload = pmssCustomerBackupRestoreTarPayloadRead($handle, $size);
    if (!is_string($payload)) { @gzclose($handle); return null; }
    $pax = pmssCustomerBackupRestorePaxPayloadParse($payload);
    if (isset($pax['path'])) $pendingName = $pax['path'];
    continue;
   }

   if (!pmssCustomerBackupRestoreTarSkipPayload($handle, $size)) { @gzclose($handle); return null; }
   $member = pmssCustomerBackupRestoreMemberNormalize($pendingName !== null ? $pendingName : $name);
   $pendingName = null;
   if ($member !== '') {
    $currentType = ($type === '0' || $type === '5') ? $type : '!';
    $types[$member] = isset($types[$member]) && $types[$member] !== $currentType ? '!' : $currentType;
   }
  }

  @gzclose($handle);
  return $types;
 }
}

if (!function_exists('pmssCustomerBackupRestoreMemberAllowed')) {
 /** Keep restore extraction exactly under the existing backup allowlist. */
 function pmssCustomerBackupRestoreMemberAllowed($member) {
  foreach (pmssCustomerBackupRelativePaths() as $allowed) {
   if ($member === $allowed || strpos($member, $allowed.'/') === 0) return true;
  }
  return false;
 }
}

if (!function_exists('pmssCustomerBackupRestoreMemberTypeAllowed')) {
 /** Permit only regular files and directories from the archive. */
 function pmssCustomerBackupRestoreMemberTypeAllowed($type) {
  return $type === '0' || $type === '5';
 }
}

if (!function_exists('pmssCustomerBackupRestoreMemberNameFromPhar')) {
 /** Strip Phar's archive URI prefix to the member path expected by extractTo(). */
 function pmssCustomerBackupRestoreMemberNameFromPhar($archivePath, $entry) {
  $path = $entry instanceof SplFileInfo ? $entry->getPathname() : '';
  $prefix = 'phar://'.$archivePath.'/';
  return is_string($path) && strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : '';
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
   $type = isset($types[$member]) ? $types[$member] : null;
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
   $status = @file_get_contents(rtrim($procRoot, '/').'/'.$entry.'/status');
   $comm = @file_get_contents(rtrim($procRoot, '/').'/'.$entry.'/comm');
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

if (!function_exists('pmssWelcomeMessageCustomerPathIsSafe')) {
 /** Backward-compatible welcome helper wrapper around the generic path guard. */
 function pmssWelcomeMessageCustomerPathIsSafe($path) { return pmssCustomerPathIsSafe($path); }
}

if (!function_exists('pmssWelcomeRequireLocalHelper')) {
 /**
  * Require one PHP helper from the customer tree when present.
  */
 function pmssWelcomeRequireLocalHelper($file) {
  if (!is_string($file)
      || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\.php\z/', $file)
      || basename($file) !== $file) {
   return false;
  }

  $path = __DIR__.'/'.$file;
  if (is_file($path) && !is_link($path) && is_readable($path)) {
   require_once $path;
   return true;
  }

  return false;
 }
}

if (!function_exists('pmssCustomerManagedAppDefinitions')) {
 /** Return metadata for customer-managed app frontends copied into www/. */
 function pmssCustomerManagedAppDefinitions() { return array(
   'qBittorrent' => array('enable' => '../.qbittorrentEnable', 'endpoint' => 'qbittorrent.php', 'binaries' => array('/usr/bin/qbittorrent-nox', '/usr/local/bin/qbittorrent-nox')),
   'Deluge'      => array('enable' => '../.delugeEnable',      'endpoint' => 'deluge.php',      'binaries' => array('/usr/bin/deluged', '/usr/local/bin/deluged')),
   'rclone'      => array('enable' => '../.rcloneEnable',      'endpoint' => 'rclone.php',      'binaries' => array('/usr/bin/rclone')),
  ); }
}

if (!function_exists('pmssWelcomeHttpContextCreate')) {
 /**
  * Build the standard remote-request context used by PMSS GUI pages.
  */
 function pmssWelcomeHttpContextCreate() {
  return stream_context_create(array('http' => array('timeout' => 5, 'user_agent' => 'PMSS-GUI (+https://pulsedmedia.com)')));
 }
}

/**
 * Shared start/disable/restart toggle flow for lightweight app frontends.
 * The callers provide the enable marker path plus app-specific commands.
 */
function pmssFrontendToggleAction($enableFile, callable $startHandler, $disableCommand, $restartCommand = null) {
 switch (pmssFrontendActionRequest()) {
  case 'start':
    touch($enableFile);
    $startHandler();
    break;

  case 'disable':
    unlink($enableFile);
    pmssFrontendShellExec($disableCommand);
    break;

  case 'restart':
    pmssFrontendShellExec($restartCommand === null ? $disableCommand : $restartCommand);
    $startHandler();
    break;
 }
}
