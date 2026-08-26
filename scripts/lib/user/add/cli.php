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
    $derivedDefault = pmssCliHelpDim(' (default: auto-derived from RAM when omitted)', $useColor);
    return pmssCliHelpSectionText([
        'Usage' => [
            '  addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [TRAFFIC_CAP_MBIT] [UPLOAD_THROTTLE_KIB]',
            '  addUser.php USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]',
            '  addUser.php --user=USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]',
        ],
        'Positional Parameters' => array_merge([
            pmssCliHelpLine('USERNAME', 'New PMSS username; lowercase [a-z][a-z0-9]{2,7}.'),
            pmssCliHelpLine('PASSWORD', 'Initial password; use rand to generate one automatically.'),
            pmssCliHelpLine('RAM_MiB', 'Account RAM target in MiB; forwarded as MemoryHigh with a 250 MiB floor.'),
            pmssCliHelpLine('DISK_QUOTA_GiB', 'Disk quota in GiB.'),
        ], pmssUserConfigCliResourceHelpLines('addUserPositionals', 'parameter'), [
            pmssCliHelpLine('UPLOAD_THROTTLE_KIB', 'Torrent upload throttle in KiB/s; 0 removes it.'),
        ]),
        'Named Options' => array_merge([
            pmssCliHelpLine('--user=USERNAME', 'Same as the first positional username.'),
            pmssCliHelpLine('--password=PASSWORD', 'Same as the second positional password.'),
            pmssCliHelpLine('--ram-mib=RAM_MiB', 'Same as the RAM positional argument.'),
            pmssCliHelpLine('--disk-quota-gib=DISK_QUOTA_GiB', 'Same as the disk quota positional argument.'),
            pmssCliHelpLine('--bonus-quota-gib=BONUS_QUOTA_GiB', 'Additional disk quota already included in the total disk quota.'),
        ], pmssUserConfigCliResourceHelpLines('addUserPrimaryOptions', 'usage'), [
            pmssCliHelpLine('--upload-throttle-kib=KIB', 'Persist torrent upload throttle in KiB/s; 0 removes it.'),
        ], pmssUserConfigCliResourceHelpLines('addUserAdvancedOptions', 'usage', ['CPUWeight' => $derivedDefault, 'IOWeight' => $derivedDefault]), [
            pmssCliHelpLine('--docker-enabled=true|false', 'Persist the initial rootless Docker policy.'),
            pmssCliHelpLine('-h, --help', 'Show this help and exit.'),
        ]),
        'Examples' => [
            '  /scripts/addUser.php alice rand 1024 200',
            '  /scripts/addUser.php alice --password=rand --ram-mib=1024 --disk-quota-gib=200 --io-weight=320',
            '  /scripts/addUser.php --user=alice --password=rand --ram-mib=1024 --disk-quota-gib=200 --traffic-limit-gb=500 --cpu-weight=320 --io-weight=320 --cpu-quota-percent=150 --io-latency-ms=50 --io-cost-qos="enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00" --upload-throttle-kib=2048 --docker-enabled=true',
        ],
        'Notes' => [
            '  - Named options override legacy positional values, so automation can skip intermediate optional slots safely.',
            '  - RAM_MiB is applied through userConfig.php and then userConfigCgroup.php; PMSS clamps the effective MemoryHigh floor to 250 MiB and derives MemoryMax at roughly 1.25x with at most 2048 MiB of headroom.',
            '  - If RAM_MiB is below 245 MiB, PMSS persists dockerEnabled=false for safety.',
        ],
    ], $useColor);
}

/** Parse the optional creation-time additional disk quota. */
function pmssAddUserBonusQuotaGiBParse($raw): ?int
{
    if ($raw === null) {
        return null;
    }
    if (!is_string($raw) || preg_match('/^(0|[1-9][0-9]*)$/D', $raw) !== 1) {
        throw new InvalidArgumentException('Invalid --bonus-quota-gib value');
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($value === false) {
        throw new InvalidArgumentException('Invalid --bonus-quota-gib value');
    }
    return (int) $value;
}

/**
 * Parse addUser CLI arguments into the canonical user payload.
 *
 * @return array<string,mixed>
 */
function pmssAddUserParseCli(array $argv): array
{
    $longOptions = array_merge(['user', 'password', 'ram-mib', 'disk-quota-gib', 'bonus-quota-gib', 'upload-throttle-kib', 'docker-enabled'], pmssUserConfigCliResourceOptionNames('addUserOption'));
    $parsed = pmssParseCliTokens($argv, $longOptions);
    if (pmssCliHelpRequested($parsed)) return ['help' => true, 'usage' => pmssAddUserCliUsage()];

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

    $bonusQuotaGiB = pmssAddUserBonusQuotaGiBParse(pmssCliOption($parsed, 'bonus-quota-gib', null, null));
    if ($bonusQuotaGiB !== null) {
        $user['bonusQuotaGiB'] = $bonusQuotaGiB;
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
