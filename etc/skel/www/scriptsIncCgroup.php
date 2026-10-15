<?php
/** Cgroup helpers. Loaded by scriptsInc.php; never require a part directly. */

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
  if (pmssCustomerFileRead($memoryStatPath, true) === null) return false;

  $controllersPath = $cgroupDir.'/cgroup.controllers';
  if (!is_file($controllersPath)) return true;

  $controllers = pmssCustomerFileRead($controllersPath, true);
  return is_string($controllers)
   && preg_match('/(?:^|\s)memory(?:\s|$)/', trim($controllers)) === 1;
 }
}

if (!function_exists('pmssCustomerCgroupCounterPaths')) {
/** Build ordered counter candidates; null UID suppresses the user-slice fallback. */
 function pmssCustomerCgroupCounterPaths($uid, $cgroupDir, $v2File, $v1Controller, $v1File) {
  $paths = $cgroupDir !== '' ? array($cgroupDir.'/'.$v2File, $cgroupDir.'/'.$v1File) : array();
  if ($uid !== null) {
   $slice = 'user.slice/user-'.$uid.'.slice';
   $paths[] = '/sys/fs/cgroup/'.$slice.'/'.$v2File;
   $paths[] = '/sys/fs/cgroup/unified/'.$slice.'/'.$v2File;
   $paths[] = '/sys/fs/cgroup/'.$v1Controller.'/'.$slice.'/'.$v1File;
  }
  return array_values(array_unique($paths));
 }
}

if (!function_exists('pmssCustomerCgroupCounterRead')) {
/** Read the first unsigned counter, excluding v1 unlimited sentinels only for limits. */
 function pmssCustomerCgroupCounterRead(array $paths, $limit = false) {
  foreach ($paths as $path) {
   $value = pmssCustomerUnsignedIntegerFileRead($path);
   if ($value !== null && (!$limit || $value < 1125899906842624)) return $value;
  }
  return null;
 }
}

if (!function_exists('pmssCustomerCgroupMemoryRead')) {
/** Share the memory counter schema; unavailable slices retain null fields without probes. */
 function pmssCustomerCgroupMemoryRead($uid, $cgroupDir, $available = true) {
  $memory = array();
  foreach (array('current' => 'usage_in_bytes', 'high' => 'soft_limit_in_bytes', 'max' => 'limit_in_bytes') as $key => $v1File) {
   $memory['memory_'.$key] = $available ? pmssCustomerCgroupCounterRead(
    pmssCustomerCgroupCounterPaths($uid, $cgroupDir, 'memory.'.$key, 'memory', 'memory.'.$v1File), $key !== 'current'
   ) : null;
  }
  return $memory;
 }
}
