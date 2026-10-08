<?php
/**
 * Per-user nginx config generation for createNginxConfig.php.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/userConfigsReconcile.php';

const PMSS_NGINX_USER_CONFIG_GENERATED = 'generated';
const PMSS_NGINX_USER_CONFIG_SKIPPED = 'skipped';
const PMSS_NGINX_USER_CONFIG_WRITE_FAILED = 'write_failed';

/**
 * Write an explicit warning when nginx config generation skips a user.
 */
function pmssCreateNginxConfigLogSkippedUser(string $user, string $reason): void
{
    $message = sprintf('WARN: skipping nginx config for %s: %s', $user, $reason);
    if (function_exists('pmssCreateNginxConfigAppendLog')) {
        pmssCreateNginxConfigAppendLog($message);
    }
    pmssCreateNginxConfigUserLog($user, $message);
}

/** Mirror nginx generation notes into the per-user log when that logger is loaded. */
function pmssCreateNginxConfigUserLog(string $user, string $message): void
{
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, $message);
    }
}

/**
 * Persist a generated nginx config through the shared guarded writer.
 */
function pmssCreateNginxConfigWriteFile(string $path, string $content, string $user, string $label): bool
{
    if (pmssWriteManagedFile($path, $content, 'root', 'root', 0640)) {
        return true;
    }

    pmssCreateNginxConfigLogSkippedUser($user, 'failed to write '.$label.' ('.$path.')');

    return false;
}

/**
 * Write the public subdomain vhost from the shared render context.
 */
function pmssCreateNginxConfigWriteSubdomainConfigs(array $ctx, string $user, string $subdomainBase, bool $suspended, ?int $serverPort = null, ?string $mcxHosts = null): bool
{
    $hostSslBlock = (string) ($ctx['nginxSslBlock'] ?? '');
    $replacements = [
        '##user##' => $user,
    ];
    if ($serverPort !== null) {
        $replacements['##port##'] = (string) $serverPort;
    }

    $prefix = $suspended ? 'Suspended' : 'Subdomain';
    $label = $suspended ? ' suspended' : '';
    // Public vhost also answers the user's stable mcx.fi hostnames (which resolve
    // via the mcx.fi zone builder), serving the same www/public content. This is
    // the per-service name, plus the customer's cluster name when they have one —
    // already space-joined by the caller, so it drops straight into server_name.
    $ownFqdn = $user.'.'.$subdomainBase;
    $publicHost = $ownFqdn.($mcxHosts !== null && $mcxHosts !== '' ? ' '.$mcxHosts : '');
    // The public vhost uses the user's OWN certificate when they have opted into
    // per-name HTTPS (docs/adr/0039); otherwise the host cert (name-mismatch
    // warning), unchanged.
    $publicSslBlock = pmssNginxUserSslBlock($ownFqdn, $hostSslBlock);
    $config = strtr((string) ($ctx['public'.$prefix.'Template'] ?? ''), $replacements + ['##host##' => $publicHost, '##ssl_block##' => $publicSslBlock]);
    return pmssCreateNginxConfigWriteFile((string) ($ctx['subdomainConfigDir'] ?? '/etc/nginx/conf.d').'/pmss-user-'.$user.'.conf', $config, $user, 'public'.$label.' subdomain config');
}

/**
 * Resolve legacy Deluge web ports from root-owned, non-symlinked port files.
 */
function pmssCreateNginxConfigLegacyDelugeWebPort(string $homeDir, string $user): int
{
    foreach (['/.delugeWebPort' => 0, '/.delugePort' => 1] as $portFile => $offset) {
        $delugePortPath = $homeDir.$portFile;
        $raw = pmssReadRegularFileContentsVerified($delugePortPath, 0, 32);
        if ($raw === null) {
            if (is_link($delugePortPath)) pmssCreateNginxConfigUserLog($user, '[WARN] Ignoring symlinked '.$portFile.' while rendering nginx template');
            continue;
        }
        $maxPort = $offset === 1 ? 65534 : 65535;
        $delugePort = pmssNetworkPortParseDigits($raw, 1024, $maxPort);
        if ($delugePort !== null) {
            return $delugePort + $offset;
        }
        pmssCreateNginxConfigUserLog($user, '[WARN] Ignoring invalid '.$portFile.' value while rendering nginx template');
    }

    return 1;
}

/**
 * Reconcile one user's configs without deleting a working route before replacement.
 */
function pmssCreateNginxConfigGenerateUser(string $thisUser, array $ctx, bool $singleUser): string
{
    $thisUser = trim($thisUser);
    if ($thisUser === '' || !pmssValidateUsername($thisUser)) return PMSS_NGINX_USER_CONFIG_SKIPPED;

    $managedPaths = pmssCreateNginxConfigManagedUserPaths($thisUser, $ctx);
    $skipKeepPaths = $singleUser ? [$managedPaths['user']] : [];
    $homeBase = pmssCreateNginxConfigContextDir($ctx, 'homeBase', '/home');
    $runtimePortDir = pmssCreateNginxConfigContextDir($ctx, 'runtimePortDir', '/etc/seedbox/runtime/ports');
    $homeDir = $homeBase.'/'.$thisUser;
    if (!is_dir($homeDir)) {
        pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $skipKeepPaths);
        return PMSS_NGINX_USER_CONFIG_SKIPPED;
    }

    $portFile = $runtimePortDir.'/lighttpd-'.$thisUser;
    $isSuspended = is_dir($homeDir.'/www-disabled');

    $suspendedTemplate = $ctx['suspendedTemplate'] ?? false;
    $userTemplate = $ctx['userTemplate'] ?? false;
    $subdomainEnabled = $ctx['subdomainEnabled'] ?? false;
    $subdomainBase = (string)($ctx['subdomainBase'] ?? '');
    $mcxHost = null;

    if ($subdomainEnabled) {
        $billingServiceId = pmssNginxUserBillingServiceIdFromHome($homeDir);
        if ($billingServiceId !== null) {
            $mcxHost = pmssNginxUserMcxHostname($billingServiceId);
            // Customers with 2+ services also get a cluster name, round-robined
            // across their nodes by the zone builder. The client id is already on
            // this node, so no remote lookup is needed. Single-service users get a
            // name that never resolves — inert, nginx never sees a request for it.
            $billingClientId = pmssUserBillingClientIdDigitsRead($homeDir, true);
            if ($billingClientId !== null) {
                $mcxHost .= ' '.pmssNginxUserMcxClusterHostname($billingClientId);
            }
        }
    }

    // Suspended users serve a static page and never need a lighttpd port.
    if ($isSuspended) {
        if ($suspendedTemplate === false || $suspendedTemplate === '') {
            // No dedicated suspended template found; skip generating a per-user
            // config so nginx falls back to generic defaults.
            pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, []);
            return PMSS_NGINX_USER_CONFIG_SKIPPED;
        }
    } else {
        if (!file_exists($homeDir.'/.rtorrent.rc')) {
            pmssCreateNginxConfigLogSkippedUser($thisUser, 'missing .rtorrent.rc prerequisite');
            pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $skipKeepPaths);
            return PMSS_NGINX_USER_CONFIG_SKIPPED;
        }

        $serverPort = pmssReadRegularFileInt($portFile);
        if (!pmssNetworkPortInRange($serverPort, 1024) || !is_file($homeDir.'/.lighttpd.conf')) {
            passthru('/scripts/util/userConfigLighttpd.php '.escapeshellarg($thisUser));
            $serverPort = pmssReadRegularFileInt($portFile);
        }
        if (!pmssNetworkPortInRange($serverPort, 1024)) {
            pmssCreateNginxConfigLogSkippedUser($thisUser, 'lighttpd port missing or invalid after refresh attempt ('.$portFile.')');
            pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $skipKeepPaths);
            return PMSS_NGINX_USER_CONFIG_SKIPPED;
        }
    }

    $writtenPaths = [];
    if ($subdomainEnabled) {
        if (!pmssCreateNginxConfigWriteSubdomainConfigs($ctx, $thisUser, $subdomainBase, $isSuspended, $serverPort ?? null, $mcxHost)) return PMSS_NGINX_USER_CONFIG_WRITE_FAILED;
        $writtenPaths[] = $managedPaths['public'];
    }

    if (!$isSuspended && ($userTemplate === false || $userTemplate === '')) {
        if ($singleUser) $writtenPaths[] = $managedPaths['user'];
        pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $writtenPaths);
        return PMSS_NGINX_USER_CONFIG_SKIPPED;
    }

    $template = $isSuspended ? $suspendedTemplate : $userTemplate;
    $replacements = ['##username' => $thisUser];
    if (!$isSuspended) {
        $replacements['##serverPort'] = (string) $serverPort;
        // Older templates may still request the legacy Deluge web port.
        if (strpos($template, '##delugeWebPort') !== false) {
            $replacements['##delugeWebPort'] = (string) pmssCreateNginxConfigLegacyDelugeWebPort($homeDir, $thisUser);
        }
    }
    $userConfig = strtr($template, $replacements);

    if (!pmssCreateNginxConfigWriteFile($managedPaths['user'], $userConfig, $thisUser, $isSuspended ? 'user suspended config' : 'user config')) return PMSS_NGINX_USER_CONFIG_WRITE_FAILED;
    $writtenPaths[] = $managedPaths['user'];
    pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $writtenPaths);
    pmssCreateNginxConfigUserLog($thisUser, $isSuspended ? 'nginx config regenerated (suspended template)' : 'nginx config regenerated');
    return PMSS_NGINX_USER_CONFIG_GENERATED;
}
