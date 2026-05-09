<?php
/**
 * addUser CLI parsing helpers.
 *
 * Keeps the public wrapper focused on orchestration while this module owns the
 * user-facing CLI contract, help output, and backwards-compatible parsing of
 * legacy positional arguments.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../cli/optionParser.php';
require_once __DIR__.'/../userConfigCli.php';

/**
 * Return the canonical addUser CLI help text.
 */
function pmssAddUserCliUsage(): string
{
    $useColor = pmssCliHelpSupportsColor();
    $resourceSpecs = pmssUserConfigCliResourceSpecs();
    $resourceHelp = pmssUserConfigCliResourceHelpSpecs();
    $derivedDefault = pmssCliHelpDim(' (default: auto-derived from RAM when omitted)', $useColor);
    $lines = [
        pmssCliHelpHeading('Usage', $useColor),
        '  addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [TRAFFIC_CAP_MBIT] [UPLOAD_THROTTLE_KIB]',
        '  addUser.php USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]',
        '  addUser.php --user=USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]',
        '',
        pmssCliHelpHeading('Positional Parameters', $useColor),
        pmssCliHelpLine('USERNAME', 'New PMSS username; lowercase [a-z][a-z0-9]{2,7}.'),
        pmssCliHelpLine('PASSWORD', 'Initial password; use rand to generate one automatically.'),
        pmssCliHelpLine('RAM_MiB', 'Account RAM target in MiB; forwarded as MemoryHigh with a 250 MiB floor.'),
        pmssCliHelpLine('DISK_QUOTA_GiB', 'Disk quota in GiB.'),
        pmssCliHelpLine($resourceHelp['trafficLimit']['parameter'], $resourceHelp['trafficLimit']['parameterDescription']),
        pmssCliHelpLine($resourceHelp['trafficCapMbit']['parameter'], $resourceHelp['trafficCapMbit']['parameterDescription']),
        pmssCliHelpLine('UPLOAD_THROTTLE_KIB', 'Torrent upload throttle in KiB/s; 0 removes it.'),
        '',
        pmssCliHelpHeading('Named Options', $useColor),
        pmssCliHelpLine('--user=USERNAME', 'Same as the first positional username.'),
        pmssCliHelpLine('--password=PASSWORD', 'Same as the second positional password.'),
        pmssCliHelpLine('--ram-mib=RAM_MiB', 'Same as the RAM positional argument.'),
        pmssCliHelpLine('--disk-quota-gib=DISK_QUOTA_GiB', 'Same as the disk quota positional argument.'),
        pmssCliHelpLine($resourceSpecs['trafficLimit']['usage'], $resourceHelp['trafficLimit']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['iopsLimit']['usage'], $resourceHelp['iopsLimit']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['trafficCapMbit']['usage'], $resourceHelp['trafficCapMbit']['optionDescription']),
        pmssCliHelpLine('--upload-throttle-kib=KIB', 'Persist torrent upload throttle in KiB/s; 0 removes it.'),
        pmssCliHelpLine($resourceSpecs['CPUWeight']['usage'], $resourceHelp['CPUWeight']['optionDescription'].$derivedDefault),
        pmssCliHelpLine($resourceSpecs['IOWeight']['usage'], $resourceHelp['IOWeight']['optionDescription'].$derivedDefault),
        pmssCliHelpLine($resourceSpecs['IOReadBW']['usage'], $resourceHelp['IOReadBW']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['IOWriteBW']['usage'], $resourceHelp['IOWriteBW']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['IOReadIOPS']['usage'], $resourceHelp['IOReadIOPS']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['IOWriteIOPS']['usage'], $resourceHelp['IOWriteIOPS']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['cpuQuotaPercent']['usage'], $resourceHelp['cpuQuotaPercent']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['ioLatencyMs']['usage'], $resourceHelp['ioLatencyMs']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['ioCostQos']['usage'], $resourceHelp['ioCostQos']['optionDescription']),
        pmssCliHelpLine($resourceSpecs['ioCostModel']['usage'], $resourceHelp['ioCostModel']['optionDescription']),
        pmssCliHelpLine('--docker-enabled=true|false', 'Persist the initial rootless Docker policy.'),
        pmssCliHelpLine('-h, --help', 'Show this help and exit.'),
        '',
        pmssCliHelpHeading('Examples', $useColor),
        '  /scripts/addUser.php alice rand 1024 200',
        '  /scripts/addUser.php alice --password=rand --ram-mib=1024 --disk-quota-gib=200 --io-weight=320',
        '  /scripts/addUser.php --user=alice --password=rand --ram-mib=1024 --disk-quota-gib=200 --traffic-limit-gb=500 --cpu-weight=320 --io-weight=320 --cpu-quota-percent=150 --io-latency-ms=50 --io-cost-qos="enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00" --upload-throttle-kib=2048 --docker-enabled=true',
        '',
        pmssCliHelpHeading('Notes', $useColor),
        '  - Named options override legacy positional values, so automation can skip intermediate optional slots safely.',
        '  - RAM_MiB is applied through userConfig.php and then userConfigCgroup.php; PMSS clamps the effective MemoryHigh floor to 250 MiB and derives MemoryMax at roughly 1.25x with at most 2048 MiB of headroom.',
        '  - If RAM_MiB is below 245 MiB, PMSS persists dockerEnabled=false for safety.',
    ];

    return implode("\n", $lines);
}

/**
 * Parse addUser CLI arguments into the canonical user payload.
 *
 * @return array<string,mixed>
 */
function pmssAddUserParseCli(array $argv): array
{
    $longOptions = array_merge(['user', 'password', 'ram-mib', 'disk-quota-gib', 'upload-throttle-kib', 'docker-enabled'], array_column(pmssUserConfigCliResourceSpecs(), 'addUserOption'));
    $parsed = pmssParseCliTokens($argv, $longOptions);
    if (pmssCliOption($parsed, 'help', 'h', false) !== false) {
        return ['help' => true, 'usage' => pmssAddUserCliUsage()];
    }

    $args = array_merge([''], $parsed['arguments']);
    $user = [
        'name' => pmssUserConfigCliLegacyValue($parsed, 'user', $args, 1, ''),
        'password' => pmssUserConfigCliLegacyValue($parsed, 'password', $args, 2, ''),
        'memory' => pmssUserConfigCliLegacyValue($parsed, 'ram-mib', $args, 3, ''),
        'quota' => pmssUserConfigCliLegacyValue($parsed, 'disk-quota-gib', $args, 4, ''),
    ];

    if ($user['name'] === '' || $user['password'] === '' || $user['memory'] === '' || $user['quota'] === '') {
        throw new InvalidArgumentException(pmssAddUserCliUsage());
    }

    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        $value = pmssUserConfigCliLegacyValue($parsed, $spec['addUserOption'], $args, isset($spec['addUserLegacyIndex']) ? (int) $spec['addUserLegacyIndex'] : -1, null);
        if ($value !== null && $value !== '') {
            $user[$key] = $value;
        }
    }
    $torrentThrottle = pmssUserConfigCliParseUploadThrottleOption(
        pmssUserConfigCliLegacyValue($parsed, 'upload-throttle-kib', $args, 7, null),
        'Invalid upload throttle value'
    );
    if ($torrentThrottle !== null) {
        $user['torrentThrottle'] = $torrentThrottle;
    }
    $dockerEnabled = pmssUserConfigParseDockerEnabledOption(pmssCliOption($parsed, 'docker-enabled', null, null));
    if ($dockerEnabled !== null) {
        $user['dockerEnabled'] = $dockerEnabled ? 'true' : 'false';
    }

    if ($user['password'] === 'rand') {
        $user['password'] = '';
    }

    return [
        'help' => false,
        'usage' => pmssAddUserCliUsage(),
        'user' => $user,
    ];
}
