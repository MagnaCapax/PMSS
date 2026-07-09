<?php
/**
 * Per-user nginx config generation for createNginxConfig.php.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../lighttpd/userFileWrite.php';

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
 * Remove a stale managed nginx config without following unsafe filesystem edges.
 */
function pmssCreateNginxConfigRemoveFile(string $path, string $user, string $label): bool
{
    if (!pmssUserFilePathIsSafe($path)) {
        pmssCreateNginxConfigLogSkippedUser($user, 'unsafe '.$label.' path ('.$path.')');
        return false;
    }
    if (!file_exists($path)) {
        return true;
    }
    if (is_link($path) || !is_file($path)) {
        pmssCreateNginxConfigLogSkippedUser($user, 'refusing to remove non-regular '.$label.' ('.$path.')');
        return false;
    }
    if (@unlink($path)) {
        return true;
    }

    pmssCreateNginxConfigLogSkippedUser($user, 'failed to remove '.$label.' ('.$path.')');

    return false;
}

/**
 * Write public/private subdomain vhosts from the shared render context.
 */
function pmssCreateNginxConfigWriteSubdomainConfigs(array $ctx, string $user, string $subdomainBase, ?string $hashHost, bool $suspended, ?int $serverPort = null, ?string $mcxHost = null): bool
{
    $replacements = [
        '##user##' => $user,
        '##ssl_block##' => (string) ($ctx['nginxSslBlock'] ?? ''),
    ];
    if ($serverPort !== null) {
        $replacements['##port##'] = (string) $serverPort;
    }

    $prefix = $suspended ? 'Suspended' : 'Subdomain';
    $label = $suspended ? ' suspended' : '';
    // Public vhost also answers the user's stable mcx.fi hostname (which resolves
    // via the mcx.fi zone builder), serving the same www/public content.
    $publicHost = $user.'.'.$subdomainBase.($mcxHost !== null && $mcxHost !== '' ? ' '.$mcxHost : '');
    foreach ([[$publicHost, 'public'.$prefix.'Template', '', 'public'.$label.' subdomain config'], [$hashHost, 'private'.$prefix.'Template', '-hash', 'private'.$label.' subdomain config']] as $target) {
        if ($target[0] === null) {
            continue;
        }
        $config = strtr((string) ($ctx[$target[1]] ?? ''), $replacements + ['##host##' => $target[0]]);
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
 * Generate per-user configs under /etc/nginx/users and optional subdomain vhosts.
 */
function pmssCreateNginxConfigGenerateUser(string $thisUser, array $ctx, bool $singleUser): void
{
    $thisUser = trim($thisUser);
    if ($thisUser === '' || !pmssValidateUsername($thisUser)) return;

    $homeDir = "/home/{$thisUser}";
    if (!is_dir($homeDir)) return;

    $portFile = "/etc/seedbox/runtime/ports/lighttpd-{$thisUser}";
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
        }
    }

    // When a user is suspended, nginx should serve a static suspended page
    // instead of proxying to their per-user lighttpd instance.
    if ($isSuspended) {
        if ($suspendedTemplate === false || $suspendedTemplate === '') {
            // No dedicated suspended template found; skip generating a per-user
            // config so nginx falls back to generic defaults.
            if ($singleUser) {
                pmssCreateNginxConfigRemoveFile("/etc/nginx/users/{$thisUser}", $thisUser, 'stale user config');
            }
            return;
        }
        if ($subdomainEnabled && !pmssCreateNginxConfigWriteSubdomainConfigs($ctx, $thisUser, $subdomainBase, $hashHost, true, null, $mcxHost)) return;
        $userConfig = str_replace('##username', $thisUser, $suspendedTemplate);
        if (!pmssCreateNginxConfigWriteFile("/etc/nginx/users/{$thisUser}", $userConfig, $thisUser, 'user suspended config')) return;
        pmssCreateNginxConfigUserLog($thisUser, 'nginx config regenerated (suspended template)');
        return;
    }

    if (!file_exists($homeDir.'/.rtorrent.rc')) {
        pmssCreateNginxConfigLogSkippedUser($thisUser, 'missing .rtorrent.rc prerequisite');
        return;
    }

    $serverPort = pmssReadRegularFileInt($portFile);
    $needsLighttpdRefresh = !pmssNetworkPortInRange($serverPort, 1024) || !is_file($homeDir.'/.lighttpd.conf');
    if ($needsLighttpdRefresh) {
        passthru('/scripts/util/userConfigLighttpd.php '.escapeshellarg($thisUser));
        $serverPort = pmssReadRegularFileInt($portFile);
    }
    if (!pmssNetworkPortInRange($serverPort, 1024)) {
        pmssCreateNginxConfigLogSkippedUser($thisUser, 'lighttpd port missing or invalid after refresh attempt ('.$portFile.')');
        return;
    }

    if ($subdomainEnabled && !pmssCreateNginxConfigWriteSubdomainConfigs($ctx, $thisUser, $subdomainBase, $hashHost, false, $serverPort, $mcxHost)) return;

    if ($userTemplate === false || $userTemplate === '') return;

    $placeholders = array("##username", "##serverPort");
    $replacements = array($thisUser, $serverPort);
    if ($ctx['needsDelugeWebPort'] ?? false) {
        // Backward compatibility: older templates may still use ##delugeWebPort.
        $placeholders[] = "##delugeWebPort";
        $replacements[] = pmssCreateNginxConfigLegacyDelugeWebPort($homeDir, $thisUser);
    }

    $userConfig = str_replace($placeholders, $replacements, $userTemplate);

    if (!pmssCreateNginxConfigWriteFile("/etc/nginx/users/{$thisUser}", $userConfig, $thisUser, 'user config')) return;
    pmssCreateNginxConfigUserLog($thisUser, 'nginx config regenerated');
}
