<?php
/**
 * WireGuard provisioning for PMSS deployments.
 */

require_once __DIR__.'/users.php';
require_once __DIR__.'/networkInfo.php';
require_once __DIR__.'/runtime.php';

if (!function_exists('logmsg')) {
    require_once __DIR__.'/update.php';
}
if (!function_exists('runStep')) {
    require_once __DIR__.'/update/runtime/commands.php';
}

function wgLog(string $message): void
{
    logmsg('[wireguard] '.$message);
}

/**
 * Return the directory used for WireGuard configuration state.
 */
function wgConfigDir(): string
{
    return pmssResolvePathFromEnv('PMSS_WG_CONFIG_DIR', '/etc/wireguard');
}

/**
 * Compose an absolute path inside the WireGuard configuration directory.
 */
function wgConfigPath(string $file): string
{
    return wgConfigDir().'/'.$file;
}

/**
 * Resolve the home directory base used when distributing user files.
 */
function wgHomeBase(): string
{
    return pmssResolvePathFromEnv('PMSS_WG_HOME_BASE', '/home');
}

/**
 * Enumerate tenants targeted for configuration distribution.
 */
function wgListHomeUsers(): array
{
    $override = getenv('PMSS_WG_USER_LIST');
    if ($override !== false && $override !== '') {
        $users = array_filter(array_map('trim', explode(',', $override)), 'strlen');
        return array_values($users);
    }
    return users::listHomeUsers();
}

function wgSupports(): bool
{
    exec('command -v wg', $out, $rc);
    if ($rc !== 0) {
        wgLog('wg binary not available on PATH');
        return false;
    }
    return true;
}

/**
 * Produce a WireGuard private key, optionally via test overrides.
 */
function wgGeneratePrivateKey(): string
{
    $override = getenv('PMSS_WG_PRIVATE_KEY');
    if ($override !== false) {
        return trim($override);
    }

    exec('wg genkey', $privOut, $rc);
    return $rc === 0 ? trim($privOut[0] ?? '') : '';
}

/**
 * Derive the WireGuard public key from the supplied private key.
 */
function wgDerivePublicKey(string $private): string
{
    $override = getenv('PMSS_WG_PUBLIC_KEY');
    if ($override !== false) {
        return trim($override);
    }

    exec('echo '.escapeshellarg($private).' | wg pubkey', $pubOut, $rc);
    return $rc === 0 ? trim($pubOut[0] ?? '') : '';
}

/**
 * Confirm that the supplied address is a routable public IPv4 endpoint.
 */
function wgValidatePublicIp(string $candidate): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    $ip    = filter_var($candidate, FILTER_VALIDATE_IP, $flags);
    return $ip === false ? null : $ip;
}

/**
 * Query the external helper service for the host's public address.
 */
function wgFetchExternalEndpoint(): ?string
{
    // #TODO Replace with an internal endpoint discovery helper instead of calling out. (GH #123)
    $override = getenv('PMSS_WG_EXTERNAL_IP');
    if ($override !== false) {
        return $override === '' ? null : wgValidatePublicIp($override);
    }

    $url     = 'https://pulsedmedia.com/remote/myip.php';
    $context = stream_context_create(['http' => ['timeout' => 3]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }

    return wgValidatePublicIp($response);
}

/**
 * Discover the IPv4 address currently bound to the uplink interface.
 */
function wgDetectInterfaceAddress(): ?string
{
    $override = getenv('PMSS_WG_INTERFACE_IP');
    if ($override !== false) {
        $trimmed = trim($override);
        if ($trimmed === '') {
            return null;
        }
        return $trimmed;
    }

    $interface = detectPrimaryInterface();
    if ($interface === '') {
        return null;
    }

    // Look for the primary IPv4 address associated with the uplink interface.
    $cmd = '/sbin/ip -4 -o addr show dev '.escapeshellarg($interface).' 2>/dev/null';
    exec($cmd, $output, $rc);
    if ($rc !== 0) {
        return null;
    }

    foreach ($output as $line) {
        if (preg_match('/inet\\s+([0-9.]+)/', $line, $matches)) {
            return $matches[1];
        }
    }

    return null;
}

/**
 * Determine the best endpoint to advertise to tenants.
 */
function wgResolveEndpoint(string $hostname): array
{
    // Prefer DNS resolution before hitting external services or interface inspection.
    $hostnameIp = '';
    if ($hostname !== '') {
        $dnsOverride = getenv('PMSS_WG_DNS_IP');
        if ($dnsOverride !== false && $dnsOverride !== '') {
            $resolved = $dnsOverride;
        } else {
            $resolved = gethostbyname($hostname);
        }
        if ($resolved !== $hostname) {
            $hostnameIp = $resolved;
            $public     = wgValidatePublicIp($resolved);
            if ($public !== null) {
                return [$public, 'hostname'];
            }
        }
    }

    $external = wgFetchExternalEndpoint();
    if ($external !== null) {
        return [$external, 'external'];
    }

    $interfaceIp = wgDetectInterfaceAddress();
    if ($interfaceIp !== null) {
        $public = wgValidatePublicIp($interfaceIp);
        if ($public !== null) {
            return [$public, 'interface'];
        }
        return [$interfaceIp, 'interface_private'];
    }

    if ($hostnameIp !== '') {
        return [$hostnameIp, 'hostname_private'];
    }

    return ['', 'unknown'];
}

/**
 * Ensure server key material exists, creating it when missing.
 */
function wgEnsureKeys(string $dir): array
{
    $privFile = $dir.'/server_private.key';
    $pubFile  = $dir.'/server_public.key';

    if (file_exists($privFile) && file_exists($pubFile)) {
        return [trim((string)file_get_contents($privFile)), trim((string)file_get_contents($pubFile))];
    }

    $priv = wgGeneratePrivateKey();
    if ($priv === '') {
        wgLog('Failed to generate server private key');
        return ['', ''];
    }

    $pub = wgDerivePublicKey($priv);
    if ($pub === '') {
        wgLog('Failed to derive server public key');
        return ['', ''];
    }

    file_put_contents($privFile, $priv.PHP_EOL);
    file_put_contents($pubFile, $pub.PHP_EOL);
    chmod($privFile, 0600);
    chmod($pubFile, 0640);
    return [$priv, $pub];
}

/**
 * Render the provided template file with placeholder replacements.
 */
function wgRenderTemplate(string $path, array $placeholders): ?string
{
    $template = @file_get_contents($path);
    if ($template === false) {
        wgLog('Template missing: '.$path);
        return null;
    }
    return str_replace(array_keys($placeholders), array_values($placeholders), $template);
}

/**
 * Validate that the supplied string is a plausible WireGuard public key.
 *
 * Keys are expected to be base64-encoded 32-byte values.
 */
function wgValidatePublicKey(string $key): bool
{
    $key = trim($key);
    if ($key === '') {
        return false;
    }
    if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $key)) {
        return false;
    }
    $decoded = base64_decode($key, true);
    if ($decoded === false || strlen($decoded) !== 32) {
        return false;
    }
    return true;
}

/**
 * Collect valid WireGuard public keys from ~/.wireguard-public-key for each user.
 *
 * @return array<int,array{user:string,key:string}>
 */
function wgCollectUserPublicKeys(): array
{
    $homeBase = wgHomeBase();
    $result   = [];

    foreach (wgListHomeUsers() as $user) {
        if ($user === '') {
            continue;
        }
        $path = $homeBase.'/'.$user.'/.wireguard-public-key';
        if (!is_file($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            wgLog('Failed to read '.$path.' for user '.$user);
            continue;
        }
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (!wgValidatePublicKey($line)) {
                wgLog(sprintf('Ignoring invalid WireGuard public key for user %s at %s line %d', $user, $path, $index + 1));
                continue;
            }
            $result[] = [
                'user' => $user,
                'key'  => $line,
            ];
        }
    }

    return $result;
}

/**
 * Derive a stable /32 address under 10.90.90.0/24 for a given public key.
 *
 * @param array<string,bool> $usedIps
 */
function wgDeriveClientIp(string $key, array $usedIps): string
{
    $hash = hash('sha256', $key, true);
    $num  = unpack('N', substr($hash, 0, 4));
    $base = isset($num[1]) ? (int) $num[1] : 1;
    if ($base === 0) {
        $base = 1;
    }

    // Reserve .1 for the server and avoid network/broadcast addresses.
    $candidate = ($base % 253) + 2; // 2..254
    $tries     = 0;
    while ($tries < 253) {
        $ip = '10.90.90.'.$candidate;
        if (!isset($usedIps[$ip])) {
            return $ip;
        }
        $candidate = ($candidate % 253) + 2;
        $tries++;
    }

    // Extremely unlikely with typical tenant counts; fail-soft by skipping the key.
    return '';
}

/**
 * Assign unique /32 addresses to each collected key.
 *
 * @param array<int,array{user:string,key:string}> $entries
 * @return array<int,array{user:string,key:string,ip:string}>
 */
function wgAssignClientIps(array $entries): array
{
    $used      = [];
    $assigned  = [];

    foreach ($entries as $entry) {
        $ip = wgDeriveClientIp($entry['key'], $used);
        if ($ip === '') {
            wgLog('Unable to assign WireGuard IP for user '.$entry['user'].' (exhausted address space?)');
            continue;
        }
        $used[$ip] = true;
        $assigned[] = [
            'user' => $entry['user'],
            'key'  => $entry['key'],
            'ip'   => $ip,
        ];
    }

    return $assigned;
}

/**
 * Render auto-managed peer sections from collected public keys.
 */
function wgBuildPeersConfig(): string
{
    $entries = wgCollectUserPublicKeys();
    if (empty($entries)) {
        return "# No WireGuard peers configured; place public key(s) in ~/.wireguard-public-key on each user account.\n";
    }

    $assigned = wgAssignClientIps($entries);
    if (empty($assigned)) {
        return "# No valid WireGuard peers configured; all provided keys were invalid.\n";
    }

    $lines   = [];
    $lines[] = '# Peers managed by PMSS – do not edit by hand.';

    foreach ($assigned as $entry) {
        $lines[] = '[Peer]';
        $lines[] = '# user='.$entry['user'];
        $lines[] = 'PublicKey = '.$entry['key'];
        $lines[] = 'AllowedIPs = '.$entry['ip'].'/32';
        $lines[] = '';
    }

    return rtrim(implode("\n", $lines))."\n";
}

/**
 * Build the full WireGuard configuration (interface + peers).
 */
function wireguardBuildConfig(string $privKey, int $port): string
{
    $rendered = wgRenderTemplate(
        '/etc/seedbox/config/template.wireguard.wg0',
        [
            '%%PRIVATE_KEY%%' => $privKey,
            '%%LISTEN_PORT%%' => (string) $port,
        ]
    );
    if ($rendered === null) {
        // Fail-soft fallback: write a minimal config when template is unavailable
        $rendered = "[Interface]\n".
                    "Address = 10.90.90.1/24\n".
                    "PrivateKey = {$privKey}\n".
                    "ListenPort = {$port}\n";
    }

    $base   = rtrim($rendered, "\r\n");
    $peers  = wgBuildPeersConfig();

    return $base."\n\n".$peers;
}

/**
 * Lay down the WireGuard base configuration from the repo template.
 */
function wireguardWriteConfig(string $privKey, int $port): void
{
    $configPath = wgConfigPath('wg0.conf');
    $contents   = wireguardBuildConfig($privKey, $port);

    file_put_contents($configPath, $contents);
    chmod($configPath, 0640);
    wgLog('WireGuard configuration refreshed at '.$configPath);
}

/**
 * Refresh the operator README with the currently advertised endpoint.
 */
function wgWriteReadme(string $hostname, string $endpoint, string $pubKey, int $port): string
{
    $rendered = wgRenderTemplate(
        '/etc/seedbox/config/template.wireguard.readme',
        [
            '%HOSTNAME%'   => $hostname,
            '%ENDPOINT%'   => $endpoint,
            '%PUBLIC_KEY%' => $pubKey,
            '%LISTEN_PORT%' => (string)$port,
        ]
    );
    if ($rendered === null) {
        return '';
    }
    file_put_contents(wgConfigPath('README'), $rendered);
    return $rendered;
}

/**
 * Copy connection instructions to every tenant home directory.
 */
function wgDistributeToUsers(string $content): void
{
    if ($content === '') {
        return;
    }
    $homeBase = wgHomeBase();
    foreach (wgListHomeUsers() as $user) {
        $target = $homeBase.'/'.$user.'/wireguard.txt';
        @file_put_contents($target, $content);
        @chown($target, $user);
        @chgrp($target, $user);
        @chmod($target, 0600);
    }
}

/**
 * Enable and start the wg-quick unit unless explicitly disabled.
 */
function wgEnableService(): void
{
    if (getenv('PMSS_WG_SKIP_SERVICE') === '1') {
        wgLog('Service enable skipped via PMSS_WG_SKIP_SERVICE');
        return;
    }
    if (!is_dir('/run/systemd/system')) {
        wgLog('systemd unavailable; skipping wg-quick@wg0 enable');
        return;
    }
    $rc = runStep('[wireguard] Enabling wg-quick@wg0', 'systemctl enable --now wg-quick@wg0');
    if ($rc !== 0) {
        wgLog('wg-quick@wg0 failed to start (rc='.$rc.')');
    }
}

/**
 * Provision WireGuard configuration and service.
 */
function pmssWireguardConfigure(?callable $logger = null): void
{
    $log = pmssSelectLogger($logger);
    if (function_exists('requireRoot')) {
        requireRoot();
    }

    if (!is_dir(wgConfigDir())) {
        @mkdir(wgConfigDir(), 0750, true);
    }

    if (!wgSupports()) {
        $log('[wireguard] wg binary not available on PATH; skipping configure');
        return;
    }

    [$privKey, $pubKey] = wgEnsureKeys(wgConfigDir());
    if ($privKey === '' || $pubKey === '') {
        $log('[wireguard] Failed to ensure keys; aborting configure');
        return;
    }

    $listenPort = 51820;
    wireguardWriteConfig($privKey, $listenPort);

    $hostname = trim((string) @file_get_contents('/etc/hostname'));
    [$endpoint, $endpointSource] = wgResolveEndpoint($hostname);
    if ($endpoint === '') {
        $log('[wireguard] Unable to determine public endpoint; falling back to hostname '.$hostname);
        $endpoint = $hostname;
    } else {
        $log(sprintf('[wireguard] Using %s endpoint %s', $endpointSource, $endpoint));
    }

    $guide = wgWriteReadme($hostname, $endpoint, $pubKey, $listenPort);
    wgDistributeToUsers($guide);
    wgEnableService();
}
