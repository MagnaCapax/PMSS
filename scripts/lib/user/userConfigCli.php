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

/** @return array<string,array<string,mixed>> Shared resource option specification. */
function pmssUserConfigCliResourceSpecs(): array
{
    return [
        'trafficLimit' => ['addUserOption' => 'traffic-limit-gb', 'addUserLegacyIndex' => 5, 'userConfigIndex' => 4, 'usage' => '--traffic-limit-gb=GIB', 'parse' => 'int', 'default' => null, 'persist' => false],
        'trafficCapMbit' => ['addUserOption' => 'traffic-cap-mbit', 'addUserLegacyIndex' => 6, 'userConfigIndex' => 12, 'usage' => '--traffic-cap-mbit=MBIT', 'parse' => 'int', 'default' => 0, 'persist' => true],
        'CPUWeight' => ['addUserOption' => 'cpu-weight', 'addUserLegacyIndex' => 8, 'userConfigIndex' => 5, 'usage' => '--cpu-weight=WEIGHT', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--cpu-weight='],
        'IOWeight' => ['addUserOption' => 'io-weight', 'addUserLegacyIndex' => 9, 'userConfigIndex' => 6, 'usage' => '--io-weight=WEIGHT', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--io-weight='],
        'IOReadBW' => ['addUserOption' => 'io-read-bw', 'addUserLegacyIndex' => 10, 'userConfigIndex' => 7, 'usage' => '--io-read-bw=/dev/DEVICE:RATE', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-read-bw='],
        'IOWriteBW' => ['addUserOption' => 'io-write-bw', 'addUserLegacyIndex' => 11, 'userConfigIndex' => 8, 'usage' => '--io-write-bw=/dev/DEVICE:RATE', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-write-bw='],
        'IOReadIOPS' => ['addUserOption' => 'io-read-iops', 'addUserLegacyIndex' => 12, 'userConfigIndex' => 9, 'usage' => '--io-read-iops=/dev/DEVICE:IOPS', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-read-iops='],
        'IOWriteIOPS' => ['addUserOption' => 'io-write-iops', 'addUserLegacyIndex' => 13, 'userConfigIndex' => 10, 'usage' => '--io-write-iops=/dev/DEVICE:IOPS', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-write-iops='],
        'cpuQuotaPercent' => ['addUserOption' => 'cpu-quota-percent', 'addUserLegacyIndex' => 14, 'userConfigIndex' => 11, 'usage' => '--cpu-quota-percent=PERCENT|infinity', 'parse' => 'string', 'default' => 0, 'persist' => true],
    ];
}

/** Prefer an explicit long option over a legacy positional slot. */
function pmssUserConfigCliLegacyValue(array $parsed, string $option, array $args, int $legacyIndex, $default = null)
{
    $value = pmssCliOption($parsed, $option, null, null);
    return ($value !== null && $value !== true) ? $value : (array_key_exists($legacyIndex, $args) ? $args[$legacyIndex] : $default);
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
    $normalized = strtolower(trim((string) $rawOption));
    if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
        return false;
    }
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
    $values = [];
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        $value = array_key_exists($spec[$indexKey], $args) ? $args[$spec[$indexKey]] : $spec['default'];
        $values[$key] = ($spec['parse'] === 'int' && $value !== null) ? (int) $value : $value;
    }
    return $values;
}

/** @param array<int,mixed> $args @return array<string,bool> Track explicit persisted resources. */
function pmssUserConfigCliPersistedPositionalPresence(array $args): array
{
    $presence = [];
    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        if (empty($spec['persist'])) {
            continue;
        }
        $presence[$key] = array_key_exists($spec['userConfigIndex'], $args)
            && $args[$spec['userConfigIndex']] !== '';
    }
    return $presence;
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
        $index = $spec['userConfigIndex'];
        $optionalArgs[$index] = array_key_exists($key, $user) ? (string) $user[$key] : '';
        if ($optionalArgs[$index] !== '') {
            $lastOptionalIndex = $index;
        }
    }
    $positionals = [];
    for ($index = 4; $index <= $lastOptionalIndex; $index++) {
        $positionals[] = $optionalArgs[$index];
    }
    return $positionals;
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
