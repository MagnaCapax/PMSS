<?php
/**
 * WireGuard provisioning for PMSS deployments.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/user/selection.php';
require_once __DIR__.'/networkInfo.php';
require_once __DIR__.'/runtime.php';
require_once __DIR__.'/log.php';
require_once __DIR__.'/lighttpd/userFileWrite.php';
require_once __DIR__.'/update/runtime/commands.php';

function wgLog(string $message): void
{
    logmsg('[wireguard] '.$message);
}

/**
 * Atomically persist a WireGuard-managed file with the expected mode.
 */
function wgWriteManagedFile(string $path, string $contents, int $mode, string $context): bool
{
    if (!pmssAtomicWriteFile($path, $contents, $mode)) {
        wgLog('Failed to write '.$context.' at '.$path);
        return false;
    }

    return true;
}

/**
 * Return the directory used for WireGuard configuration state.
 */
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
    if (!is_file($configPath) || is_link($configPath)) {
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

/**
 * Enumerate tenants targeted for configuration distribution.
 */
function wgListHomeUsers(): array
{
    if (($override = getenv('PMSS_WG_USER_LIST')) !== false && $override !== '') {
        return pmssManagedUsersNormalizeList(explode(',', $override));
    }
    return pmssManagedHomeUsersList();
}

function wgSupports(): bool
{
    $supported = pmssCommandPath('wg') !== '';
    if (!$supported) {
        wgLog('wg binary not available on PATH');
    }
    return $supported;
}

/**
 * Resolve WireGuard key material from a test override or captured command output.
 */
function wgKeyMaterialResolve(string $envKey, string $command, string $failureMessage): string
{
    $override = getenv($envKey);
    if ($override !== false) {
        $value = trim($override);
    } else {
        $result = pmssCommandCapture($command);
        $value = $result['rc'] === 0 ? trim($result['stdout']) : '';
    }

    if ($value === '') {
        wgLog($failureMessage);
    }

    return $value;
}

/**
 * Confirm that the supplied address is a routable public IPv4 endpoint.
 */
function wgValidatePublicIp(string $candidate): ?string
{
    if (($candidate = trim($candidate)) === '') {
        return null;
    }

    $ip = filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    return $ip === false ? null : $ip;
}

/**
 * Query the external helper service for the host's public address.
 */
function wgExternalEndpointUrlCandidates(): array
{
    return [
        // Primary endpoint maintained by PMSS.
        'https://pulsedmedia.com/remote/myip.php',
        // Best-effort fallback used when the primary endpoint is unavailable.
        'https://api.ipify.org',
    ];
}

/**
 * Determine the best endpoint to advertise to tenants.
 */
function wgResolveEndpoint(string $hostname): array
{
    // Prefer DNS resolution before hitting external services or interface inspection.
    $hostnamePrivate = '';
    if ($hostname !== '') {
        $dnsOverride = getenv('PMSS_WG_DNS_IP');
        if ($dnsOverride !== false && $dnsOverride !== '') {
            $resolved = $dnsOverride;
        } else {
            $resolved = gethostbyname($hostname);
        }
        if ($resolved !== $hostname) {
            $hostnamePrivate = $resolved;
            $public     = wgValidatePublicIp($resolved);
            if ($public !== null) {
                return [$public, 'hostname'];
            }
        }
    }

    $interfacePrivate = '';
    $interfaceOverride = getenv('PMSS_WG_INTERFACE_IP');
    if ($interfaceOverride !== false) {
        $interfaceIp = trim($interfaceOverride);
        if ($interfaceIp === '') {
            $interfaceIp = null;
        }
    } else {
        $interface = detectPrimaryInterface();
        if ($interface === '') {
            $interfaceIp = null;
        } else {
            // Look for the primary IPv4 address associated with the uplink interface.
            $cmd = '/sbin/ip -4 -o addr show dev '.escapeshellarg($interface).' 2>/dev/null';
            exec($cmd, $output, $rc);
            $interfaceIp = null;
            if ($rc === 0) {
                foreach ($output as $line) {
                    if (preg_match('/inet\\s+([0-9.]+)/', $line, $matches)) {
                        $interfaceIp = $matches[1];
                        break;
                    }
                }
            }
        }
    }
    if ($interfaceIp !== null) {
        $public = wgValidatePublicIp($interfaceIp);
        if ($public !== null) {
            return [$public, 'interface'];
        }
        $interfacePrivate = $interfaceIp;
    }

    // #TODO Replace with an internal endpoint discovery helper instead of calling out. (GH #123)
    $externalOverride = getenv('PMSS_WG_EXTERNAL_IP');
    if ($externalOverride !== false) {
        $external = $externalOverride === '' ? null : wgValidatePublicIp($externalOverride);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout'    => 3,
                'user_agent' => 'PMSS WireGuard (+https://github.com/MagnaCapax/PMSS)',
            ],
        ]);

        $external = null;
        foreach (wgExternalEndpointUrlCandidates() as $url) {
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                continue;
            }
            $ip = wgValidatePublicIp($response);
            if ($ip !== null) {
                $external = $ip;
                break;
            }
        }
    }
    if ($external !== null) {
        return [$external, 'external'];
    }

    if ($interfacePrivate !== '') {
        return [$interfacePrivate, 'interface_private'];
    }

    if ($hostnamePrivate !== '') {
        return [$hostnamePrivate, 'hostname_private'];
    }

    return ['', 'unknown'];
}

/** Prefer a resolvable hostname for client profiles, retaining IP fallback. */
function wgResolveClientEndpoint(string $hostname): array
{
    $hostname = trim($hostname);
    if (pmssHostnameIsValid($hostname, false)) {
        $dnsOverride = getenv('PMSS_WG_DNS_IP');
        $resolved = $dnsOverride !== false && trim($dnsOverride) !== ''
            ? trim($dnsOverride)
            : gethostbyname($hostname);
        if ($resolved !== $hostname && wgValidatePublicIp($resolved) !== null) {
            return [$hostname, 'hostname'];
        }
    }

    return wgResolveEndpoint($hostname);
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

    $priv = wgKeyMaterialResolve('PMSS_WG_PRIVATE_KEY', 'wg genkey', 'Failed to generate server private key');
    if ($priv === '') {
        return ['', ''];
    }

    $pub = wgKeyMaterialResolve('PMSS_WG_PUBLIC_KEY', 'echo '.escapeshellarg($priv).' | wg pubkey', 'Failed to derive server public key');
    if ($pub === '') {
        return ['', ''];
    }

    if (!wgWriteManagedFile($privFile, $priv.PHP_EOL, 0600, 'WireGuard server private key')) {
        return ['', ''];
    }
    if (!wgWriteManagedFile($pubFile, $pub.PHP_EOL, 0640, 'WireGuard server public key')) {
        @unlink($privFile);
        return ['', ''];
    }

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
 * Replace one guide line while preserving the original text on regex errors.
 */
function wgGuideReplaceFirst(string $content, string $pattern, string $replacement): string
{
    $updated = preg_replace($pattern, $replacement, $content, 1);
    return $updated === null ? $content : $updated;
}

/**
 * Resolve the per-user public-key registry path.
 */
function wgUserPublicKeyPath(string $user): string
{
    $homeBase = pmssResolvePathFromEnv('PMSS_WG_HOME_BASE', '/home');
    return $homeBase.'/'.$user.'/.wireguard-public-key';
}

/**
 * Resolve the per-user client configuration path.
 */
function wgUserGuidePath(string $user): string
{
    $homeBase = pmssResolvePathFromEnv('PMSS_WG_HOME_BASE', '/home');
    return $homeBase.'/'.$user.'/wireguard.txt';
}

/**
 * Detect whether a guide still expects the user to provide a private key.
 */
function wgGuideHasPrivateKeyPlaceholder(string $content): bool
{
    return preg_match('/^PrivateKey = <client private key>$/m', $content) === 1;
}

/**
 * Replace the placeholder client private key in a user guide.
 */
function wgApplyPrivateKeyToGuide(string $content, string $privateKey): string
{
    return wgGuideReplaceFirst(
        $content,
        '/^PrivateKey = <client private key>$/m',
        'PrivateKey = '.$privateKey
    );
}

/**
 * Determine whether the guide still matches the PMSS-managed bootstrap profile.
 */
function wgGuideLooksManagedBootstrapProfile(string $content): bool
{
    return preg_match('/^MTU = 1420$/m', $content) === 1
        && preg_match('/^DNS = 1\.1\.1\.1$/m', $content) === 1
        && preg_match('/^AllowedIPs = 0\.0\.0\.0\/0, ::\/0$/m', $content) === 1
        && preg_match('/^PersistentKeepalive = 25$/m', $content) === 1;
}

/**
 * Read the client private key from a ready-to-import guide.
 */
function wgGuidePrivateKey(string $content): string
{
    if (preg_match('/^PrivateKey = ([^\r\n]+)$/m', $content, $matches) !== 1) {
        return '';
    }

    $privateKey = trim($matches[1]);
    if ($privateKey === '' || $privateKey === '<client private key>') {
        return '';
    }

    return $privateKey;
}

/**
 * Build a ready-to-import client configuration template.
 */
function wgBuildClientGuide(string $publicKey, string $endpoint, int $listenPort): string
{
    return "[Interface]\n"
        ."PrivateKey = <client private key>\n"
        ."Address = 10.90.90.X/32\n"
        ."MTU = 1420\n"
        ."DNS = 1.1.1.1\n\n"
        ."[Peer]\n"
        ."PublicKey = {$publicKey}\n"
        ."Endpoint = {$endpoint}:{$listenPort}\n"
        ."AllowedIPs = 0.0.0.0/0, ::/0\n"
        ."PersistentKeepalive = 25\n";
}

/**
 * Generate a client keypair for the ready-to-import bootstrap profile.
 *
 * Environment overrides are available for hermetic tests.
 *
 * @return array{0:string,1:string}
 */
function wgGenerateClientKeypair(): array
{
    $privateKey = wgKeyMaterialResolve(
        'PMSS_WG_CLIENT_PRIVATE_KEY',
        'wg genkey',
        'Failed to generate WireGuard client private key'
    );
    if ($privateKey === '') {
        return ['', ''];
    }

    $publicKey = wgDerivePublicKey($privateKey);
    if ($publicKey === '') {
        return ['', ''];
    }

    return [$privateKey, $publicKey];
}

/**
 * Derive a client public key from private key material.
 */
function wgDerivePublicKey(string $privateKey): string
{
    $publicKey = wgKeyMaterialResolve(
        'PMSS_WG_CLIENT_PUBLIC_KEY',
        'printf %s '.escapeshellarg($privateKey).' | wg pubkey',
        'Failed to derive WireGuard client public key'
    );
    if ($publicKey === '' || !wgValidatePublicKey($publicKey)) {
        if ($publicKey === '') {
            return '';
        }
        wgLog('Failed to derive WireGuard client public key');
        return '';
    }

    return $publicKey;
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
    return $decoded !== false && strlen($decoded) === 32;
}

/**
 * Read configured peer public keys from wg0.conf.
 *
 * @return array{status:string,keys:array<int,string>}
 */
function pmssWireguardPeerPublicKeysFromConfig(string $configPath): array
{
    $config = pmssWireguardConfigLines($configPath);
    if ($config['status'] !== 'ok') {
        return ['status' => $config['status'], 'keys' => []];
    }

    $keys = [];
    foreach ($config['lines'] as $line) {
        if (preg_match('/^PublicKey\s*=\s*([A-Za-z0-9+\/=]+)\s*$/', trim($line), $matches) !== 1 || !wgValidatePublicKey($matches[1])) {
            continue;
        }
        $keys[$matches[1]] = true;
    }

    $keys = array_keys($keys);
    sort($keys, SORT_STRING);

    return ['status' => 'ok', 'keys' => $keys];
}

/**
 * Read the valid WireGuard public keys registered for a single user.
 *
 * @return array<int,string>
 */
function wgReadUserPublicKeys(string $user): array
{
    if (!pmssValidateUsername($user)) {
        wgLog('Ignoring WireGuard public keys for invalid user '.$user);
        return [];
    }

    $path = wgUserPublicKeyPath($user);
    if (!file_exists($path)) {
        return [];
    }
    if (!is_file($path) || is_link($path)) {
        wgLog('Ignoring unsafe WireGuard public key path for user '.$user.': '.$path);
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        wgLog('Failed to read '.$path.' for user '.$user);
        return [];
    }

    $result = [];
    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (!wgValidatePublicKey($line)) {
            wgLog(sprintf('Ignoring invalid WireGuard public key for user %s at %s line %d', $user, $path, $index + 1));
            continue;
        }
        $result[] = $line;
    }

    return $result;
}

/**
 * Collect valid WireGuard public keys from ~/.wireguard-public-key for each user.
 *
 * @return array<int,array{user:string,key:string}>
 */
function wgCollectUserPublicKeys(): array
{
    $result = [];

    foreach (wgListHomeUsers() as $user) {
        if ($user === '') {
            continue;
        }
        foreach (wgReadUserPublicKeys($user) as $key) {
            $result[] = [
                'user' => $user,
                'key'  => $key,
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
 * Attach deterministic client IPs to collected peer entries.
 *
 * @param array<int,array{user:string,key:string}> $entries
 *
 * @return array<int,array{user:string,key:string,ip:string}>
 */
function wgAssignClientIps(array $entries): array
{
    $used     = [];
    $assigned = [];

    foreach ($entries as $entry) {
        $ip = wgDeriveClientIp($entry['key'], $used);
        if ($ip === '') {
            wgLog('Unable to assign WireGuard IP for user '.$entry['user'].' (exhausted address space?)');
            continue;
        }
        $used[$ip] = true;
        $entry['ip'] = $ip;
        $assigned[] = $entry;
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

    return rtrim($rendered, "\r\n")."\n\n".wgBuildPeersConfig();
}

/**
 * Replace the placeholder client address in a user guide with the assigned IP.
 */
function wgApplyAssignedIpToGuide(string $content, string $ip): string
{
    $content = wgGuideReplaceFirst(
        $content,
        '/^Address = 10\.90\.90\.(?:X|[0-9]{1,3})\/32$/m',
        'Address = '.$ip.'/32'
    );

    return wgGuideReplaceFirst(
        $content,
        '/AllowedIPs = 10\.90\.90\.(?:X|[0-9]{1,3})\/32/',
        'AllowedIPs = '.$ip.'/32'
    );
}

/**
 * Ensure a default single-device client profile exists for users without keys.
 */
function wgBootstrapUserGuide(string $user, string $clientGuide): void
{
    $publicKeyPath = wgUserPublicKeyPath($user);
    $guidePath     = wgUserGuidePath($user);
    $guideExists   = is_file($guidePath);
    $guide         = $guideExists ? @file_get_contents($guidePath) : false;
    $publicKeyText = is_file($publicKeyPath) ? @file_get_contents($publicKeyPath) : false;
    $managedGuide  = $guide !== false && $guide !== '' && !wgGuideHasPrivateKeyPlaceholder($guide);

    if (!empty(wgReadUserPublicKeys($user))) {
        if (!$managedGuide && $clientGuide !== '' && !pmssWriteUserFile($guidePath, $clientGuide, $user, 0600)) {
            wgLog('Failed to distribute WireGuard guide for user '.$user);
        }
        return;
    }
    if ($managedGuide && !wgGuideLooksManagedBootstrapProfile($guide)) {
        return;
    }

    $originalGuide = $guideExists && $guide !== false ? $guide : null;
    if ($managedGuide) {
        $privateKey = wgGuidePrivateKey($guide);
        if ($privateKey === '') {
            wgLog('Managed WireGuard guide for user '.$user.' is missing a client private key');
            return;
        }
        $publicKey = wgDerivePublicKey($privateKey);
        if ($publicKey === '') {
            return;
        }
        $updatedGuide = $guide;
    } else {
        [$privateKey, $publicKey] = wgGenerateClientKeypair();
        if ($privateKey === '' || $publicKey === '') {
            return;
        }
        $updatedGuide = wgApplyPrivateKeyToGuide($clientGuide, $privateKey);
    }

    $updatedKeyText = $publicKey.PHP_EOL;
    if ($publicKeyText !== false && trim($publicKeyText) !== '') {
        $updatedKeyText = rtrim($publicKeyText, "\r\n").PHP_EOL.$publicKey.PHP_EOL;
    }

    if (!$managedGuide && !pmssWriteUserFile($guidePath, $updatedGuide, $user, 0600)) {
        wgLog('Failed to write WireGuard guide for user '.$user);
        return;
    }

    if (!pmssWriteUserFile($publicKeyPath, $updatedKeyText, $user, 0600)) {
        wgLog('Failed to write WireGuard public key for user '.$user);
        if (!$managedGuide && $originalGuide === null) {
            if (pmssUserFilePathIsSafe($guidePath) && is_file($guidePath) && !@unlink($guidePath)) {
                wgLog('Failed to remove incomplete WireGuard guide for user '.$user);
            }
        } elseif (!$managedGuide && !pmssWriteUserFile($guidePath, $originalGuide, $user, 0600)) {
            wgLog('Failed to restore WireGuard guide for user '.$user.' after public key write failure');
        }
        return;
    }

    if ($managedGuide) {
        wgLog('Recovered WireGuard public key registration for user '.$user.' from existing managed guide');
        pmssUserFileApplyMetadata($guidePath, $user, 0600);
    }
}

/**
 * Bootstrap missing per-user client profiles during provision or refresh.
 */
function wgBootstrapUserGuides(string $clientGuide): void
{
    if ($clientGuide === '') {
        return;
    }

    foreach (wgListHomeUsers() as $user) {
        if ($user === '') {
            continue;
        }
        wgBootstrapUserGuide($user, $clientGuide);
    }
}

/**
 * Update each per-user guide to show the assigned client IP for the first valid key.
 *
 * @param array<int,array{user:string,key:string,ip:string}> $assigned
 */
function wgSyncUserGuideAddresses(array $assigned, string $fallbackGuide = ''): void
{
    if (empty($assigned)) {
        return;
    }

    $seenUsers = [];

    foreach ($assigned as $entry) {
        if (isset($seenUsers[$entry['user']])) {
            continue;
        }
        $seenUsers[$entry['user']] = true;

        $target       = wgUserGuidePath($entry['user']);
        $targetExists = is_file($target);
        $guide        = $targetExists ? @file_get_contents($target) : false;
        if ($guide === false || $guide === '') {
            if ($fallbackGuide === '') {
                continue;
            }
            $guide = $fallbackGuide;
        }

        $updated = wgApplyAssignedIpToGuide($guide, $entry['ip']);
        if ($targetExists && $updated === $guide) {
            continue;
        }
        if (!pmssWriteUserFile($target, $updated, $entry['user'], 0600)) {
            wgLog('Failed to update WireGuard guide for user '.$entry['user']);
            continue;
        }
    }
}

/**
 * Lay down the WireGuard base configuration from the repo template.
 */
function wireguardWriteConfig(string $privKey, int $port): bool
{
    $configPath = wgConfigDir().'/wg0.conf';
    $contents   = wireguardBuildConfig($privKey, $port);

    if (!wgWriteManagedFile($configPath, $contents, 0640, 'WireGuard configuration')) {
        return false;
    }

    wgLog('WireGuard configuration refreshed at '.$configPath);

    return true;
}

/**
 * Provision WireGuard configuration and service.
 */
function pmssWireguardConfigure(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    requireRoot();

    $configDir = wgConfigDir();
    pmssDirEnsureExists($configDir, 0750);

    if (!wgSupports()) {
        $log('[wireguard] wg binary not available on PATH; skipping configure');
        return;
    }

    [$privKey, $pubKey] = wgEnsureKeys($configDir);
    if ($privKey === '' || $pubKey === '') {
        $log('[wireguard] Failed to ensure keys; aborting configure');
        return;
    }

    $listenPort = 51820;

    $hostname = pmssHostnameRead();
    [$endpoint, $endpointSource] = wgResolveClientEndpoint($hostname);
    if ($endpoint === '') {
        $log('[wireguard] Unable to determine public endpoint; falling back to hostname '.$hostname);
        $endpoint = $hostname;
    } else {
        $log(sprintf('[wireguard] Using %s endpoint %s', $endpointSource, $endpoint));
    }

    $guide = wgRenderTemplate(
        '/etc/seedbox/config/template.wireguard.readme',
        [
            '%HOSTNAME%'    => $hostname,
            '%ENDPOINT%'    => $endpoint,
            '%PUBLIC_KEY%'  => $pubKey,
            '%LISTEN_PORT%' => (string) $listenPort,
        ]
    );
    if ($guide === null) {
        $guide = '';
    } elseif (!wgWriteManagedFile($configDir.'/README', $guide, 0644, 'WireGuard README')) {
        // wgWriteManagedFile() logs the failed path; keep service configuration fail-soft.
    }

    $clientGuide = wgBuildClientGuide($pubKey, $endpoint, $listenPort);
    wgBootstrapUserGuides($clientGuide);

    $assignedPeers = wgAssignClientIps(wgCollectUserPublicKeys());
    wgSyncUserGuideAddresses($assignedPeers, $clientGuide);
    if (!wireguardWriteConfig($privKey, $listenPort)) {
        wgLog('Skipping wg-quick@wg0 enable because configuration refresh failed');
        return;
    }

    if (pmssEnvFlagEnabled('PMSS_WG_SKIP_SERVICE')) {
        wgLog('Service enable skipped via PMSS_WG_SKIP_SERVICE');
        return;
    }
    if (!pmssSystemdRuntimeAvailable()) {
        wgLog('systemd unavailable; skipping wg-quick@wg0 enable');
        return;
    }
    $rc = runStep('[wireguard] Enabling wg-quick@wg0', 'systemctl enable --now wg-quick@wg0');
    if ($rc !== 0) {
        wgLog('wg-quick@wg0 failed to start (rc='.$rc.')');
    }
}
