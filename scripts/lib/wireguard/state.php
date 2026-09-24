<?php
/**
 * WireGuard configuration state parsing.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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
        if (is_string($override) && trim($override) !== '') {
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

    $lines = @file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return ['status' => 'unreadable', 'lines' => []];
    }

    return ['status' => 'ok', 'lines' => $lines];
}

/** Parse PMSS-managed peer blocks from wg0.conf lines. */
function wgConfigPeerBlocksFromLines(array $lines): array
{
    $peers = [];
    $current = ['user' => '', 'key' => '', 'ip' => ''];

    foreach (array_merge($lines, ['[Peer]']) as $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') {
            continue;
        }
        if (stripos($trimmed, '[Peer]') === 0) {
            if ($current['user'] !== '' || $current['key'] !== '' || $current['ip'] !== '') {
                $peers[] = $current;
            }
            $current = ['user' => '', 'key' => '', 'ip' => ''];
            continue;
        }
        if (preg_match('/^#\s*user\s*=\s*(.*?)\s*$/', $trimmed, $matches) === 1) {
            $current['user'] = trim($matches[1]);
            continue;
        }
        if (preg_match('/^PublicKey\s*=\s*(.*?)\s*$/i', $trimmed, $matches) === 1) {
            $current['key'] = trim($matches[1]);
            continue;
        }
        if (preg_match('/^AllowedIPs\s*=\s*(.*?)\s*$/i', $trimmed, $matches) === 1) {
            $cidr = trim(explode(',', trim($matches[1]), 2)[0]);
            $current['ip'] = trim(explode('/', $cidr, 2)[0]);
        }
    }

    return $peers;
}

/**
 * Read peer owner comments from wg0.conf without trusting path shape or content.
 *
 * @return array{status:string,users:array<int,string>}
 */
function pmssWireguardPeerUsersFromConfig(string $configPath): array
{
    $config = pmssWireguardConfigLines($configPath);
    if ($config['status'] !== 'ok') {
        return ['status' => $config['status'], 'users' => []];
    }

    $peerUsers = [];
    foreach ($config['lines'] as $line) {
        if (preg_match('/^#\s*user\s*=\s*([A-Za-z0-9._-]+)\s*$/', trim($line), $matches) !== 1) {
            continue;
        }
        $user = $matches[1];
        if (pmssValidateUsername($user)) {
            $peerUsers[$user] = true;
        }
    }
    $peerUsers = array_keys($peerUsers);
    sort($peerUsers, SORT_NATURAL | SORT_FLAG_CASE);

    return ['status' => 'ok', 'users' => $peerUsers];
}
