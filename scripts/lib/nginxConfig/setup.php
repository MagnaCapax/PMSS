<?php
/**
 * Nginx config setup helpers for createNginxConfig.php.
 *
 * Responsible for preparing /etc/nginx paths, copying templates, and computing
 * SSL/subdomain context required for per-user vhost generation.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/templates.php';
require_once __DIR__.'/../configBackups.php';

/**
 * Ensure the default nginx site defines default_server on its listen directives.
 *
 * Older hosts may still carry stale site templates without default_server, which
 * causes nginx to treat the first loaded vhost as the implicit default (conf.d
 * loads before sites-enabled by default).
 */
function pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer(string $config): string
{
    $config = preg_replace_callback('/^(\\s*listen\\s+80\\b)([^;]*)(;.*)$/m', static function (array $match) {
        if (preg_match('/\\bdefault_server\\b/', $match[2])) {
            return $match[0];
        }
        return $match[1].rtrim($match[2]).' default_server'.$match[3];
    }, $config);

    $config = preg_replace_callback('/^(\\s*listen\\s+443\\b)([^;]*)(;.*)$/m', static function (array $match) {
        if (!preg_match('/\\bssl\\b/', $match[2])) {
            return $match[0];
        }
        if (preg_match('/\\bdefault_server\\b/', $match[2])) {
            return $match[0];
        }
        return $match[1].rtrim($match[2]).' default_server'.$match[3];
    }, $config);

    return $config;
}

/**
 * Prepare nginx config directories and compute render context.
 *
 * @return array<string,mixed>
 */
function pmssCreateNginxConfigSetup(string $requestedUser, bool $singleUser): array
{
    $userTemplate = @file_get_contents("/etc/seedbox/config/template.nginx-user");
    $suspendedTemplate = @file_get_contents("/etc/seedbox/config/template.nginx-user-suspended");
    $needsDelugeWebPort = is_string($userTemplate) && strpos($userTemplate, '##delugeWebPort') !== false;

    // Ensure nginx directories exist to avoid noisy cp/mkdir errors on fresh hosts.
    if (!is_dir('/etc/nginx')) {
        @mkdir('/etc/nginx', 0755, true);
    }
    if (!is_dir('/etc/nginx/sites-available')) {
        @mkdir('/etc/nginx/sites-available', 0755, true);
    }
    if (!is_dir('/etc/nginx/sites-enabled')) {
        @mkdir('/etc/nginx/sites-enabled', 0755, true);
    }

    pmssBackupCriticalConfig('nginx', '/etc/nginx/nginx.conf');
    @copy('/etc/seedbox/config/template.nginx-conf', '/etc/nginx/nginx.conf');
    pmssBackupCriticalConfig('nginx', '/etc/nginx/proxy_params');
    @copy('/etc/seedbox/config/template.nginx-proxy_params', '/etc/nginx/proxy_params');

    // Configure site default
    //passthru("cp /etc/seedbox/config/template.nginx-site-default /etc/nginx/sites-available/default");
    $serverHostname = trim((string)@file_get_contents('/etc/hostname'));
    // /etc/hostname should be a single token; trim defensively to avoid whitespace surprises.
    $serverHostnameParts = preg_split('/\\s+/', $serverHostname);
    $serverHostname = is_array($serverHostnameParts) && isset($serverHostnameParts[0]) ? (string)$serverHostnameParts[0] : $serverHostname;
    $subdomainBase = strtolower($serverHostname);
    $subdomainEnabled = pmssNginxUserHostIsValidFqdn($subdomainBase);
    $subdomainConfigDir = '/etc/nginx/conf.d';
    $nginxConfigSiteDefault = @file_get_contents('/etc/seedbox/config/template.nginx-site-default');
    $nginxConfigSiteDefaultSsl = @file_get_contents('/etc/seedbox/config/template.nginx-site-default-ssl');
    $nginxConfigSiteDefaultSslLetsEncrypt = @file_get_contents('/etc/seedbox/config/template.nginx-site-default-ssl-lets-encrypt');


    // Do we have let's encrypt cert done?
    $certificatePath = "/etc/letsencrypt/live/{$serverHostname}";
    if (file_exists("{$certificatePath}/fullchain.pem") &&
        file_exists("{$certificatePath}/privkey.pem")   &&
        file_exists('/etc/letsencrypt/options-ssl-nginx.conf') &&
        file_exists('/etc/letsencrypt/ssl-dhparams.pem') &&

        is_readable("{$certificatePath}/fullchain.pem") &&
        is_readable("{$certificatePath}/privkey.pem")   &&
        is_readable('/etc/letsencrypt/options-ssl-nginx.conf') &&
        is_readable('/etc/letsencrypt/ssl-dhparams.pem') ) {

        // Insert server hostname on Let's Encrypt template AND put it on the default SSL config
        $nginxConfigSiteDefaultSsl = str_replace('||SERVER_HOSTNAME||', $serverHostname, $nginxConfigSiteDefaultSslLetsEncrypt);


    }

    $nginxSslBlock = '';
    if ($nginxConfigSiteDefaultSsl !== false) {
        $sslCandidate = trim((string)$nginxConfigSiteDefaultSsl);
        if ($sslCandidate !== '') {
            $nginxSslBlock = rtrim($sslCandidate)."\n";
        }
    }

    // Create config and save it :)
    if ($nginxConfigSiteDefault !== false) {
        $nginxConfigSiteDefault = pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer((string)$nginxConfigSiteDefault);

        // Ensure requests to the base hostname (FQDN) land on the main vhost where
        // user location blocks (including legacy Deluge redirects) are included.
        // This prevents unexpected fallback to user subdomain vhosts on some hosts.
        if ($subdomainEnabled && $subdomainBase !== '' && $subdomainBase !== 'localhost') {
            $nginxConfigSiteDefault = str_replace(
                'server_name localhost;',
                'server_name localhost '.$subdomainBase.';',
                $nginxConfigSiteDefault
            );
        }

        $nginxConfigSiteDefault = str_replace('||SSL_SETTINGS_CONFIGURED_HERE||', (string)$nginxConfigSiteDefaultSsl, $nginxConfigSiteDefault);
        pmssBackupCriticalConfig('nginx', '/etc/nginx/sites-available/default');
        @file_put_contents('/etc/nginx/sites-available/default', $nginxConfigSiteDefault);
        $enabledDefault = '/etc/nginx/sites-enabled/default';
        if (!file_exists($enabledDefault)) {
            if (@symlink('/etc/nginx/sites-available/default', $enabledDefault) === false) {
                @file_put_contents($enabledDefault, $nginxConfigSiteDefault);
            }
        } elseif (!is_link($enabledDefault)) {
            // Keep the enabled copy in sync on hosts where default is not a symlink.
            pmssBackupCriticalConfig('nginx', $enabledDefault);
            @file_put_contents($enabledDefault, $nginxConfigSiteDefault);
        }
    }



    // Create SSL config if required!
    if (!file_exists("/etc/nginx/ssl")) {
        @mkdir("/etc/nginx/ssl", 0755, true);
    }

    if (!file_exists("/etc/nginx/ssl/nginx.crt")) {
        $hostname = trim( file_get_contents("/etc/hostname") );
        $hostname = str_replace(array("\n", "\r"), '', $hostname);
        // Generate a self-signed cert if Let's Encrypt not present yet (ignore errors on systems without openssl)
        @passthru('openssl req -x509 -nodes -days 365 -newkey rsa:2048 -subj "/C=FI/ST=none/L=none/O=PulsedMedia/CN=' . $hostname . '" -keyout /etc/nginx/ssl/nginx.key -out /etc/nginx/ssl/nginx.crt');
    }

    if (!file_exists("/etc/nginx/users")) {
        mkdir("/etc/nginx/users", 0751);
    } elseif (!$singleUser) {
        $existingConfigs = glob('/etc/nginx/users/*');
        if ($existingConfigs !== false) {
            foreach ($existingConfigs as $oldConfig) {
                @unlink($oldConfig);
            }
        }
    }

    if ($subdomainEnabled && !is_dir($subdomainConfigDir)) {
        @mkdir($subdomainConfigDir, 0755, true);
    }
    if ($subdomainEnabled) {
        $managedPattern = $subdomainConfigDir.'/pmss-user-*.conf';
        if (!$singleUser) {
            $existingSubdomains = glob($managedPattern);
            if ($existingSubdomains !== false) {
                foreach ($existingSubdomains as $oldConfig) {
                    @unlink($oldConfig);
                }
            }
        } elseif ($requestedUser !== '') {
            @unlink($subdomainConfigDir.'/pmss-user-'.$requestedUser.'.conf');
            @unlink($subdomainConfigDir.'/pmss-user-'.$requestedUser.'-hash.conf');
        }
    } elseif ($subdomainBase !== '') {
        fwrite(STDERR, "Skipping nginx subdomain vhosts (invalid hostname: {$subdomainBase})\n");
    }

    $templates = pmssNginxUserSubdomainTemplates();
    return [
        'userTemplate' => $userTemplate,
        'suspendedTemplate' => $suspendedTemplate,
        'needsDelugeWebPort' => $needsDelugeWebPort,
        'subdomainEnabled' => $subdomainEnabled,
        'subdomainBase' => $subdomainBase,
        'subdomainConfigDir' => $subdomainConfigDir,
        'nginxSslBlock' => $nginxSslBlock,
        'publicSubdomainTemplate' => $templates['public'],
        'privateSubdomainTemplate' => $templates['private'],
        'publicSuspendedTemplate' => $templates['publicSuspended'],
        'privateSuspendedTemplate' => $templates['privateSuspended'],
    ];
}
