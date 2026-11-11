<?php
/**
 * WireGuard configuration acquisition and configured-value discovery.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/peerConfig.php';

/** Return the directory used for WireGuard configuration state. */
function wgConfigDir(): string
{
    return pmssResolvePathFromEnv('PMSS_WG_CONFIG_DIR', '/etc/wireguard');
}
/**
 * Return the wg0 config path, allowing hermetic checks to inject a fixture.
 */
function pmssWireguardCheckConfigPath(): string
{
    if (function_exists('pmssTestModeEnabled') && pmssTestModeEnabled()) {
        $override = getenv('PMSS_WIREGUARD_CONFIG_PATH');
        if ($override !== false && trim($override) !== '') {
            return trim($override);
        }
    }

    return '/etc/wireguard/wg0.conf';
}

/** Read wg0.conf lines without trusting path shape or content. */
function pmssWireguardConfigLines(string $configPath): array
{
    if (!file_exists($configPath)) {
        return ['status' => 'missing', 'lines' => []];
    }
    if (!pmssRegularFilePathIsReadable($configPath)) {
        return ['status' => 'not_regular', 'lines' => []];
    }

    if (!is_array($lines = @file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))) {
        return ['status' => 'unreadable', 'lines' => []];
    }

    return ['status' => 'ok', 'lines' => $lines];
}

/** Read, validate, deduplicate and sort configured values with each caller's exact line policy. */
function pmssWireguardConfigValuesRead(string $configPath, string $field, string $pattern, callable $validate, int $sort): array
{
    $config = pmssWireguardConfigLines($configPath);
    if ($config['status'] !== 'ok') {
        return ['status' => $config['status'], $field => []];
    }

    $values = [];
    foreach ($config['lines'] as $line) {
        if (preg_match($pattern, trim($line), $matches) === 1 && $validate($matches[1])) {
            $values[$matches[1]] = true;
        }
    }
    $values = array_keys($values);
    sort($values, $sort);

    return ['status' => 'ok', $field => $values];
}

/**
 * Read peer owner comments from wg0.conf without trusting path shape or content.
 *
 * @return array{status:string,users:array<int,string>}
 */
function pmssWireguardPeerUsersFromConfig(string $configPath): array
{
    return pmssWireguardConfigValuesRead($configPath, 'users', '/^#\s*user\s*=\s*([A-Za-z0-9._-]+)\s*$/',
        'pmssValidateUsername', SORT_NATURAL | SORT_FLAG_CASE);
}
