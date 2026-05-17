<?php
/**
 * Canonical CLI metadata for per-user configuration resources.
 *
 * addUser.php and userConfig.php share the same resource knobs but expose them
 * through different legacy positional layouts. Keep the mapping in one place so
 * wrappers and reconfiguration stay in lockstep.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../cli/helpText.php';

/** @return array<string,array<string,mixed>> Shared resource option specification. */
function pmssUserConfigCliResourceSpecs(): array
{
    return [
        'trafficLimit' => ['addUserOption' => 'traffic-limit-gb', 'addUserLegacyIndex' => 5, 'userConfigIndex' => 4, 'usage' => '--traffic-limit-gb=GIB', 'parse' => 'int', 'default' => null, 'persist' => false],
        'iopsLimit' => ['addUserOption' => 'iops-limit', 'usage' => '--iops-limit=OPS', 'parse' => 'int', 'default' => null, 'persist' => false],
        'trafficCapMbit' => ['addUserOption' => 'traffic-cap-mbit', 'addUserLegacyIndex' => 6, 'userConfigIndex' => 12, 'usage' => '--traffic-cap-mbit=MBIT', 'parse' => 'int', 'default' => 0, 'persist' => true],
        'CPUWeight' => ['addUserOption' => 'cpu-weight', 'addUserLegacyIndex' => 8, 'userConfigIndex' => 5, 'usage' => '--cpu-weight=WEIGHT', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--cpu-weight='],
        'IOWeight' => ['addUserOption' => 'io-weight', 'addUserLegacyIndex' => 9, 'userConfigIndex' => 6, 'usage' => '--io-weight=WEIGHT', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--io-weight='],
        'IOReadBW' => ['addUserOption' => 'io-read-bw', 'addUserLegacyIndex' => 10, 'userConfigIndex' => 7, 'usage' => '--io-read-bw=/dev/DEVICE:RATE', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-read-bw='],
        'IOWriteBW' => ['addUserOption' => 'io-write-bw', 'addUserLegacyIndex' => 11, 'userConfigIndex' => 8, 'usage' => '--io-write-bw=/dev/DEVICE:RATE', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-write-bw='],
        'IOReadIOPS' => ['addUserOption' => 'io-read-iops', 'addUserLegacyIndex' => 12, 'userConfigIndex' => 9, 'usage' => '--io-read-iops=/dev/DEVICE:IOPS', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-read-iops='],
        'IOWriteIOPS' => ['addUserOption' => 'io-write-iops', 'addUserLegacyIndex' => 13, 'userConfigIndex' => 10, 'usage' => '--io-write-iops=/dev/DEVICE:IOPS', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-write-iops='],
        'cpuQuotaPercent' => ['addUserOption' => 'cpu-quota-percent', 'addUserLegacyIndex' => 14, 'userConfigIndex' => 11, 'usage' => '--cpu-quota-percent=PERCENT|infinity', 'parse' => 'string', 'default' => 0, 'persist' => true],
        'ioLatencyMs' => ['addUserOption' => 'io-latency-ms', 'addUserLegacyIndex' => 15, 'userConfigIndex' => 13, 'usage' => '--io-latency-ms=MS', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--io-latency-ms='],
        'ioCostQos' => ['addUserOption' => 'io-cost-qos', 'addUserLegacyIndex' => 16, 'userConfigIndex' => 14, 'usage' => '--io-cost-qos=SETTING', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-cost-qos='],
        'ioCostModel' => ['addUserOption' => 'io-cost-model', 'addUserLegacyIndex' => 17, 'userConfigIndex' => 15, 'usage' => '--io-cost-model=SETTING', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-cost-model='],
    ];
}

/** Prefer an explicit long option over a legacy positional slot. */
function pmssUserConfigCliLegacyValue(array $parsed, string $option, array $args, int $legacyIndex, $default = null)
{
    $value = pmssCliOption($parsed, $option, null, null);
    return ($value !== null && $value !== true) ? $value : (array_key_exists($legacyIndex, $args) ? $args[$legacyIndex] : $default);
}

/** @return array<int,string> List shared long-option names for resource flags. */
function pmssUserConfigCliResourceOptionNames(string $optionKey): array
{
    return array_values(array_filter(array_map('strval', array_column(pmssUserConfigCliResourceSpecs(), $optionKey)), 'strlen'));
}

/** Check whether a parsed long option carries an explicit scalar value. */
function pmssUserConfigCliHasExplicitOptionValue(array $parsed, string $option): bool
{
    if (!array_key_exists($option, $parsed['options'])) {
        return false;
    }

    $value = $parsed['options'][$option];
    return $value !== null && $value !== true && $value !== '';
}

/** @return array<string,mixed> Parse resource values with named options overriding positional slots. */
function pmssUserConfigCliResolvedResources(array $parsed, array $args, string $optionKey, string $indexKey): array
{
    $values = [];
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        $legacyIndex = isset($spec[$indexKey]) ? (int) $spec[$indexKey] : -1;
        $value = pmssUserConfigCliLegacyValue($parsed, $spec[$optionKey], $args, $legacyIndex, $spec['default']);
        $values[$key] = ($spec['parse'] === 'int' && $value !== null) ? (int) $value : $value;
    }
    return $values;
}

/** @return array<string,mixed> Parse only explicitly provided resource values. */
function pmssUserConfigCliExplicitResources(array $parsed, array $args, string $optionKey, string $indexKey): array
{
    $values = [];
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        $value = null;
        if (pmssUserConfigCliHasExplicitOptionValue($parsed, $spec[$optionKey])) {
            $value = $parsed['options'][$spec[$optionKey]];
        } elseif (isset($spec[$indexKey]) && array_key_exists($spec[$indexKey], $args) && $args[$spec[$indexKey]] !== '') {
            $value = $args[$spec[$indexKey]];
        }
        if ($value !== null) {
            $values[$key] = ($spec['parse'] === 'int') ? (int) $value : $value;
        }
    }
    return $values;
}

/** Parse the optional Docker toggle while keeping legacy CLI semantics. */
function pmssUserConfigParseDockerEnabledOption($rawOption): ?bool
{
    if ($rawOption === null) {
        return null;
    }
    if ($rawOption === true || $rawOption === '') {
        throw new InvalidArgumentException('--docker-enabled requires true or false');
    }
    if (pmssValueMatchesNormalized($rawOption, ['true', '1', 'yes', 'on'])) return true;
    if (pmssValueMatchesNormalized($rawOption, ['false', '0', 'no', 'off'])) return false;
    throw new InvalidArgumentException('Invalid --docker-enabled value');
}

/** Parse the optional torrent throttle while ignoring legacy empty flags. */
function pmssUserConfigCliParseUploadThrottleOption($rawOption, string $negativeMessage = 'Upload throttle must be >= 0'): ?int
{
    if ($rawOption === null || $rawOption === true || $rawOption === '') {
        return null;
    }
    if (!is_numeric($rawOption)) {
        throw new InvalidArgumentException('Invalid --upload-throttle-kib value');
    }
    $value = (int) $rawOption;
    if ($value < 0) {
        throw new InvalidArgumentException($negativeMessage);
    }
    return $value;
}

/** @param array<int,mixed> $args @return array<string,mixed> Parse positional resources. */
function pmssUserConfigCliPositionalResources(array $args, string $indexKey): array
{
    return pmssUserConfigCliResolvedResources(['options' => []], $args, 'addUserOption', $indexKey);
}

/** @param array<int,mixed> $args @return array<string,bool> Track explicit persisted resources. */
function pmssUserConfigCliPersistedPositionalPresence(array $args): array
{
    return pmssUserConfigCliPersistedResourcePresence(['options' => []], $args, 'addUserOption', 'userConfigIndex');
}

/** @return array<string,bool> Track explicit persisted resources from named options or positionals. */
function pmssUserConfigCliPersistedResourcePresence(array $parsed, array $args, string $optionKey, string $indexKey): array
{
    $presence = [];
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        if (empty($spec['persist'])) {
            continue;
        }

        $presence[$key] = pmssUserConfigCliHasExplicitOptionValue($parsed, $spec[$optionKey])
            || (isset($spec[$indexKey]) && array_key_exists($spec[$indexKey], $args) && $args[$spec[$indexKey]] !== '');
    }
    return $presence;
}

/** @return array<string,mixed> Copy persisted shared resources from stored payloads. */
function pmssUserConfigCliPersistedStoredResources(array $payload): array
{
    $values = [];
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        if (empty($spec['persist']) || !array_key_exists($key, $payload)) {
            continue;
        }
        $values[$key] = $payload[$key];
    }
    return $values;
}

/** @return array<string,mixed> Copy explicit persisted resources into a payload. */
function pmssUserConfigCliApplyPersistedResources(array $payload, array $user, array $presence): array
{
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        if (empty($spec['persist'])) {
            continue;
        }
        if (!empty($presence[$key]) && array_key_exists($key, $user)) {
            $payload[$key] = $user[$key];
        }
    }
    return $payload;
}

/** @return array<string,mixed> Remove the legacy embedded welcome banner from config. */
function pmssUserConfigClearWelcomeMessage(array $payload): array
{
    unset($payload['welcomeMessage']);
    return $payload;
}

/** @return array<int,string> Render sparse userConfig positionals. */
function pmssUserConfigCliBuildUserConfigPositionals(array $user): array
{
    $optionalArgs = array_fill(4, 9, '');
    $lastOptionalIndex = 3;
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        if (!isset($spec['userConfigIndex'])) {
            continue;
        }
        $index = $spec['userConfigIndex'];
        $optionalArgs[$index] = array_key_exists($key, $user) ? (string) $user[$key] : '';
        if ($optionalArgs[$index] !== '') {
            $lastOptionalIndex = $index;
        }
    }
    return array_slice($optionalArgs, 0, max(0, $lastOptionalIndex - 3));
}

/** @return array<int,string> Translate shared resource keys into cgroup flags. */
function pmssUserConfigCliBuildCgroupResourceArgs(array $user): array
{
    $args = [];
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        if (!empty($spec['cgroupFlag']) && !empty($user[$key])) {
            $args[] = $spec['cgroupFlag'].$user[$key];
        }
    }
    return $args;
}

/** @return array<string,array<string,string>> Human-facing descriptions for shared resource knobs. */
function pmssUserConfigCliResourceHelpSpecs(): array
{
    return [
        'trafficLimit' => [
            'parameter' => 'TRAFFIC_LIMIT_GB',
            'description' => 'Monthly traffic quota in GiB.',
        ],
        'iopsLimit' => [
            'parameter' => 'IOPS_LIMIT',
            'description' => 'Monthly combined read+write I/O operations budget.',
        ],
        'trafficCapMbit' => [
            'parameter' => 'TRAFFIC_CAP_MBIT',
            'description' => 'Traffic shaper ceiling in Mbit/s; 0 disables shaping.',
        ],
        'CPUWeight' => [
            'parameter' => 'CPUWEIGHT',
            'description' => 'systemd CPUWeight; systemd expects 1-10000.',
        ],
        'IOWeight' => [
            'parameter' => 'IOWEIGHT',
            'description' => 'systemd IOWeight; systemd expects 1-10000.',
        ],
        'IOReadBW' => [
            'parameter' => 'IO_READ_BW',
            'description' => 'Read bandwidth cap in /dev/DEVICE:RATE form.',
        ],
        'IOWriteBW' => [
            'parameter' => 'IO_WRITE_BW',
            'description' => 'Write bandwidth cap in /dev/DEVICE:RATE form.',
        ],
        'IOReadIOPS' => [
            'parameter' => 'IO_READ_IOPS',
            'description' => 'Read IOPS cap in /dev/DEVICE:IOPS form.',
        ],
        'IOWriteIOPS' => [
            'parameter' => 'IO_WRITE_IOPS',
            'description' => 'Write IOPS cap in /dev/DEVICE:IOPS form.',
        ],
        'cpuQuotaPercent' => [
            'parameter' => 'CPU_QUOTA_PERCENT',
            'description' => 'CPU quota percent; use infinity to remove the limit.',
        ],
        'ioLatencyMs' => [
            'parameter' => 'IO_LATENCY_MS',
            'description' => 'IODeviceLatencyTargetSec target in milliseconds; defaults to the /home backing device.',
        ],
        'ioCostQos' => [
            'parameter' => 'IO_COST_QOS',
            'description' => 'io.cost.qos nested keys; defaults to the /home backing device major:minor.',
        ],
        'ioCostModel' => [
            'parameter' => 'IO_COST_MODEL',
            'description' => 'io.cost.model nested keys; defaults to the /home backing device major:minor.',
        ],
    ];
}

/** Render the canonical userConfig.php help output. */
function pmssUserConfigCliUsage(): string
{
    $useColor = pmssCliHelpSupportsColor();
    $resourceHelp = pmssUserConfigCliResourceHelpSpecs();
    $resourceSpecs = pmssUserConfigCliResourceSpecs();
    $derivedDefault = pmssCliHelpDim(' (default: auto-derived from RAM when omitted)', $useColor);
    $unchangedDefault = pmssCliHelpDim(' (default: leave current slice policy unchanged)', $useColor);
    $resourceDescriptionSuffixes = [
        'CPUWeight' => $derivedDefault,
        'IOWeight' => $derivedDefault,
        'cpuQuotaPercent' => $unchangedDefault,
    ];
    $lines = [
        pmssCliHelpHeading('Usage', $useColor),
        '  ./userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [CPUWEIGHT] [IOWEIGHT] [IO_READ_BW] [IO_WRITE_BW] [IO_READ_IOPS] [IO_WRITE_IOPS] [CPU_QUOTA_PERCENT] [TRAFFIC_CAP_MBIT] [IO_LATENCY_MS] [IO_COST_QOS] [IO_COST_MODEL]',
        '  ./userConfig.php USERNAME [RESOURCE_OPTIONS]',
        '  ./userConfig.php USERNAME --welcome-message=HTML',
        '',
        pmssCliHelpHeading('Positional Parameters', $useColor),
        pmssCliHelpLine('USERNAME', 'Existing PMSS username; lowercase [a-z][a-z0-9]{2,7}.'),
        pmssCliHelpLine('RAM_MiB', 'Account RAM target in MiB; forwarded as MemoryHigh with a 250 MiB floor.'),
        pmssCliHelpLine('DISK_QUOTA_GiB', 'Disk quota in GiB.'),
    ];

    foreach (['trafficLimit', 'CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit', 'ioLatencyMs', 'ioCostQos', 'ioCostModel'] as $key) {
        $lines[] = pmssCliHelpLine($resourceHelp[$key]['parameter'], $resourceHelp[$key]['description'].($resourceDescriptionSuffixes[$key] ?? ''));
    }

    $lines[] = '';
    $lines[] = pmssCliHelpHeading('Named Options', $useColor);
    foreach (['trafficLimit', 'iopsLimit', 'CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit', 'ioLatencyMs', 'ioCostQos', 'ioCostModel'] as $key) {
        $lines[] = pmssCliHelpLine($resourceSpecs[$key]['usage'], $resourceHelp[$key]['description'].($resourceDescriptionSuffixes[$key] ?? ''));
    }
    $lines[] = pmssCliHelpLine('--upload-throttle-kib=KIB', 'Persist torrent upload throttle in KiB/s; 0 removes it.');
    $lines[] = pmssCliHelpLine('--welcome-message=HTML', 'Set or clear ~/.config/welcome-message.html.');
    $lines[] = pmssCliHelpLine('--docker-enabled=true|false', 'Persist the rootless Docker policy for this user.');
    $lines[] = pmssCliHelpLine('-h, --help', 'Show this help and exit.');
    $lines[] = '';
    $lines[] = pmssCliHelpHeading('Examples', $useColor);
    $lines[] = '  /scripts/util/userConfig.php alice 1024 200';
    $lines[] = '  /scripts/util/userConfig.php alice --io-weight=300';
    $lines[] = '  /scripts/util/userConfig.php alice 2048 500 750 300 300 /dev/sda:20M /dev/sda:20M /dev/sda:500 /dev/sda:500 125 150 50 "enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00" "ctrl=user model=linear rbps=834913556 rseqiops=93622 rrandiops=102913 wbps=618985353 wseqiops=72325 wrandiops=71025" --upload-throttle-kib=2048 --docker-enabled=true';
    $lines[] = '  /scripts/util/userConfig.php alice --welcome-message=<p>Planned maintenance tonight.</p>';
    $lines[] = '';
    $lines[] = pmssCliHelpHeading('Notes', $useColor);
    $lines[] = '  - Named resource options override legacy positional values, and USERNAME with named options reuses the stored RAM/quota baseline.';
    $lines[] = '  - RAM_MiB is applied through userConfigCgroup.php as MemoryHigh; PMSS clamps the effective floor to 250 MiB and derives MemoryMax at roughly 1.25x with at most 2048 MiB of headroom.';
    $lines[] = '  - If RAM_MiB is below 245 MiB, PMSS persists dockerEnabled=false for safety.';
    $lines[] = '  - For targeted slice-only edits, use /scripts/util/userConfigCgroup.php directly.';

    return implode("\n", $lines);
}
