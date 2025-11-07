#!/usr/bin/php
<?php
/**
 * PMSS user reconfiguration helper.
 *
 * Entry point for updating an existing account's quotas, scheduler weights, and
 * service configuration. It chains purpose-built helpers so the orchestration
 * layer remains concise while still enforcing the PMSS baseline on repeated
 * runs.
 */

require_once '/scripts/lib/user/traffic.php';
require_once '/scripts/lib/user/rtorrent.php';
require_once '/scripts/lib/user/deluge.php';
require_once '/scripts/lib/user/qbittorrent.php';
require_once '/scripts/lib/user/integrations.php';
require_once '/scripts/lib/user/system.php';
require_once '/scripts/lib/user/helpers.php';

$usage = 'Usage: ./userConfig.php USERNAME MAX_RAM_MB DISK_QUOTA_IN_GB [TRAFFIC_LIMIT_GB] [CPUWEIGHT=1000] [IOWEIGHT=1000]';
if (empty($argv[1]) || empty($argv[2]) || empty($argv[3])) {
    die('need user name. '.$usage."\n");
}

$user = [
    'name'      => $argv[1],
    'memory'    => (int) $argv[2],
    'quota'     => (int) $argv[3],
    'CPUWeight' => isset($argv[5]) ? (int) $argv[5] : 500,
    'IOWeight'  => isset($argv[6]) ? (int) $argv[6] : 500,
];
$user['id'] = (int) trim(`id -u {$user['name']}`);

if (isset($argv[4])) {
    $user['trafficLimit'] = (int) $argv[4];
}

if ($user['id'] < 1000) {
    die("No system ID or user does not exist\n");
}
if (!file_exists("/home/{$user['name']}")) {
    die("User does not exist\n");
}

$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $user['name']) === false) {
    die("No such user in passwd list\n");
}

// Write optional traffic caps before touching heavyweight services so limits
// persist even if later steps bail out.
userApplyTrafficLimit($user);

$user['CPUWeight'] = $user['CPUWeight'] ?: 500;
$user['IOWeight']  = $user['IOWeight']  ?: 500;

// Compose a canonical rtorrent configuration and mirror it to companion apps.
$configuration = userConfigureRtorrent($user);
userConfigureRutorrent($user, $configuration);
userEnsureRclonePort($user);
userConfigureDeluge($user, $configuration);
userConfigureQbittorrent($user);
userApplyDiskQuota($user);
userRestartRtorrentIfRunning($user);
userEnsureShell($user);
userConfigureSystemdSlice($user);
userEnableLingerAndDocker($user);
