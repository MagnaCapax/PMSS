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
pmssRequireRelativeFiles(__DIR__, [
    'lighttpd/userFileWrite.php', 'nginxUserHosts.php', 'update/runtime/commands.php',
    'wireguard/state.php', 'wireguard/endpoint.php', 'wireguard/peers.php', 'wireguard/guides.php',
]);

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
    if (pmssCommandPath('wg') !== '') {
        return true;
    }
    wgLog('wg binary not available on PATH');
    return false;
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
 * Build the full WireGuard configuration (interface + peers).
 */
function wireguardBuildConfig(string $privKey, int $port, ?array $peerState = null): string
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

    return rtrim($rendered, "\r\n")."\n\n".wgBuildPeersConfig($peerState);
}

/**
 * Lay down the WireGuard base configuration from the repo template.
 */
function wireguardWriteConfig(string $privKey, int $port, ?array $peerState = null): bool
{
    $configPath = wgConfigDir().'/wg0.conf';
    $contents   = wireguardBuildConfig($privKey, $port, $peerState);

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
    if ($guide !== null) {
        wgWriteManagedFile($configDir.'/README', $guide, 0644, 'WireGuard README');
    }

    $peerState = wgClientStateReconcile($pubKey, $endpoint, $listenPort);
    if (!wireguardWriteConfig($privKey, $listenPort, $peerState)) {
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
