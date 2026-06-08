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

/**
 * Check whether customer PHP may run shell commands on this host.
 */
function pmssFrontendShellExecAvailable() {
 $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
 return function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
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

if (!function_exists('pmssCustomerHtmlAttr')) { /** Escape customer GUI text and attributes with the shared PMSS flags. */ function pmssCustomerHtmlAttr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } }
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
