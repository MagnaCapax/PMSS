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

$usage = 'Usage: ./userConfig.php USERNAME MAX_RAM_MB DISK_QUOTA_IN_GB [TRAFFIC_LIMIT_GB] [CPUWEIGHT=1000] [IOWEIGHT=1000] [CPUQUOTAPCT]';
if (empty($argv[1]) || empty($argv[2]) || empty($argv[3])) {
    die('need user name. '.$usage."\n");
}

// The $user array is populated from sanitized command-line arguments ($argv)
// provided by an operator or trusted automation.
$user = [
    'name'      => $argv[1],
    'memory'    => (int) $argv[2],
    'quota'     => (int) $argv[3],
    'CPUWeight' => isset($argv[5]) ? (int) $argv[5] : 0,
    'IOWeight'  => isset($argv[6]) ? (int) $argv[6] : 0,
    'IOReadBW'    => isset($argv[7]) ? $argv[7] : null,
    'IOWriteBW'   => isset($argv[8]) ? $argv[8] : null,
    'IOReadIOPS'  => isset($argv[9]) ? $argv[9] : null,
    'IOWriteIOPS' => isset($argv[10]) ? $argv[10] : null,
    'cpuQuotaPercent' => isset($argv[11]) ? (int) $argv[11] : 0,
];

// Safely get the user ID
$output = [];
$return_var = 0;
exec('id -u ' . escapeshellarg($user['name']), $output, $return_var);
if ($return_var !== 0) {
    die("Failed to get user ID for {$user['name']}\n");
}
$user['id'] = (int) trim($output[0]);

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
