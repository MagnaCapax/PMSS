<?php
/** AppProcess helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerManagedAppDefinitions')) {
/** Return metadata for customer-managed app frontends copied into www/. */
 function pmssCustomerManagedAppDefinitions() { return array(
   'qBittorrent' => array('enable' => '../.qbittorrentEnable', 'endpoint' => 'qbittorrent.php', 'binaries' => array('/usr/bin/qbittorrent-nox', '/usr/local/bin/qbittorrent-nox')),
   'Deluge'      => array('enable' => '../.delugeEnable',      'endpoint' => 'deluge.php',      'binaries' => array('/usr/bin/deluged', '/usr/local/bin/deluged', '/usr/bin/deluge-web', '/usr/local/bin/deluge-web')),
   'rclone'      => array('enable' => '../.rcloneEnable',      'endpoint' => 'rclone.php',      'binaries' => array('/usr/bin/rclone')),
  ); }
}

if (!function_exists('pmssWelcomeServiceAvailable')) {
function pmssWelcomeServiceAvailable($scriptPath, array $binaryPaths) {
  if (!file_exists($scriptPath)) return false;
  foreach ($binaryPaths as $binaryPath) if (file_exists((string) $binaryPath)) return true;
  return false;
 }
}

if (!function_exists('pmssCustomerNativePidsRead')) {
/** Select only this UID's processes executing one of the installed native binaries. */
function pmssCustomerNativePidsRead(array $binaries, string $procRoot = '/proc', $uid = null): array {
 $uid = $uid === null && function_exists('posix_geteuid') ? posix_geteuid() : $uid;
 if ($uid === null || !is_int($uid) || $uid < 1) return array();
 $mountNamespace = @readlink(rtrim($procRoot, '/').'/self/ns/mnt');
 if ($mountNamespace === false) return array();
 $allowed = array();
 $scripts = array();
 foreach ($binaries as $binary) {
  $resolved = realpath($binary);
  if ($resolved === false) continue;
  $allowed[$resolved] = true;
  $handle = @fopen($resolved, 'rb');
  if (!is_resource($handle)) continue;
  $first = fgets($handle, 160);
  fclose($handle);
  if (is_string($first) && preg_match('/^#!\s*(\/\S+)/', $first, $match) === 1) {
   $interpreterPath = $match[1];
   if ($interpreterPath === '/usr/bin/env' && preg_match('/^#!\s*\/usr\/bin\/env\s+python3(?:\s|$)/', trim($first)) === 1) $interpreterPath = '/usr/bin/python3';
   $interpreter = realpath($interpreterPath);
   if ($interpreter !== false) $scripts[$resolved] = $interpreter;
  }
 }
 $pids = array();
 foreach (glob(rtrim($procRoot, '/').'/*', GLOB_ONLYDIR) ?: array() as $entry) {
  $pid = basename($entry);
  if (!ctype_digit($pid) || (int) $pid < 2) continue;
  $owner = @fileowner($entry);
  if ($owner !== $uid) continue;
  if (@readlink($entry.'/ns/mnt') !== $mountNamespace) continue;
  $exe = @readlink($entry.'/exe');
  if (is_string($exe)) $exe = preg_replace('/ \(deleted\)$/', '', $exe);
  if ($exe !== false && isset($allowed[$exe])) { $pids[] = (int) $pid; continue; }
  if ($exe === false || $scripts === array()) continue;
  $cmdline = @file_get_contents($entry.'/cmdline', false, null, 0, 4096);
  $args = is_string($cmdline) ? explode("\0", $cmdline) : array();
  $script = isset($args[1]) ? realpath($args[1]) : false;
  if ($script !== false && isset($scripts[$script]) && $scripts[$script] === $exe) $pids[] = (int) $pid;
 }
 return $pids;
}
}

if (!function_exists('pmssCustomerNativeSignal')) {
/** Signal matched native processes without selecting same-named Docker guests. */
function pmssCustomerNativeSignal(array $binaries, int $signal, string $procRoot = '/proc', $uid = null, ?callable $sender = null): int {
 $count = 0;
 foreach (pmssCustomerNativePidsRead($binaries, $procRoot, $uid) as $pid) {
  if ($sender !== null ? $sender($pid, $signal) : (function_exists('posix_kill') && @posix_kill($pid, $signal))) $count++;
 }
 return $count;
}
}

if (!function_exists('pmssCustomerNativeStop')) {
/** Give native frontends a brief graceful exit before a scoped force stop. */
function pmssCustomerNativeStop(array $binaries): void {
 if (pmssCustomerNativeSignal($binaries, 15) === 0) return;
 usleep(3000000);
 pmssCustomerNativeSignal($binaries, 9);
}
}

if (!function_exists('pmssCustomerAppsMediaIdsRead')) {
/** Return the seven installer-managed IDs, including the local Cloudplow install. */
function pmssCustomerAppsMediaIdsRead(): array
{
    return function_exists('pmssMediaStackPanelAppDefinitionsRead')
        ? array_merge(array_keys(pmssMediaStackPanelAppDefinitionsRead()), array('cloudplow')) : array();
}
}
