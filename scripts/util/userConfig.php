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

foreach (['traffic', 'iopsLimit', 'deluge', 'qbittorrent', 'userConfigStore'] as $module) {
    require_once __DIR__.'/../lib/user/'.$module.'.php';
}
require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/user/userConfigCli.php';
require_once __DIR__.'/../lib/user/userConfigRuntime.php';
require_once __DIR__.'/../lib/rtorrentConfig.php';
require_once __DIR__.'/../lib/rutorrent/config.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/welcomeMessage.php';

/**
 * Main entry point for user configuration changes.
 */
$usage = pmssUserConfigCliUsage();
$resourceOptions = pmssUserConfigCliResourceOptionNames('addUserOption');
$parsed = pmssParseCliTokens(
    $argv ?? ($_SERVER['argv'] ?? []),
    array_merge(['upload-throttle-kib', 'welcome-message', 'docker-enabled'], $resourceOptions)
);
$helpRequested = pmssCliOption($parsed, 'help', 'h', false) !== false;
$args = array_merge([''], $parsed['arguments']);
if ($helpRequested) {
    echo $usage."\n";
    exit(0);
}
$welcomeMessage = pmssCliOption($parsed, 'welcome-message');
$welcomeMessage = ($welcomeMessage === true || $welcomeMessage === null) ? null : (string) $welcomeMessage;
try {
    $uploadThrottleKib = pmssUserConfigCliParseUploadThrottleOption(pmssCliOption($parsed, 'upload-throttle-kib'));
    $dockerEnabled = pmssUserConfigParseDockerEnabledOption(pmssCliOption($parsed, 'docker-enabled'));
} catch (InvalidArgumentException $exception) {
    die($exception->getMessage()."\n");
}
$explicitResourceOverrides = pmssUserConfigCliExplicitResources($parsed, $args, 'addUserOption', 'userConfigIndex');
$namedConfigChange = $uploadThrottleKib !== null
    || $dockerEnabled !== null
    || count($explicitResourceOverrides) > 0;
$namedConfigMode = !empty($args[1])
    && empty($args[2])
    && empty($args[3])
    && $namedConfigChange;
$fullConfigMode = !empty($args[1]) && !empty($args[2]) && !empty($args[3]);
$welcomeOnlyMode = !empty($args[1])
    && $welcomeMessage !== null
    && empty($args[2])
    && empty($args[3])
    && !$namedConfigMode;
if (!$fullConfigMode && !$welcomeOnlyMode && !$namedConfigMode) {
    die($usage."\n");
}

// The $user array is populated from sanitized command-line arguments ($args)
// provided by an operator or trusted automation.
$user = [
    'name'      => $args[1],
    'memory'    => (int) $args[2],
    'quota'     => (int) $args[3],
];
$user = array_merge($user, pmssUserConfigCliResolvedResources($parsed, $args, 'addUserOption', 'userConfigIndex'));
$user['name'] = pmssNormalizeUsername((string) $user['name']);

$passwdEntry = pmssPasswdEntryLookup($user['name']);
if ($passwdEntry === null) {
    die("No such user in passwd list\n");
}

$account = pmssUserAccountLookup($user['name']) ?? $passwdEntry;
$user['id'] = (int) ($account['uid'] ?? 0);

if ($user['id'] < 1000) {
    die("No system ID or user does not exist\n");
}
$expectedHome = "/home/{$user['name']}";
$accountHome = rtrim((string) ($passwdEntry['dir'] ?? ($account['dir'] ?? '')), '/');
if ($accountHome !== $expectedHome || !file_exists($expectedHome)) {
    die("User does not exist\n");
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

    if (!pmssWelcomeUserMessageSet($user['name'], $expectedHome, $welcomeMessage)) {
        fwrite(STDERR, "Error: failed to persist welcome message for {$user['name']}\n");
        exit(1);
    }
    unset($payload['welcomeMessage']);
    if (!$store->persist($user['name'], $payload)) {
        fwrite(STDERR, "Error: failed to persist user config for {$user['name']}\n");
        exit(1);
    }
    exit(0);
}

if ($namedConfigMode) {
    foreach (['ramMiB', 'quota'] as $requiredBaselineKey) {
        if (!isset($existing[$requiredBaselineKey]) || !is_numeric($existing[$requiredBaselineKey])) {
            fwrite(STDERR, "Error: missing existing {$requiredBaselineKey}; rerun full userConfig.php first.\n");
            exit(1);
        }
    }

    $user['memory'] = (int) $existing['ramMiB'];
    $user['quota'] = (int) $existing['quota'];
    $user = array_merge($user, pmssUserConfigCliPersistedStoredResources($existing));
    $user = array_merge($user, $explicitResourceOverrides);
}

$presence = array_fill_keys(array_keys($explicitResourceOverrides), true);

$payload = $existing;
$payload['ramMiB'] = $user['memory'];
$payload['rtorrentPort'] = isset($existing['rtorrentPort']) ? (int) $existing['rtorrentPort'] : 0;
$payload['quota'] = $user['quota'];
$payload['quotaBurst'] = (int) round(((float) $user['quota']) * 1.25);
$payload['trafficLimit'] = 0;
$payload = pmssUserConfigCliApplyPersistedResources($payload, $user, $presence);
$payload['billingId'] = $payload['billingId'] ?? 0;
if ($payload['billingId'] === 0) {
    $payload = $store->applyFallbacks($user['name'], $payload);
}
if ($dockerEnabled !== null) {
    $payload['dockerEnabled'] = $dockerEnabled;
}
if ($welcomeMessage !== null) {
    if (!pmssWelcomeUserMessageSet($user['name'], $expectedHome, $welcomeMessage)) {
        fwrite(STDERR, "Warning: failed to persist welcome message for {$user['name']}\n");
    }
    unset($payload['welcomeMessage']);
}

if (!$store->persist($user['name'], $payload)) {
    fwrite(STDERR, "Warning: failed to persist user config for {$user['name']}\n");
}
// Write optional torrent upload throttle before touching heavyweight services so limits
// persist even if later steps bail out.
if ($uploadThrottleKib !== null) {
    if (!pmssWriteTorrentThrottle($user['name'], $uploadThrottleKib)) {
        fwrite(STDERR, "Warning: failed to write torrent upload throttle for {$user['name']}\n");
    }
}

// Write optional traffic caps before touching heavyweight services so limits
// persist even if later steps bail out.
userApplyTrafficLimit($user);
if (array_key_exists('iopsLimit', $user) && $user['iopsLimit'] !== null) {
    $targetModes = pmssIopsLimitTargetModes($user['name'], dirname($expectedHome));
    $runtimePath = array_key_first($targetModes);
    $persistError = null;
    if (!is_string($runtimePath)
        || $runtimePath === ''
        || !pmssIntegerSettingStorageDirEnsure(dirname($runtimePath), 0700)
        || !pmssIntegerSettingTargetModesPersist($targetModes, (int) $user['iopsLimit'], $persistError, 'invalid operations value')
    ) {
        fwrite(STDERR, "Warning: failed to persist IOPS limit for {$user['name']}: ".($persistError ?: 'unable to prepare runtime targets')."\n");
    }
}

// Compose a canonical rtorrent configuration and mirror it to companion apps.
echo "Creating rTorrent config\n";
try {
    $resources = pmssReadOptionalSerializedArrayFile('/etc/seedbox/config/system.rtorrent.resources', 'rTorrent resource configuration');
    $rtorrentConfig = new rtorrentConfig($resources);
    $throttle = pmssReadTorrentThrottle($user['name']);
    $configuration = $rtorrentConfig->createConfig([
        'ram' => $user['memory'],
        'dht' => pmssReadRequiredRegularFile('/etc/seedbox/config/user.rtorrent.defaults.dht', 'rTorrent DHT defaults'),
        'pex' => pmssReadRequiredRegularFile('/etc/seedbox/config/user.rtorrent.defaults.pex', 'rTorrent PEX defaults'),
        'uploadThrottle' => $throttle === null ? 0 : $throttle,
    ]);
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Error: '.$exception->getMessage()."\n");
    exit(1);
}
$rtorrentConfig->writeConfig($user['name'], $configuration['configFile']);

// Persist derived ports once rTorrent config is generated so they survive
// re-runs and other tooling can read a single source of truth.
$scgiPort = (int) ($configuration['config']['scgiPort'] ?? 0);
if ($scgiPort > 0 && (!isset($payload['rtorrentPort']) || (int) $payload['rtorrentPort'] !== $scgiPort)) {
    $payload['rtorrentPort'] = $scgiPort;
    if (!$store->persist($user['name'], $payload)) {
        fwrite(STDERR, "Warning: failed to persist rtorrentPort for {$user['name']}\n");
    }
}
echo "Changing ruTorrent config\n";
updateRutorrentConfig($user['name'], $scgiPort);
$rclonePortFile = sprintf('/home/%s/.rclonePort', $user['name']);
if (!file_exists($rclonePortFile)) {
    if (@file_put_contents($rclonePortFile, (string) rand(1500, 65500)) === false) {
        fwrite(STDERR, "Warning: failed to write rclone port for {$user['name']}\n");
    }
}
userConfigureDeluge($user, $configuration);
$qbittorrentConfigDir = sprintf('/home/%s/.config/qBittorrent', $user['name']);
$qbittorrentConfigFile = $qbittorrentConfigDir.'/qBittorrent.conf';
if (!file_exists($qbittorrentConfigFile)) {
    $qbittorrentPort = (int) round(rand(1500, 65500));
    if (!is_dir($qbittorrentConfigDir)
        && !@mkdir($qbittorrentConfigDir, 0770, true)
        && !is_dir($qbittorrentConfigDir)
    ) {
        fwrite(STDERR, "Warning: failed to create qBittorrent config directory for {$user['name']}\n");
    }

    try {
        $qbittorrentTemplate = pmssReadRequiredRegularFile('/etc/seedbox/config/template.qbittorrent.conf', 'qBittorrent template');
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Error: '.$exception->getMessage()."\n");
        exit(1);
    }

    $qbittorrentConfig = str_replace(
        ['##username', '##port', '##uploadThrottleLine'],
        [
            $user['name'],
            $qbittorrentPort,
            ($throttle !== null && $throttle > 0) ? 'Connection\\GlobalUPLimit='.(int) $throttle : '',
        ],
        $qbittorrentTemplate
    );
    if (@file_put_contents($qbittorrentConfigFile, $qbittorrentConfig) === false) {
        fwrite(STDERR, "Warning: failed to write qBittorrent config for {$user['name']}\n");
    }
    if (@file_put_contents(sprintf('/home/%s/.qbittorrentPort', $user['name']), (string) $qbittorrentPort) === false) {
        fwrite(STDERR, "Warning: failed to write qBittorrent port for {$user['name']}\n");
    }
}
pmssQbittorrentApplyUploadThrottle($user['name'], $throttle);
userApplyDiskQuota($user);
$lockFile = sprintf('/home/%s/session/rtorrent.lock', $user['name']);
if (file_exists($lockFile)) {
    $pid = pmssUserConfigRtorrentLockPid($lockFile);
    if ($pid !== null && pmssUserConfigRtorrentProcessOwnedBy($pid, $user['id'])) {
        runStep('Restarting rTorrent', sprintf('kill -9 %d', $pid));
    } elseif ($pid !== null) {
        fwrite(STDERR, "Warning: refusing to restart rTorrent for {$user['name']}: lock PID is not an owned rTorrent process\n");
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

// Optional I/O throttles
$args = array_merge($args, pmssUserConfigCliBuildCgroupResourceArgs($user));
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

if (!pmssUserDockerEnabled($user['name'], $store)) {
    pmssLogStatus('SKIP', 'Rootless Docker disabled by config for '.$user['name']);
} else {
    runStep('Enabling linger for user', sprintf('loginctl enable-linger %s', escapeshellarg($user['name'])));
    runStep('Installing systemd-container tools', 'apt-get install -y systemd-container');
    runStep(
        'Configuring rootless Docker',
        sprintf('machinectl shell %1$s@ /usr/bin/dockerd-rootless-setuptool.sh install', escapeshellarg($user['name']))
    );
}
