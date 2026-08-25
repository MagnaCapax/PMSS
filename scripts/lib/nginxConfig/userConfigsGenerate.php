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
 * Write public/private subdomain vhosts from the shared render context.
 */
function pmssCreateNginxConfigWriteSubdomainConfigs(array $ctx, string $user, string $subdomainBase, ?string $hashHost, bool $suspended, ?int $serverPort = null, ?string $mcxHosts = null): bool
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
    // warning), unchanged. The private vhost always uses the host cert.
    $publicSslBlock = pmssNginxUserSslBlock($ownFqdn, $hostSslBlock);
    foreach ([[$publicHost, 'public'.$prefix.'Template', '', 'public'.$label.' subdomain config', $publicSslBlock], [$hashHost, 'private'.$prefix.'Template', '-hash', 'private'.$label.' subdomain config', $hostSslBlock]] as $target) {
        if ($target[0] === null) {
            continue;
        }
        $config = strtr((string) ($ctx[$target[1]] ?? ''), $replacements + ['##host##' => $target[0], '##ssl_block##' => $target[4]]);
        if (!pmssCreateNginxConfigWriteFile((string) ($ctx['subdomainConfigDir'] ?? '/etc/nginx/conf.d').'/pmss-user-'.$user.$target[2].'.conf', $config, $user, $target[3])) {
            return false;
        }
    }

    return true;
}

/**
 * Resolve legacy Deluge web ports from root-owned, non-symlinked port files.
 */
function pmssCreateNginxConfigLegacyDelugeWebPort(string $homeDir, string $user): int
{
    foreach (['/.delugeWebPort' => 0, '/.delugePort' => 1] as $portFile => $offset) {
        $delugePortPath = $homeDir.$portFile;
        if (!pmssRegularFilePathIsReadable($delugePortPath)) {
            if (is_link($delugePortPath)) pmssCreateNginxConfigUserLog($user, '[WARN] Ignoring symlinked '.$portFile.' while rendering nginx template');
            continue;
        }

        $owner = @fileowner($delugePortPath);
        if ($owner === false || (int) $owner !== 0) {
            pmssCreateNginxConfigUserLog($user, '[WARN] Ignoring non-root-owned '.$portFile.' while rendering nginx template');
            continue;
        }

        $raw = pmssReadRegularFileTrimmed($delugePortPath);
        if ($raw === null) continue;
        if ($raw === '' || !ctype_digit($raw)) {
            pmssCreateNginxConfigUserLog($user, '[WARN] Ignoring non-numeric '.$portFile.' value while rendering nginx template');
            continue;
        }

        $delugePort = (int) $raw;
        $maxPort = $offset === 1 ? 65534 : 65535;
        if (pmssNetworkPortInRange($delugePort, 1024, $maxPort)) {
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
    $homeBase = pmssCreateNginxConfigContextDir($ctx, 'homeBase', '/home');
    $runtimePortDir = pmssCreateNginxConfigContextDir($ctx, 'runtimePortDir', '/etc/seedbox/runtime/ports');
    $homeDir = $homeBase.'/'.$thisUser;
    if (!is_dir($homeDir)) {
        pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $singleUser ? [$managedPaths['user']] : []);
        return PMSS_NGINX_USER_CONFIG_SKIPPED;
    }

    $portFile = $runtimePortDir.'/lighttpd-'.$thisUser;
    $isSuspended = is_dir($homeDir.'/www-disabled');

    $suspendedTemplate = $ctx['suspendedTemplate'] ?? false;
    $userTemplate = $ctx['userTemplate'] ?? false;
    $subdomainEnabled = $ctx['subdomainEnabled'] ?? false;
    $subdomainBase = (string)($ctx['subdomainBase'] ?? '');
    $hashHost = null;
    $mcxHost = null;

    if ($subdomainEnabled) {
        $billingServiceId = pmssNginxUserBillingServiceIdFromHome($homeDir);
        if ($billingServiceId !== null) {
            $hashHost = pmssNginxUserHashHostname($thisUser, $billingServiceId, $subdomainBase);
            $mcxHost = pmssNginxUserMcxHostname($billingServiceId);
            // Customers with 2+ services also get a cluster name, round-robined
            // across their nodes by the zone builder. The client id is already on
            // this node, so no remote lookup is needed. Single-service users get a
            // name that never resolves — inert, nginx never sees a request for it.
            $billingClientId = pmssUserBillingClientIdDigitsRead($homeDir);
            if ($billingClientId !== null) {
                $mcxHost .= ' '.pmssNginxUserMcxClusterHostname($billingClientId);
            }
        }
    }

    // When a user is suspended, nginx should serve a static suspended page
    // instead of proxying to their per-user lighttpd instance.
    if ($isSuspended) {
        if ($suspendedTemplate === false || $suspendedTemplate === '') {
            // No dedicated suspended template found; skip generating a per-user
            // config so nginx falls back to generic defaults.
            pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, []);
            return PMSS_NGINX_USER_CONFIG_SKIPPED;
        }
        $writtenPaths = [];
        if ($subdomainEnabled) {
            if (!pmssCreateNginxConfigWriteSubdomainConfigs($ctx, $thisUser, $subdomainBase, $hashHost, true, null, $mcxHost)) return PMSS_NGINX_USER_CONFIG_WRITE_FAILED;
            $writtenPaths[] = $managedPaths['public'];
            if ($hashHost !== null) $writtenPaths[] = $managedPaths['private'];
        }
        $userConfig = str_replace('##username', $thisUser, $suspendedTemplate);
        if (!pmssCreateNginxConfigWriteFile($managedPaths['user'], $userConfig, $thisUser, 'user suspended config')) return PMSS_NGINX_USER_CONFIG_WRITE_FAILED;
        $writtenPaths[] = $managedPaths['user'];
        pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $writtenPaths);
        pmssCreateNginxConfigUserLog($thisUser, 'nginx config regenerated (suspended template)');
        return PMSS_NGINX_USER_CONFIG_GENERATED;
    }

    if (!file_exists($homeDir.'/.rtorrent.rc')) {
        pmssCreateNginxConfigLogSkippedUser($thisUser, 'missing .rtorrent.rc prerequisite');
        pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $singleUser ? [$managedPaths['user']] : []);
        return PMSS_NGINX_USER_CONFIG_SKIPPED;
    }

    $serverPort = pmssReadRegularFileInt($portFile);
    $needsLighttpdRefresh = !pmssNetworkPortInRange($serverPort, 1024) || !is_file($homeDir.'/.lighttpd.conf');
    if ($needsLighttpdRefresh) {
        passthru('/scripts/util/userConfigLighttpd.php '.escapeshellarg($thisUser));
        $serverPort = pmssReadRegularFileInt($portFile);
    }
    if (!pmssNetworkPortInRange($serverPort, 1024)) {
        pmssCreateNginxConfigLogSkippedUser($thisUser, 'lighttpd port missing or invalid after refresh attempt ('.$portFile.')');
        pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $singleUser ? [$managedPaths['user']] : []);
        return PMSS_NGINX_USER_CONFIG_SKIPPED;
    }

    $writtenPaths = [];
    if ($subdomainEnabled) {
        if (!pmssCreateNginxConfigWriteSubdomainConfigs($ctx, $thisUser, $subdomainBase, $hashHost, false, $serverPort, $mcxHost)) return PMSS_NGINX_USER_CONFIG_WRITE_FAILED;
        $writtenPaths[] = $managedPaths['public'];
        if ($hashHost !== null) $writtenPaths[] = $managedPaths['private'];
    }

    if ($userTemplate === false || $userTemplate === '') {
        if ($singleUser) $writtenPaths[] = $managedPaths['user'];
        pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $writtenPaths);
        return PMSS_NGINX_USER_CONFIG_SKIPPED;
    }

    $placeholders = array("##username", "##serverPort");
    $replacements = array($thisUser, $serverPort);
    if ($ctx['needsDelugeWebPort'] ?? false) {
        // Backward compatibility: older templates may still use ##delugeWebPort.
        $placeholders[] = "##delugeWebPort";
        $replacements[] = pmssCreateNginxConfigLegacyDelugeWebPort($homeDir, $thisUser);
    }

    $userConfig = str_replace($placeholders, $replacements, $userTemplate);

    if (!pmssCreateNginxConfigWriteFile($managedPaths['user'], $userConfig, $thisUser, 'user config')) return PMSS_NGINX_USER_CONFIG_WRITE_FAILED;
    $writtenPaths[] = $managedPaths['user'];
    pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $writtenPaths);
    pmssCreateNginxConfigUserLog($thisUser, 'nginx config regenerated');
    return PMSS_NGINX_USER_CONFIG_GENERATED;
}
