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

/**
 * Return the canonical addUser CLI help text.
 */
function pmssAddUserCliUsage(): string
{
    return implode("\n", [
        'Usage:',
        '  addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [TRAFFIC_CAP_MBIT] [UPLOAD_THROTTLE_KIB]',
        '  addUser.php --user=USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]',
        '',
        'Resource options:',
        '  --traffic-limit-gb=GIB',
        '  --traffic-cap-mbit=MBIT',
        '  --upload-throttle-kib=KIB',
        '  --cpu-weight=WEIGHT',
        '  --io-weight=WEIGHT',
        '  --io-read-bw=/dev/DEVICE:RATE',
        '  --io-write-bw=/dev/DEVICE:RATE',
        '  --io-read-iops=/dev/DEVICE:IOPS',
        '  --io-write-iops=/dev/DEVICE:IOPS',
        '  --cpu-quota-percent=PERCENT|infinity',
        '',
        'Other options:',
        '  -h, --help',
    ]);
}

/**
 * Prefer an explicit long option over the legacy positional slot.
 *
 * @param array<string,mixed> $parsed
 * @param array<int,string>   $args
 * @return mixed
 */
function pmssAddUserCliValue(array $parsed, string $option, array $args, int $legacyIndex, $default = null)
{
    $value = pmssCliOption($parsed, $option, null, null);
    if ($value !== null && $value !== true) {
        return $value;
    }

    return array_key_exists($legacyIndex, $args) ? $args[$legacyIndex] : $default;
}

/**
 * Parse addUser CLI arguments into the canonical user payload.
 *
 * @return array<string,mixed>
 */
function pmssAddUserParseCli(array $argv): array
{
    $parsed = pmssParseCliTokens($argv, [
        'user',
        'password',
        'ram-mib',
        'disk-quota-gib',
        'traffic-limit-gb',
        'traffic-cap-mbit',
        'upload-throttle-kib',
        'cpu-weight',
        'io-weight',
        'io-read-bw',
        'io-write-bw',
        'io-read-iops',
        'io-write-iops',
        'cpu-quota-percent',
    ]);
    if (pmssCliOption($parsed, 'help', 'h', false) !== false) {
        return ['help' => true, 'usage' => pmssAddUserCliUsage()];
    }

    $args = array_merge([''], $parsed['arguments']);
    $user = [
        'name' => pmssAddUserCliValue($parsed, 'user', $args, 1, ''),
        'password' => pmssAddUserCliValue($parsed, 'password', $args, 2, ''),
        'memory' => pmssAddUserCliValue($parsed, 'ram-mib', $args, 3, ''),
        'quota' => pmssAddUserCliValue($parsed, 'disk-quota-gib', $args, 4, ''),
    ];

    if ($user['name'] === '' || $user['password'] === '' || $user['memory'] === '' || $user['quota'] === '') {
        throw new InvalidArgumentException(pmssAddUserCliUsage());
    }

    $optionalMap = [
        'trafficLimit' => ['traffic-limit-gb', 5],
        'trafficCapMbit' => ['traffic-cap-mbit', 6],
        'torrentThrottle' => ['upload-throttle-kib', 7],
        'CPUWeight' => ['cpu-weight', 8],
        'IOWeight' => ['io-weight', 9],
        'IOReadBW' => ['io-read-bw', 10],
        'IOWriteBW' => ['io-write-bw', 11],
        'IOReadIOPS' => ['io-read-iops', 12],
        'IOWriteIOPS' => ['io-write-iops', 13],
        'cpuQuotaPercent' => ['cpu-quota-percent', 14],
    ];
    foreach ($optionalMap as $key => $mapping) {
        $value = pmssAddUserCliValue($parsed, $mapping[0], $args, $mapping[1], null);
        if ($value !== null && $value !== '') {
            $user[$key] = $value;
        }
    }

    if (isset($user['torrentThrottle'])) {
        if (!is_numeric($user['torrentThrottle']) || (int) $user['torrentThrottle'] < 0) {
            throw new InvalidArgumentException('Invalid upload throttle value');
        }
        $user['torrentThrottle'] = (int) $user['torrentThrottle'];
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

