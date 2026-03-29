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
    $resourceLines = [];
    foreach (pmssUserConfigCliResourceSpecs() as $spec) {
        $resourceLines[] = '  '.$spec['usage'];
    }
    return implode("\n", [
        'Usage:',
        '  addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [TRAFFIC_CAP_MBIT] [UPLOAD_THROTTLE_KIB]',
        '  addUser.php --user=USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]',
        '',
        'Resource options:',
        $resourceLines[0],
        $resourceLines[1],
        '  --upload-throttle-kib=KIB',
        $resourceLines[2],
        $resourceLines[3],
        $resourceLines[4],
        $resourceLines[5],
        $resourceLines[6],
        $resourceLines[7],
        $resourceLines[8],
        '  --docker-enabled=true|false',
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
    $longOptions = [
        'user',
        'password',
        'ram-mib',
        'disk-quota-gib',
        'upload-throttle-kib',
        'docker-enabled',
    ];
    foreach (pmssUserConfigCliResourceSpecs() as $spec) {
        $longOptions[] = $spec['addUserOption'];
    }
    $parsed = pmssParseCliTokens($argv, $longOptions);
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

    foreach (pmssUserConfigCliResourceSpecs() as $key => $spec) {
        $value = pmssAddUserCliValue($parsed, $spec['addUserOption'], $args, $spec['addUserLegacyIndex'], null);
        if ($value !== null && $value !== '') {
            $user[$key] = $value;
        }
    }
    $torrentThrottle = pmssAddUserCliValue($parsed, 'upload-throttle-kib', $args, 7, null);
    if ($torrentThrottle !== null && $torrentThrottle !== '') {
        $user['torrentThrottle'] = $torrentThrottle;
    }
    $dockerEnabled = pmssCliOption($parsed, 'docker-enabled', null, null);
    if ($dockerEnabled === true || $dockerEnabled === '') {
        throw new InvalidArgumentException('--docker-enabled requires true or false');
    }
    if ($dockerEnabled !== null) {
        $user['dockerEnabled'] = (string) $dockerEnabled;
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
