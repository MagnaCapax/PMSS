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
    $resourceLines = array_map(static function (array $spec): string {
        return '  '.$spec['usage'];
    }, array_values(pmssUserConfigCliResourceSpecs()));
    return implode("\n", array_merge([
        'Usage:',
        '  addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [TRAFFIC_CAP_MBIT] [UPLOAD_THROTTLE_KIB]',
        '  addUser.php --user=USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]',
        '',
        'Resource options:',
        $resourceLines[0],
        $resourceLines[1],
        '  --upload-throttle-kib=KIB',
    ], array_slice($resourceLines, 2), [
        '  --docker-enabled=true|false',
        '',
        'Other options:',
        '  -h, --help',
    ]));
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
        $value = pmssUserConfigCliLegacyValue($parsed, $spec['addUserOption'], $args, $spec['addUserLegacyIndex'], null);
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
