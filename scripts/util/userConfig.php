#!/usr/bin/env php
<?php
/**
 * PMSS user reconfiguration helper.
 *
 * Entry point for updating an existing account's quotas, scheduler weights, and
 * service configuration. It chains purpose-built helpers so the orchestration
 * layer remains concise while still enforcing the PMSS baseline on repeated
 * runs.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/user/traffic.php';
require_once __DIR__.'/../lib/user/rtorrent.php';
require_once __DIR__.'/../lib/user/deluge.php';
require_once __DIR__.'/../lib/user/qbittorrent.php';
require_once __DIR__.'/../lib/user/system.php';
require_once __DIR__.'/../lib/user/userConfigStore.php';

/**
 * Main entry point for user configuration changes.
 */


$usage = 'Usage: ./userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [CPUWEIGHT] [IOWEIGHT] [IO_READ_BW] [IO_WRITE_BW] [IO_READ_IOPS] [IO_WRITE_IOPS] [CPU_QUOTA_PERCENT] [TRAFFIC_CAP_MBIT]';
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
    'cpuQuotaPercent' => isset($argv[11]) ? $argv[11] : 0,
    'trafficCapMbit' => isset($argv[12]) ? (int) $argv[12] : 0,
];
$user['name'] = pmssNormalizeUsername((string) $user['name']);

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

$presence = [
    'trafficLimit'    => array_key_exists(4, $argv),
    'CPUWeight'       => array_key_exists(5, $argv),
    'IOWeight'        => array_key_exists(6, $argv),
    'IOReadBW'        => array_key_exists(7, $argv),
    'IOWriteBW'       => array_key_exists(8, $argv),
    'IOReadIOPS'      => array_key_exists(9, $argv),
    'IOWriteIOPS'     => array_key_exists(10, $argv),
    'cpuQuotaPercent' => array_key_exists(11, $argv),
    'trafficCapMbit'  => array_key_exists(12, $argv),
];

$store = new UserConfigStore();
$existing = $store->get($user['name']) ?? [];

$payload = $existing;
$payload['ramMiB'] = $user['memory'];
$payload['rtorrentPort'] = isset($existing['rtorrentPort']) ? (int) $existing['rtorrentPort'] : 0;
$payload['quota'] = $user['quota'];
$payload['quotaBurst'] = (int) round(((float) $user['quota']) * 1.25);
$payload['trafficLimit'] = 0;
if (!empty($presence['CPUWeight'])) {
    $payload['CPUWeight'] = $user['CPUWeight'];
}
if (!empty($presence['IOWeight'])) {
    $payload['IOWeight'] = $user['IOWeight'];
}
if (!empty($presence['IOReadBW'])) {
    $payload['IOReadBW'] = $user['IOReadBW'];
}
if (!empty($presence['IOWriteBW'])) {
    $payload['IOWriteBW'] = $user['IOWriteBW'];
}
if (!empty($presence['IOReadIOPS'])) {
    $payload['IOReadIOPS'] = $user['IOReadIOPS'];
}
if (!empty($presence['IOWriteIOPS'])) {
    $payload['IOWriteIOPS'] = $user['IOWriteIOPS'];
}
if (!empty($presence['cpuQuotaPercent'])) {
    $payload['cpuQuotaPercent'] = $user['cpuQuotaPercent'];
}
if ($presence['trafficCapMbit']) {
    $payload['trafficCapMbit'] = $user['trafficCapMbit'];
}
if (!isset($payload['billingId'])) {
    $payload['billingId'] = 0;
}
if ($payload['billingId'] === 0) {
    $payload = $store->applyFallbacks($user['name'], $payload);
}
if (!$store->set($user['name'], $payload)) {
    fwrite(STDERR, "Warning: failed to persist user config for {$user['name']}\n");
} else {
    $store->writeUserCache($user['name'], $payload);
}

// Write optional traffic caps before touching heavyweight services so limits
// persist even if later steps bail out.
userApplyTrafficLimit($user);

// Compose a canonical rtorrent configuration and mirror it to companion apps.
$configuration = userConfigureRtorrent($user);

// Persist derived ports once rTorrent config is generated so they survive
// re-runs and other tooling can read a single source of truth.
$scgiPort = (int) ($configuration['config']['scgiPort'] ?? 0);
if ($scgiPort > 0 && (!isset($payload['rtorrentPort']) || (int) $payload['rtorrentPort'] !== $scgiPort)) {
    $payload['rtorrentPort'] = $scgiPort;
    if ($store->set($user['name'], $payload)) {
        $store->writeUserCache($user['name'], $payload);
    } else {
        fwrite(STDERR, "Warning: failed to persist rtorrentPort for {$user['name']}\n");
    }
}
userConfigureRutorrent($user, $configuration);
$rclonePortFile = sprintf('/home/%s/.rclonePort', $user['name']);
if (!file_exists($rclonePortFile)) {
    file_put_contents($rclonePortFile, rand(1500, 65500));
}
userConfigureDeluge($user, $configuration);
userConfigureQbittorrent($user);
userApplyDiskQuota($user);
userRestartRtorrentIfRunning($user);
userEnsureShell($user);
userConfigureSystemdSlice($user);
userEnableLingerAndDocker($user);
