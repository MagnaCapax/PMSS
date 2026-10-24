<?php
/** Render the managed Transmission route only for a published selection. */

require_once __DIR__.'/../torrentClients/projection.php';
require_once __DIR__.'/../runtime/filesystem.php';
require_once __DIR__.'/../user/identity.php';

/** Match an account link to one published static socket build. */
function pmssLighttpdTransmissionSelected(
    string $user,
    string $home,
    string $projection = '/var/lib/pmss/public/torrentClients.json',
    string $storeRoot = '/home/.pmss/clients'
): bool {
    if (!pmssValidateUsername($user)
        || basename($home) !== $user) return false;
    $target = @readlink($home.'/bin/transmission/default');
    $prefix = rtrim($storeRoot, '/').'/transmission/';
    if (!is_string($target) || strpos($target, $prefix) !== 0) return false;
    $version = substr($target, strlen($prefix));
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1) return false;
    $binary = $target.'/transmission-daemon';
    if (!pmssTorrentClientsStorePathChainSafe($storeRoot, 'transmission', $version)
        || !is_file($binary) || is_link($binary)) return false;
    foreach (pmssTorrentClientsProjectionVersionsRead($projection) as $entry) {
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

/** True when the account's rendered lighttpd config does not match its Transmission selection. */
function pmssLighttpdTransmissionRouteStale(string $user, bool $selected, string $config): bool
{
    return $selected !== (strpos($config, '"^/user-'.$user.'/transmission/"') !== false);
}

/** Re-render the account's lighttpd config when a Transmission selection gained or lost its socket route. */
function pmssLighttpdTransmissionRouteConverge(string $user, string $home): void
{
    if (!pmssValidateUsername($user) || basename($home) !== $user) return;
    $path = $home.'/.lighttpd.conf';
    // The file sits in the account-writable home: read only the root-owned single-link regular inode, non-blocking,
    // so a swapped link, hard link or FIFO can neither redirect nor stall this root watchdog.
    $config = (string) pmssReadRegularFileContentsVerified($path, 0, 1048576);
    if ($config === '' || !pmssLighttpdTransmissionRouteStale($user, pmssLighttpdTransmissionSelected($user, $home), $config)) return;
    runStep('Reconcile Transmission lighttpd route for '.$user,
        pmssBuildCommand('/scripts/util/userConfigLighttpd.php', [$user]));
}
