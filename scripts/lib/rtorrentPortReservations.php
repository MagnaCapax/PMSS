<?php
/**
 * Legacy rTorrent port-reservation ownership and cleanup helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/log.php';
require_once __DIR__.'/runtime/filesystem.php';
require_once __DIR__.'/runtime/locks.php';

const PMSS_RTORRENT_PORT_RESERVATION_GRACE_SECONDS = 3600;

/** Validate legacy account names without forcing the full runtime bootstrap. */
function pmssRtorrentPortReservationUsernameIsValid($user): bool
{
    if (!is_string($user)) {
        return false;
    }
    if (function_exists('pmssValidateUsername')) {
        return pmssValidateUsername($user);
    }
    $normalized = strtolower(trim($user));
    return $user !== '' && $normalized === $user && preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) === 1;
}

/** Return the canonical legacy reservation types in acquisition order. */
function pmssRtorrentPortReservationSpecs(): array
{
    return array(
        'scgi' => array('key' => 'rtorrentPort', 'min' => 4000, 'max' => 24000, 'pattern' => '/^(?:scgi_port|network\.scgi\.open_port)\s*=\s*(?:[^:\s]+:)?([^\s#]+)/i'),
        'dht' => array('key' => 'rtorrentDhtPort', 'min' => 24001, 'max' => 44000, 'pattern' => '/^(?:dht_?port|dht\.port(?:\.set)?)\s*=\s*([^\s#]+)/i'),
        'listen' => array('key' => 'rtorrentListenPort', 'min' => 44001, 'max' => 64000, 'pattern' => '/^(?:port_range|network\.port_range(?:\.set)?)\s*=\s*([^\s#-]+)/i'),
    );
}

/** Keep reservation acquisition and reconciliation on one lock namespace. */
function pmssRtorrentPortReservationLockPath(): string
{
    $testMode = (defined('PMSS_TEST_MODE') && constant('PMSS_TEST_MODE')) || getenv('PMSS_TEST_MODE') === '1';
    if ($testMode) {
        $testRoot = getenv('PMSS_TEST_TEMP_ROOT');
        if (is_string($testRoot) && $testRoot !== '') {
            return rtrim($testRoot, '/').'/pmss-rtorrentPortReservations.lock';
        }
    }
    return pmssRuntimeLockPath('pmss-rtorrentPortReservations.lock');
}

/** Build an empty source result, optionally uncertain for every port type. */
function pmssRtorrentPortReservationSourceEmpty(bool $uncertain = false): array
{
    return array(
        'ports' => array(),
        'uncertain' => $uncertain ? array_fill_keys(array_keys(pmssRtorrentPortReservationSpecs()), true) : array(),
    );
}

/** Parse reservation fields from one stored user payload. */
function pmssRtorrentPortReservationPayloadSource(array $payload): array
{
    $source = pmssRtorrentPortReservationSourceEmpty();
    foreach (pmssRtorrentPortReservationSpecs() as $type => $spec) {
        if (!array_key_exists($spec['key'], $payload)) {
            continue;
        }
        $port = pmssNetworkPortParseDigits($payload[$spec['key']], $spec['min'], $spec['max']);
        if ($port === null) {
            $source['uncertain'][$type] = true;
            continue;
        }
        $source['ports'][$type][$port] = true;
    }
    return $source;
}

/** Read canonical or legacy stored ownership without hiding malformed files. */
function pmssRtorrentPortReservationStoredSource(string $user, string $configRoot): array
{
    $configRoot = rtrim($configRoot, '/');
    $canonical = $configRoot.'/users/'.$user.'.json';
    if (file_exists($canonical) || is_link($canonical)) {
        $payload = pmssJsonFileReadAssoc($canonical, true);
        return is_array($payload) ? pmssRtorrentPortReservationPayloadSource($payload) : pmssRtorrentPortReservationSourceEmpty(true);
    }

    $legacy = dirname($configRoot).'/runtime/users.json';
    if (!file_exists($legacy) && !is_link($legacy)) {
        return pmssRtorrentPortReservationSourceEmpty();
    }
    $payload = pmssJsonFileReadAssoc($legacy, true);
    if (!is_array($payload)) {
        return pmssRtorrentPortReservationSourceEmpty(true);
    }
    $users = isset($payload['users']) && is_array($payload['users']) ? $payload['users'] : $payload;
    if (!array_key_exists($user, $users)) {
        return pmssRtorrentPortReservationSourceEmpty();
    }
    return is_array($users[$user])
        ? pmssRtorrentPortReservationPayloadSource($users[$user])
        : pmssRtorrentPortReservationSourceEmpty(true);
}

/** Parse numeric ownership references from one rendered rTorrent config. */
function pmssRtorrentPortReservationConfigSource(string $path): array
{
    if (!pmssRegularFilePathIsReadable($path) || !pmssPathTargetIsSafe($path, false, true)) {
        return pmssRtorrentPortReservationSourceEmpty(true);
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return pmssRtorrentPortReservationSourceEmpty(true);
    }

    $source = pmssRtorrentPortReservationSourceEmpty();
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        foreach (pmssRtorrentPortReservationSpecs() as $type => $spec) {
            if (preg_match($spec['pattern'], $line, $matches) !== 1) {
                continue;
            }
            $port = pmssNetworkPortParseDigits($matches[1], $spec['min'], $spec['max']);
            $port === null ? $source['uncertain'][$type] = true : $source['ports'][$type][$port] = true;
        }
    }
    return $source;
}

/** Remove one exact marker while refusing links, malformed types, and ranges. */
function pmssRtorrentPortReservationMarkerRemove(string $base, string $type, int $port): bool
{
    $specs = pmssRtorrentPortReservationSpecs();
    if (!isset($specs[$type]) || !pmssNetworkPortInRange($port, $specs[$type]['min'], $specs[$type]['max'])) {
        return false;
    }
    $path = rtrim($base, '/').'/'.$type.'/'.$port;
    return pmssPathTargetIsSafe($path, false, true)
        && is_file($path)
        && !is_link($path)
        && @unlink($path);
}
