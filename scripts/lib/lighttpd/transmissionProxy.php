<?php
/** Render the managed Transmission route only for a published selection. */

require_once __DIR__.'/../torrentClients/storePaths.php';
require_once __DIR__.'/../runtime/filesystem.php';
require_once __DIR__.'/../user/identity.php';

/** Match an account link to one published static socket build. */
function pmssLighttpdTransmissionSelected(
    string $user,
    string $home,
    string $projection = '/var/lib/pmss/public/torrentClients.json',
    string $storeRoot = '/home/.pmss/clients'
): bool {
    if (!pmssTorrentClientsMarkerEnabled() || !pmssValidateUsername($user)
        || basename($home) !== $user) return false;
    $target = @readlink($home.'/bin/transmission/default');
    $prefix = rtrim($storeRoot, '/').'/transmission/';
    if (!is_string($target) || strpos($target, $prefix) !== 0) return false;
    $version = substr($target, strlen($prefix));
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1) return false;
    $binary = $target.'/transmission-daemon';
    if (!pmssTorrentClientsStorePathChainSafe($storeRoot, 'transmission', $version)
        || !is_file($binary) || is_link($binary)) return false;
    $raw = pmssReadRegularFileContentsVerified($projection, null, 65536);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    foreach ((array) ($data['versions'] ?? []) as $entry) {
        if (is_array($entry) && ($entry['client'] ?? null) === 'transmission'
            && ($entry['version'] ?? null) === $version && ($entry['kind'] ?? null) === 'static'
            && ($entry['rpcUnixSocket'] ?? null) === true) return true;
    }
    return false;
}

/** Place the account proxy inside the existing htpasswd URL area. */
function pmssLighttpdTransmissionProxyBlock(string $user): string
{
    if (!pmssValidateUsername($user)) return '';
    return '$HTTP["url"] =~ "^/user-'.$user.'/transmission/" {' . "\n"
        .'    proxy.server = ( "" => (( "socket" => "/home/'.$user.'/.lighttpd/transmission.sock" )) )' . "\n"
        .'}' . "\n";
}
