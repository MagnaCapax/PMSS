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

foreach (['traffic', 'deluge', 'qbittorrent', 'system', 'userConfigStore'] as $module) {
    require_once __DIR__.'/../lib/user/'.$module.'.php';
}
require_once __DIR__.'/../lib/rtorrentConfig.php';
require_once __DIR__.'/../lib/update.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

/**
 * Main entry point for user configuration changes.
 */


$usage = 'Usage: ./userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [CPUWEIGHT] [IOWEIGHT] [IO_READ_BW] [IO_WRITE_BW] [IO_READ_IOPS] [IO_WRITE_IOPS] [CPU_QUOTA_PERCENT] [TRAFFIC_CAP_MBIT]';
$rawArgs = $argv ?? ($_SERVER['argv'] ?? []);
$args = [];
$uploadThrottleKib = null;
$welcomeMessage = null;
foreach ($rawArgs as $arg) {
    if (strpos($arg, '--upload-throttle-kib=') === 0) {
        $uploadThrottleKib = substr($arg, strlen('--upload-throttle-kib='));
        continue;
    }
    if (strpos($arg, '--welcome-message=') === 0) {
        $welcomeMessage = substr($arg, strlen('--welcome-message='));
        continue;
    }
    $args[] = $arg;
}
$usage .= ' [--upload-throttle-kib=KIB] [--welcome-message=HTML]';
$usage .= "\n   or: ./userConfig.php USERNAME --welcome-message=HTML";
$fullConfigMode = !empty($args[1]) && !empty($args[2]) && !empty($args[3]);
$welcomeOnlyMode = !empty($args[1]) && $welcomeMessage !== null && empty($args[2]) && empty($args[3]);
if (!$fullConfigMode && !$welcomeOnlyMode) {
    die($usage."\n");
}

if ($welcomeOnlyMode && $uploadThrottleKib !== null) {
    die("--upload-throttle-kib requires RAM and quota arguments\n");
}

// The $user array is populated from sanitized command-line arguments ($args)
// provided by an operator or trusted automation.
$user = [
    'name'      => $args[1],
    'memory'    => (int) $args[2],
    'quota'     => (int) $args[3],
    'CPUWeight' => isset($args[5]) ? (int) $args[5] : 0,
    'IOWeight'  => isset($args[6]) ? (int) $args[6] : 0,
    'IOReadBW'    => isset($args[7]) ? $args[7] : null,
    'IOWriteBW'   => isset($args[8]) ? $args[8] : null,
    'IOReadIOPS'  => isset($args[9]) ? $args[9] : null,
    'IOWriteIOPS' => isset($args[10]) ? $args[10] : null,
    'cpuQuotaPercent' => isset($args[11]) ? $args[11] : 0,
    'trafficCapMbit' => isset($args[12]) ? (int) $args[12] : 0,
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

if (isset($args[4])) {
    $user['trafficLimit'] = (int) $args[4];
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

$presenceIndices = [
    'CPUWeight'       => 5,
    'IOWeight'        => 6,
    'IOReadBW'        => 7,
    'IOWriteBW'       => 8,
    'IOReadIOPS'      => 9,
    'IOWriteIOPS'     => 10,
    'cpuQuotaPercent' => 11,
    'trafficCapMbit'  => 12,
];
$presence = [];
foreach ($presenceIndices as $key => $index) {
    $presence[$key] = array_key_exists($index, $args);
}

$store = new UserConfigStore();
$existing = $store->get($user['name']) ?? [];

if ($welcomeOnlyMode) {
    $payload = $existing;
    $requiredBaselineKeys = ['ramMiB', 'rtorrentPort', 'quota', 'quotaBurst'];
    foreach ($requiredBaselineKeys as $requiredBaselineKey) {
        if (!isset($payload[$requiredBaselineKey]) || !is_numeric($payload[$requiredBaselineKey])) {
            fwrite(STDERR, "Error: missing existing {$requiredBaselineKey}; rerun full userConfig.php first.\n");
            exit(1);
        }
    }

    if (trim($welcomeMessage) === '') {
        unset($payload['welcomeMessage']);
    } else {
        $payload['welcomeMessage'] = $welcomeMessage;
    }

    if (!$store->set($user['name'], $payload)) {
        fwrite(STDERR, "Error: failed to persist user config for {$user['name']}\n");
        exit(1);
    }
    $store->writeUserCache($user['name'], $payload);
    exit(0);
}

$payload = $existing;
$payload['ramMiB'] = $user['memory'];
$payload['rtorrentPort'] = isset($existing['rtorrentPort']) ? (int) $existing['rtorrentPort'] : 0;
$payload['quota'] = $user['quota'];
$payload['quotaBurst'] = (int) round(((float) $user['quota']) * 1.25);
$payload['trafficLimit'] = 0;
$payloadMap = [
    'CPUWeight'       => 'CPUWeight',
    'IOWeight'        => 'IOWeight',
    'IOReadBW'        => 'IOReadBW',
    'IOWriteBW'       => 'IOWriteBW',
    'IOReadIOPS'      => 'IOReadIOPS',
    'IOWriteIOPS'     => 'IOWriteIOPS',
    'cpuQuotaPercent' => 'cpuQuotaPercent',
    'trafficCapMbit'  => 'trafficCapMbit',
];
foreach ($payloadMap as $key => $userKey) {
    if (!empty($presence[$key])) {
        $payload[$key] = $user[$userKey];
    }
}
if (!isset($payload['billingId'])) {
    $payload['billingId'] = 0;
}
if ($payload['billingId'] === 0) {
    $payload = $store->applyFallbacks($user['name'], $payload);
}

// Optional per-user welcome banner override for welcome.php.
if ($welcomeMessage !== null) {
    if (trim($welcomeMessage) === '') {
        unset($payload['welcomeMessage']);
    } else {
        $payload['welcomeMessage'] = $welcomeMessage;
    }
}

if (!$store->set($user['name'], $payload)) {
    fwrite(STDERR, "Warning: failed to persist user config for {$user['name']}\n");
} else {
    $store->writeUserCache($user['name'], $payload);
}

// Write optional torrent upload throttle before touching heavyweight services so limits
// persist even if later steps bail out.
if ($uploadThrottleKib !== null) {
    if ($uploadThrottleKib === '' || !is_numeric($uploadThrottleKib)) {
        die("Invalid --upload-throttle-kib value\n");
    }
    $throttleValue = (int) $uploadThrottleKib;
    if ($throttleValue < 0) {
        die("Upload throttle must be >= 0\n");
    }
    if (!pmssWriteTorrentThrottle($user['name'], $throttleValue)) {
        fwrite(STDERR, "Warning: failed to write torrent upload throttle for {$user['name']}\n");
    }
}

// Write optional traffic caps before touching heavyweight services so limits
// persist even if later steps bail out.
userApplyTrafficLimit($user);

// Compose a canonical rtorrent configuration and mirror it to companion apps.
echo "Creating rTorrent config\n";
$resources = [];
$resourceFile = '/etc/seedbox/config/system.rtorrent.resources';
if (file_exists($resourceFile)) {
    $resources = unserialize((string) file_get_contents($resourceFile));
}
$rtorrentConfig = new rtorrentConfig($resources);
$throttle = pmssReadTorrentThrottle($user['name']);
$configuration = $rtorrentConfig->createConfig([
    'ram' => $user['memory'],
    'dht' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.dht'),
    'pex' => file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.pex'),
    'uploadThrottle' => $throttle === null ? 0 : $throttle,
]);
$rtorrentConfig->writeConfig($user['name'], $configuration['configFile']);

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
echo "Changing ruTorrent config\n";
updateRutorrentConfig($user['name'], $scgiPort);
$rclonePortFile = sprintf('/home/%s/.rclonePort', $user['name']);
if (!file_exists($rclonePortFile)) {
    file_put_contents($rclonePortFile, rand(1500, 65500));
}
userConfigureDeluge($user, $configuration);
userConfigureQbittorrent($user);
userApplyDiskQuota($user);
$lockFile = sprintf('/home/%s/session/rtorrent.lock', $user['name']);
if (file_exists($lockFile)) {
    $pidChunk = explode(':+', (string)file_get_contents($lockFile));
    $pid = (int) $pidChunk;
    if ($pid > 0) {
        runStep('Restarting rTorrent', sprintf('kill -9 %d', $pid));
    }
}
if (file_exists('/bin/bash')) {
    runStep('Ensuring bash shell', sprintf('chsh -s /bin/bash %s', escapeshellarg($user['name'])));
}
userConfigureSystemdSlice($user);
userEnableLingerAndDocker($user);
