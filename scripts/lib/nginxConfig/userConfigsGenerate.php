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
 * Generate per-user configs under /etc/nginx/users and optional subdomain vhosts.
 */
function pmssCreateNginxConfigGenerateUser(string $thisUser, array $ctx, bool $singleUser): void
{
    $thisUser = trim($thisUser);
    if ($thisUser === '') {
        return;
    }
    if (!pmssValidateUsername($thisUser)) {
        return;
    }

    $homeDir = "/home/{$thisUser}";
    if (!is_dir($homeDir)) {
        return;
    }

    $portFile = "/etc/seedbox/runtime/ports/lighttpd-{$thisUser}";
    $isSuspended = is_dir($homeDir.'/www-disabled');

    $suspendedTemplate = $ctx['suspendedTemplate'] ?? false;
    $userTemplate = $ctx['userTemplate'] ?? false;
    $needsDelugeWebPort = $ctx['needsDelugeWebPort'] ?? false;
    $subdomainEnabled = $ctx['subdomainEnabled'] ?? false;
    $subdomainBase = (string)($ctx['subdomainBase'] ?? '');
    $subdomainConfigDir = (string)($ctx['subdomainConfigDir'] ?? '/etc/nginx/conf.d');
    $nginxSslBlock = (string)($ctx['nginxSslBlock'] ?? '');

    // When a user is suspended, nginx should serve a static suspended page
    // instead of proxying to their per-user lighttpd instance.
    if ($isSuspended) {
        if ($suspendedTemplate === false || $suspendedTemplate === '') {
            // No dedicated suspended template found; skip generating a per-user
            // config so nginx falls back to generic defaults.
            if ($singleUser) {
                @unlink("/etc/nginx/users/{$thisUser}");
            }
            return;
        }
        if ($subdomainEnabled) {
            $publicHost = $thisUser.'.'.$subdomainBase;
            $publicConfig = str_replace(
                array('##host##', '##user##', '##ssl_block##'),
                array($publicHost, $thisUser, $nginxSslBlock),
                (string)$ctx['publicSuspendedTemplate']
            );
            if (!pmssCreateNginxConfigWriteFile($subdomainConfigDir.'/pmss-user-'.$thisUser.'.conf', $publicConfig, $thisUser, 'public suspended subdomain config')) {
                return;
            }

            $billingId = pmssNginxUserBillingIdFromFile($homeDir.'/.billingId');
            if ($billingId !== null) {
                $hashHost = pmssNginxUserHashHostname($thisUser, $billingId, $subdomainBase);
                $hashConfig = str_replace(
                    array('##host##', '##user##', '##ssl_block##'),
                    array($hashHost, $thisUser, $nginxSslBlock),
                    (string)$ctx['privateSuspendedTemplate']
                );
                if (!pmssCreateNginxConfigWriteFile($subdomainConfigDir.'/pmss-user-'.$thisUser.'-hash.conf', $hashConfig, $thisUser, 'private suspended subdomain config')) {
                    return;
                }
            }
        }
        $userConfig = str_replace('##username', $thisUser, $suspendedTemplate);
        if (!pmssCreateNginxConfigWriteFile("/etc/nginx/users/{$thisUser}", $userConfig, $thisUser, 'user suspended config')) {
            return;
        }
        if (function_exists('pmssUserLog')) {
            pmssUserLog($thisUser, 'nginx config regenerated (suspended template)');
        }
        return;
    }

    if (!file_exists($homeDir.'/.rtorrent.rc')) {
        pmssCreateNginxConfigLogSkippedUser($thisUser, 'missing .rtorrent.rc prerequisite');
        return;
    }

    $serverPort = 0;
    if (is_readable($portFile)) {
        $serverPort = (int) trim((string) file_get_contents($portFile));
    }
    $needsLighttpdRefresh = ($serverPort < 1024 || $serverPort > 65535) || !is_file($homeDir.'/.lighttpd.conf');
    if ($needsLighttpdRefresh) {
        passthru('/scripts/util/userConfigLighttpd.php '.escapeshellarg($thisUser));
        $serverPort = (int) trim((string) @file_get_contents($portFile));
    }
    if ($serverPort < 1024 || $serverPort > 65535) {
        pmssCreateNginxConfigLogSkippedUser($thisUser, 'lighttpd port missing or invalid after refresh attempt ('.$portFile.')');
        return;
    }

    if ($subdomainEnabled) {
        $publicHost = $thisUser.'.'.$subdomainBase;
        $publicConfig = str_replace(
            array('##host##', '##user##', '##port##', '##ssl_block##'),
            array($publicHost, $thisUser, (string)$serverPort, $nginxSslBlock),
            (string)$ctx['publicSubdomainTemplate']
        );
        if (!pmssCreateNginxConfigWriteFile($subdomainConfigDir.'/pmss-user-'.$thisUser.'.conf', $publicConfig, $thisUser, 'public subdomain config')) {
            return;
        }

        $billingId = pmssNginxUserBillingIdFromFile($homeDir.'/.billingId');
        if ($billingId !== null) {
            $hashHost = pmssNginxUserHashHostname($thisUser, $billingId, $subdomainBase);
            $hashConfig = str_replace(
                array('##host##', '##user##', '##port##', '##ssl_block##'),
                array($hashHost, $thisUser, (string)$serverPort, $nginxSslBlock),
                (string)$ctx['privateSubdomainTemplate']
            );
            if (!pmssCreateNginxConfigWriteFile($subdomainConfigDir.'/pmss-user-'.$thisUser.'-hash.conf', $hashConfig, $thisUser, 'private subdomain config')) {
                return;
            }
        }
    }

    if ($userTemplate === false || $userTemplate === '') {
        return;
    }

    $placeholders = array("##username", "##serverPort");
    $replacements = array($thisUser, $serverPort);
    if ($needsDelugeWebPort) {
        // Backward compatibility: some older templates proxy /deluge-<user>/ directly
        // to deluge-web (historically scgi+1). Keep supporting the placeholder, but
        // treat the port file as untrusted input (must be root-owned + non-symlink).
        $delugeWebPort = 1;
        $delugePortPath = $homeDir.'/.delugePort';
        if (is_file($delugePortPath) && !is_link($delugePortPath)) {
            $owner = @fileowner($delugePortPath);
            if ($owner !== false && (int)$owner === 0) {
                $raw = @file_get_contents($delugePortPath);
                if (is_string($raw)) {
                    $raw = trim($raw);
                    if ($raw !== '' && ctype_digit($raw)) {
                        $delugePort = (int) $raw;
                        if ($delugePort >= 1024 && $delugePort <= 65534) {
                            $delugeWebPort = $delugePort + 1;
                        } elseif (function_exists('pmssUserLog')) {
                            pmssUserLog($thisUser, '[WARN] Ignoring invalid .delugePort value while rendering nginx template');
                        }
                    } elseif (function_exists('pmssUserLog')) {
                        pmssUserLog($thisUser, '[WARN] Ignoring non-numeric .delugePort value while rendering nginx template');
                    }
                }
            } elseif (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, '[WARN] Ignoring non-root-owned .delugePort while rendering nginx template');
            }
        } elseif (is_link($delugePortPath) && function_exists('pmssUserLog')) {
            pmssUserLog($thisUser, '[WARN] Ignoring symlinked .delugePort while rendering nginx template');
        }
        $placeholders[] = "##delugeWebPort";
        $replacements[] = $delugeWebPort;
    }

    $userConfig = str_replace($placeholders, $replacements, $userTemplate);

    if (!pmssCreateNginxConfigWriteFile("/etc/nginx/users/{$thisUser}", $userConfig, $thisUser, 'user config')) {
        return;
    }
    if (function_exists('pmssUserLog')) {
        pmssUserLog($thisUser, 'nginx config regenerated');
    }
}
