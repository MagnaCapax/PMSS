<?php
/**
 * Customer-local torrent installer helpers; delivered alongside dockerInstallLsio.php.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Read exactly one decimal port line using the account-agent marker contract. */
function pmssDockerInstallLsioReservedPort(string $path): ?int
{
    if (!is_file($path) || is_link($path) || filesize($path) > 32) return null;
    $line = @file_get_contents($path);
    if (!is_string($line) || !preg_match('/^([0-9]{1,5})\r?\n?$/D', $line, $matches)) return null;
    $port = (int) $matches[1];
    return $port >= 1 && $port <= 65535 ? $port : null;
}

/** Read both reservations before any Docker call or filesystem mutation. */
function pmssDockerInstallLsioTorrentPorts(string $home, string $app, ?string $hostPort = null): ?array
{
    if ($hostPort !== null) {
        fwrite(STDERR, "HOST_PORT is not accepted for {$app}; PMSS reserves its WebUI and peer ports.\n");
        return null;
    }
    $webui = pmssDockerInstallLsioReservedPort($home.'/.media-stack-port-'.$app);
    $peer = pmssDockerInstallLsioReservedPort($home.'/.media-stack-port-'.$app.'-peer');
    if ($webui === null || $peer === null) {
        fwrite(STDERR, "Missing or invalid {$app} port reservations; PMSS publishes them in its per-account setup.\n");
        return null;
    }
    return ['webui' => $webui, 'peer' => $peer];
}

/** Render the pinned LSIO defaults plus the first-start service login. */
function pmssDockerInstallLsioQbittorrentConfig(int $peer, string $user, string $password): string
{
    $salt = random_bytes(16);
    $hash = hash_pbkdf2('sha512', $password, $salt, 100000, 64, true);
    return "[AutoRun]\nenabled=false\nprogram=\n\n[LegalNotice]\nAccepted=true\n\n[Preferences]\n"
        .'Connection\UPnP=false'."\n"
        .'Connection\PortRangeMin='.$peer."\n"
        .'Downloads\SavePath=/downloads/'."\n"
        .'Downloads\ScanDirsV2=@Variant(\0\0\0\x1c\0\0\0\0)'."\n"
        .'Downloads\TempPath=/downloads/incomplete/'."\n"
        .'WebUI\Address=*'."\n"
        .'WebUI\ServerDomains=*'."\n"
        .'WebUI\Username='.$user."\n"
        .'WebUI\Password_PBKDF2="@ByteArray('.base64_encode($salt).':'.base64_encode($hash).')"'."\n"
        .'WebUI\CSRFProtection=true'."\n"
        .'WebUI\ClickjackingProtection=true'."\n"
        .'WebUI\HostHeaderValidation=false'."\n";
}

/** Reject redirected or foreign-owned config directories before writing secrets. */
function pmssDockerInstallLsioQbittorrentDirectoryEnsure(string $home): bool
{
    $path = rtrim($home, '/');
    foreach (['', 'docker', 'qbittorrent', 'config', 'qBittorrent'] as $component) {
        if ($component !== '') $path .= '/'.$component;
        $entry = @lstat($path);
        if ($entry === false && @mkdir($path, 0755)) $entry = @lstat($path);
        if ($entry === false || ($entry['mode'] & 0170000) !== 0040000
            || (function_exists('posix_geteuid') && $entry['uid'] !== posix_geteuid())) {
            fwrite(STDERR, "Unsafe qBittorrent config directory: {$path}\n");
            return false;
        }
    }
    return true;
}

/** Seed auth before first start; an existing regular config belongs to the customer. */
function pmssDockerInstallLsioQbittorrentSeed(string $home, int $peer): bool
{
    if (!pmssDockerInstallLsioQbittorrentDirectoryEnsure($home)) return false;
    $config = $home.'/docker/qbittorrent/config/qBittorrent/qBittorrent.conf';
    $existing = @lstat($config);
    if ($existing !== false) {
        if (($existing['mode'] & 0170000) !== 0100000 || $existing['nlink'] !== 1
            || (function_exists('posix_geteuid') && $existing['uid'] !== posix_geteuid())) {
            fwrite(STDERR, "Unsafe qBittorrent config file: {$config}\n");
            return false;
        }
        echo "Existing qBittorrent.conf preserved; service credential file left untouched.\n";
        return true;
    }

    // Service-only credentials are independent of the primary account password.
    $user = bin2hex(random_bytes(6));
    $password = pmssDockerInstallLsioRandomSecret();
    $credentials = $home.'/docker/qbittorrent/pmss-credentials.env';
    return pmssDockerInstallLsioWritePrivateFile($credentials, "WEBUI_USER={$user}\nWEBUI_PASS={$password}\n")
        && pmssDockerInstallLsioWritePrivateFile($config, pmssDockerInstallLsioQbittorrentConfig($peer, $user, $password));
}

/** Publish the same peer port over both protocols and configure the client listener. */
function pmssDockerInstallLsioTorrentRunArgs(string $app, array $spec): array
{
    $peer = $spec['peerPort'] ?? null;
    if (!is_int($peer) || $peer < 1 || $peer > 65535) {
        throw new InvalidArgumentException('Torrent client requires a reserved peer port.');
    }
    return ['-p', '0.0.0.0:'.$peer.':'.$peer.'/tcp', '-p', '0.0.0.0:'.$peer.':'.$peer.'/udp',
        '-e', ($app === 'qbittorrent' ? 'TORRENTING_PORT=' : 'PEERPORT=').$peer];
}

/** Request root-side proxy convergence only after Docker starts successfully. */
function pmssDockerInstallLsioTorrentProxyRefresh(string $home): bool
{
    $marker = $home.'/.lighttpd/.dockerClientProxyRefresh';
    if (!is_dir(dirname($marker)) || is_link($marker) || (!is_file($marker) && @file_put_contents($marker, '') === false)) {
        fwrite(STDERR, "Container installed; panel proxy refresh marker could not be written.\n");
        return false;
    }
    return true;
}
