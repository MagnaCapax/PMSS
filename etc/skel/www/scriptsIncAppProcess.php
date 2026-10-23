<?php
/** AppProcess helpers. Loaded by scriptsInc.php; never require a part directly. */

if (!function_exists('pmssCustomerManagedAppDefinitions')) {
/** Return metadata for customer-managed app frontends copied into www/. */
 function pmssCustomerManagedAppDefinitions() { $definitions = array(
   'qBittorrent' => array('enable' => '../.qbittorrentEnable', 'endpoint' => 'qbittorrent.php', 'binaries' => array('/usr/bin/qbittorrent-nox', '/usr/local/bin/qbittorrent-nox')),
   'Deluge'      => array('enable' => '../.delugeEnable',      'endpoint' => 'deluge.php',      'binaries' => array('/usr/bin/deluged', '/usr/local/bin/deluged', '/usr/bin/deluge-web', '/usr/local/bin/deluge-web')),
   'rclone'      => array('enable' => '../.rcloneEnable',      'endpoint' => 'rclone.php',      'binaries' => array('/usr/bin/rclone')),
   'openclaw' => array('enable' => '../.openclaw/gateway.env', 'disable' => '.openclaw/pmss-stopped',
       'endpoint' => 'apps.php?managed=openclaw', 'binaries' => array(dirname(__DIR__).'/.local/share/openclaw/lib/node_modules/openclaw/openclaw.mjs'),
       'installable' => true, 'portMarker' => '.media-stack-port-openclaw', 'stateFile' => '.openclaw/check.state',
       'waitSeconds' => 180, 'url' => 'apps.php?managed=openclaw&action=open', 'log' => true,
       'statusHelperFile' => 'userOpenClaw.php', 'statusHelper' => 'pmssOpenClawStatusRead',
       'consent' => 'OpenClaw is an AI agent that can run commands as your account. It uses your Pulsed Media AI credits by default. Continue?'),
  );
  foreach (require __DIR__.'/toggleAppCatalog.php' as $id => $row) {
   $definitions[$id] = array('enable' => '../'.$row['enable'], 'endpoint' => 'toggleApp.php?app='.$id, 'binaries' => $row['binaries']);
  }
  // A version picked in Configure runs from the account's store link: status, Open and Turn off must see it too.
  foreach (array('qBittorrent' => 'qbittorrent', 'Deluge' => 'deluge') as $name => $client) {
   $selected = pmssCustomerTorrentClientSelectedBinary($client);
   if ($selected === null) continue;
   $definitions[$name]['binaries'][] = $selected;
   if ($client === 'deluge') $definitions[$name]['binaries'][] = dirname($selected).'/deluge-web';
  }
  return $definitions; }
}

if (!function_exists('pmssCustomerTorrentClientSelectedBinary')) {
/** Return the store binary the account selected for $client in Configure, or null for the system build. */
function pmssCustomerTorrentClientSelectedBinary(string $client): ?string {
 $resolver = __DIR__.'/torrentClientsResolve.php';
 if (!function_exists('pmssTorrentClientResolve')) {
  if (!is_file($resolver) || is_link($resolver)) return null;
  require_once $resolver;
 }
 return function_exists('pmssTorrentClientResolve') ? pmssTorrentClientResolve(dirname(__DIR__), $client) : null;
}
}

if (!function_exists('pmssCustomerRtorrentBinaries')) {
/** Return the rTorrent binaries this account may run: the system builds plus a Configure store selection. */
function pmssCustomerRtorrentBinaries(): array {
 $binaries = array('/usr/bin/rtorrent', '/usr/local/bin/rtorrent');
 $selected = pmssCustomerTorrentClientSelectedBinary('rtorrent');
 if ($selected !== null) $binaries[] = $selected;
 return $binaries;
}
}

if (!function_exists('pmssWelcomeServiceAvailable')) {
function pmssWelcomeServiceAvailable($scriptPath, array $binaryPaths) {
  if (!file_exists(explode('?', (string) $scriptPath, 2)[0])) return false;
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
  if ($script !== false && isset($scripts[$script]) && $scripts[$script] === $exe) { $pids[] = (int) $pid; continue; }
  // deluge-web rewrites its command line to its own name (setproctitle): only argv[0] is left, the rest is NUL.
  if (($args[0] ?? '') === '' || implode('', array_slice($args, 1)) !== '') continue;
  foreach ($scripts as $path => $interpreter) {
   if ($interpreter === $exe && basename($path) === $args[0]) { $pids[] = (int) $pid; break; }
  }
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

if (!function_exists('pmssCustomerDelugeSystemCommand')) {
/** Keep the legacy Deluge start command shared by the toggle and Configure. */
function pmssCustomerDelugeSystemCommand(): string {
 return 'nohup deluged -l /home/$(whoami)/.delugeLog -L info >> /dev/null 2>&1 & nohup deluge-web -l /home/$(whoami)/.delugeWebLog -L info >> /dev/null 2>&1 &';
}
}

if (!function_exists('pmssCustomerTorrentClientToggleStart')) {
/** Hold the account switch lock while choosing the selected or unchanged system start. */
function pmssCustomerTorrentClientToggleStart(string $client, callable $systemStart, ?string $home = null,
    string $marker = '/etc/seedbox/config/torrentClients.enabled', ?callable $selectedStart = null): void {
 if (!in_array($client, array('qbittorrent', 'deluge'), true)) return;
 $home = $home ?? dirname(__DIR__);
 $link = $home.'/bin/'.$client.'/default';
 $dir = $home.'/.config/pmss/torrentClients';
 // Match the watchdog: prepare the lock directory for a selection, and lock it whenever present.
 if (is_link($link) && !is_dir($dir) && !@mkdir($dir, 0700, true)) return;
 if (is_link($dir)) return;
 $lock = null;
 if (is_dir($dir)) {
  $lock = @fopen($dir.'/'.$client.'.lock', 'ce');
  if (!is_resource($lock)) return;
  if (!@flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; }
 }
 try {
  // Recheck under the lock so a completed Configure switch determines this launch.
  if (is_link($link) && is_file($marker)) {
   $command = 'cd ~; php ~/www/torrentClientsResolve.php '.$client.' --launch'
       .($client === 'qbittorrent' ? ' --held' : ' both').' >/dev/null 2>&1';
   if ($selectedStart !== null) $selectedStart($command);
   else pmssFrontendShellExec($command);
  } else $systemStart();
 } finally {
  if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
 }
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
