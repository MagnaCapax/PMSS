<?php
/** Files helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerSerializedArrayFileRead')) {
/** Read a guarded customer-visible serialized array file. */
 function pmssCustomerSerializedArrayFileRead($path, $maxBytes = 8192, $allowSymlink = false) {
  return pmssWelcomeSerializedArrayDecode(pmssCustomerFileRead($path, $allowSymlink), $maxBytes);
 }
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

if (!function_exists('pmssCustomerNonnegativeFloat')) {
/** Normalize customer-visible metrics without admitting overflow or negatives. */
 function pmssCustomerNonnegativeFloat($value) {
  if (!is_numeric($value)) return null;
  $number = (float) $value;
  return is_finite($number) && $number >= 0 ? $number : null;
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

if (!function_exists('pmssWelcomeMessageCustomerPathIsSafe')) {
/** Backward-compatible welcome helper wrapper around the generic path guard. */
 function pmssWelcomeMessageCustomerPathIsSafe($path) { return pmssCustomerPathIsSafe($path); }
}

if (!function_exists('pmssWelcomeRequireLocalHelpers')) {
/** Preflight the complete delivery before loading any customer helpers, in order. */
 function pmssWelcomeRequireLocalHelpers(array $files) {
  $paths = array();
  foreach ($files as $file) {
   if (!is_string($file)
      || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\.php\z/', $file)
      || basename($file) !== $file) return false;
   $path = __DIR__.'/'.$file;
   if (!is_file($path) || is_link($path) || !is_readable($path)) return false;
   $paths[] = $path;
  }
  foreach ($paths as $path) require_once $path;
  return true;
 }
}

if (!function_exists('pmssWelcomeRequireLocalHelper')) {
/** Require one PHP helper using the same delivery and filename checks. */
 function pmssWelcomeRequireLocalHelper($file) { return pmssWelcomeRequireLocalHelpers(array($file)); }
}
