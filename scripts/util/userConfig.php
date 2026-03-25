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

foreach (['traffic', 'deluge', 'qbittorrent', 'userConfigStore'] as $module) {
    require_once __DIR__.'/../lib/user/'.$module.'.php';
}
require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/rtorrentConfig.php';
require_once __DIR__.'/../lib/update.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

/**
 * Main entry point for user configuration changes.
 */


$usage = 'Usage: ./userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [CPUWEIGHT] [IOWEIGHT] [IO_READ_BW] [IO_WRITE_BW] [IO_READ_IOPS] [IO_WRITE_IOPS] [CPU_QUOTA_PERCENT] [TRAFFIC_CAP_MBIT]';
$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []), ['upload-throttle-kib', 'welcome-message']);
$args = array_merge([''], $parsed['arguments']);
$uploadThrottleKib = pmssCliOption($parsed, 'upload-throttle-kib');
$uploadThrottleKib = ($uploadThrottleKib === true || $uploadThrottleKib === null) ? null : (string) $uploadThrottleKib;
$welcomeMessage = pmssCliOption($parsed, 'welcome-message');
$welcomeMessage = ($welcomeMessage === true || $welcomeMessage === null) ? null : (string) $welcomeMessage;
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

    if (trim((string) $welcomeMessage) === '') {
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
foreach (['CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit'] as $key) {
    if (!empty($presence[$key])) {
        $payload[$key] = $user[$key];
    }
}
$payload['billingId'] = $payload['billingId'] ?? 0;
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
$qbittorrentConfigDir = sprintf('/home/%s/.config/qBittorrent', $user['name']);
$qbittorrentConfigFile = $qbittorrentConfigDir.'/qBittorrent.conf';
if (!file_exists($qbittorrentConfigFile)) {
    $qbittorrentPort = (int) round(rand(1500, 65500));
    if (!file_exists($qbittorrentConfigDir)) {
        mkdir($qbittorrentConfigDir, 0770, true);
    }

    file_put_contents(
        $qbittorrentConfigFile,
        str_replace(
            ['##username', '##port', '##uploadThrottleLine'],
            [
                $user['name'],
                $qbittorrentPort,
                ($throttle !== null && $throttle > 0) ? 'Connection\\GlobalUPLimit='.(int) $throttle : '',
            ],
            file_get_contents('/etc/seedbox/config/template.qbittorrent.conf')
        )
    );
    file_put_contents(sprintf('/home/%s/.qbittorrentPort', $user['name']), $qbittorrentPort);
}
pmssQbittorrentApplyUploadThrottle($user['name'], $throttle);
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

// Delegate cgroup configuration to the dedicated utility.
// This ensures v1/v2 compatibility and automatic weight calculation.
$args = [
    '/scripts/util/userConfigCgroup.php',
    $user['name'],
    '--apply',
    '--memory-high=' . $user['memory'],
];

if (!empty($user['CPUWeight']) && $user['CPUWeight'] > 0) {
    $args[] = '--cpu-weight=' . $user['CPUWeight'];
}
if (!empty($user['IOWeight']) && $user['IOWeight'] > 0) {
    $args[] = '--io-weight=' . $user['IOWeight'];
}

// Optional I/O throttles
$ioArgs = [
    'IOReadBW'    => '--io-read-bw=',
    'IOWriteBW'   => '--io-write-bw=',
    'IOReadIOPS'  => '--io-read-iops=',
    'IOWriteIOPS' => '--io-write-iops=',
];
foreach ($ioArgs as $key => $flag) {
    if (!empty($user[$key])) {
        $args[] = $flag.$user[$key];
    }
}
if (isset($user['cpuQuotaPercent']) && $user['cpuQuotaPercent'] !== '') {
    $quotaVal = $user['cpuQuotaPercent'];
    $quotaLabel = (is_string($quotaVal) && strtolower((string) $quotaVal) === 'infinity')
        ? 'infinity'
        : $quotaVal.'%';
    echo 'Applying CPU quota: '.$quotaLabel."\n";
    $args[] = '--cpu-quota-percent=' . $quotaVal;
}

runStep(
    'Configuring cgroups',
    pmssBuildCommand('php', $args)
);

if (function_exists('pmssUserDockerEnabled') && !pmssUserDockerEnabled($user['name'], $store)) {
    pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.$user['name']);
} else {
    runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg($user['name'])));
    runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');
    runStep(
        'Configuring rootless Docker',
        sprintf('machinectl shell %1$s@ /usr/bin/dockerd-rootless-setuptool.sh install', escapeshellarg($user['name']))
    );
}
