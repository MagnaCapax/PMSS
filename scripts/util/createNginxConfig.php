#!/usr/bin/env php
<?php
/**
 * Nginx server config
 *
 * Creates nginx config for reverse proxying per-user lighttpd instances.
 *
 * Suspension handling:
 * - The canonical suspended marker is `/home/<user>/www-disabled`. When present,
 *   nginx uses the suspended template instead of proxying.
 *
 * This script intentionally stays focused on nginx config generation. It will
 * only invoke per-user lighttpd config regeneration when required to discover
 * a missing lighttpd port assignment on legacy hosts.
 *
 * @author Aleksi Ursin
 * @copyright NuCode 2015-2023 - All Rights reserved.
 * @since 31/03/2015
 * @version 1.1
 **/

require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/nginxUserHosts.php';
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}

$usage = <<<TXT
Usage:
  /scripts/util/createNginxConfig.php [--user USERNAME] [--restart]
  /scripts/util/createNginxConfig.php USERNAME [--restart]

Options:
  --user, -u USERNAME  Only regenerate nginx config for USERNAME (keeps other user configs intact)
  --restart, -r        Restart nginx after writing configs
  --help, -h           Show this help

TXT;

$parsed = pmssParseCliTokens($argv);
if (pmssCliOption($parsed, 'help', 'h', false) !== false) {
    echo $usage;
    exit(0);
}

$requestedUser = strtolower(trim((string) pmssCliOption($parsed, 'user', 'u', '')));
$positionals = $parsed['arguments'] ?? [];
if ($requestedUser === '' && count($positionals) === 1) {
    $requestedUser = strtolower(trim((string) $positionals[0]));
} elseif (count($positionals) > 1) {
    fwrite(STDERR, $usage);
    exit(1);
}

$restartNginx = pmssCliOption($parsed, 'restart', 'r', false) !== false;

if ($requestedUser !== '' && !pmssValidateUsername($requestedUser)) {
    fwrite(STDERR, "Invalid username: {$requestedUser}\n");
    exit(1);
}

// Treat internal tool output as untrusted: validate every username and require
// a clean exit code so broken environments do not emit garbage as user data.
$userLines = [];
$userRc = 0;
exec('/scripts/listUsers.php 2>/dev/null', $userLines, $userRc);
if ($userRc !== 0) {
    fwrite(STDERR, "Error: /scripts/listUsers.php failed (rc={$userRc}); aborting.\n");
    exit(1);
}
if (empty($userLines)) {
    die("No users setup - nothing to do\n");
}

$usersFiltered = [];
foreach ($userLines as $name) {
    $name = strtolower(trim((string)$name));
    if ($name === '' || !pmssValidateUsername($name)) {
        continue;
    }
    $usersFiltered[$name] = true;
}
$users = array_keys($usersFiltered);
sort($users, SORT_NATURAL | SORT_FLAG_CASE);

$singleUser = false;
if ($requestedUser !== '') {
    if (!in_array($requestedUser, $users, true)) {
        fwrite(STDERR, "Username not found: {$requestedUser}\n");
        exit(1);
    }
    $users = [$requestedUser];
    $singleUser = true;
}

$userTemplate = @file_get_contents("/etc/seedbox/config/template.nginx-user");
$suspendedTemplate = @file_get_contents("/etc/seedbox/config/template.nginx-user-suspended");

// Ensure nginx directories exist to avoid noisy cp/mkdir errors on fresh hosts.
if (!is_dir('/etc/nginx')) {
    @mkdir('/etc/nginx', 0755, true);
}
if (!is_dir('/etc/nginx/sites-available')) {
    @mkdir('/etc/nginx/sites-available', 0755, true);
}

@copy('/etc/seedbox/config/template.nginx-conf', '/etc/nginx/nginx.conf');
@copy('/etc/seedbox/config/template.nginx-proxy_params', '/etc/nginx/proxy_params');

// Configure site default
//passthru("cp /etc/seedbox/config/template.nginx-site-default /etc/nginx/sites-available/default");
$serverHostname = trim(file_get_contents('/etc/hostname'));
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
    $nginxConfigSiteDefault = str_replace('||SSL_SETTINGS_CONFIGURED_HERE||', (string)$nginxConfigSiteDefaultSsl, $nginxConfigSiteDefault);
    @file_put_contents('/etc/nginx/sites-available/default', $nginxConfigSiteDefault);
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

$publicSubdomainTemplate = <<<'NGINX'
# PMSS public subdomain for ##user## (maps to /public-##user##/).
server {
    listen 80;
    server_name ##host##;

    location / {
        proxy_pass http://127.0.0.1:##port##/public-##user##/;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 100m;
        limit_rate 32768k;
        limit_conn addr 8;
    }
}

server {
    listen 443 ssl;
    server_name ##host##;

##ssl_block##
    location / {
        proxy_pass http://127.0.0.1:##port##/public-##user##/;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 100m;
        limit_rate 32768k;
        limit_conn addr 8;
    }
}
NGINX;

$privateSubdomainTemplate = <<<'NGINX'
# PMSS private subdomain for ##user## (maps to /user-##user##/).
# Private area: do not add public locations to this vhost.
server {
    listen 80;
    server_name ##host##;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name ##host##;

##ssl_block##
    location / {
        proxy_pass http://127.0.0.1:##port##/user-##user##/;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        include /etc/nginx/proxy_params;
        proxy_http_version 1.1;
        limit_rate_after 1024m;
        limit_rate 102400k;
        limit_conn addr 16;
        error_page 502 /error-502.html;
    }

    location = /error-502.html {
        root /var/www;
        internal;
    }
}
NGINX;

$publicSuspendedTemplate = <<<'NGINX'
# PMSS suspended subdomain for ##user##.
server {
    listen 80;
    server_name ##host##;
    root /var/www;

    location = /error-suspended.html {
        root /var/www;
    }
    location / {
        return 302 /error-suspended.html;
    }
}

server {
    listen 443 ssl;
    server_name ##host##;
    root /var/www;

##ssl_block##
    location = /error-suspended.html {
        root /var/www;
    }
    location / {
        return 302 /error-suspended.html;
    }
}
NGINX;

$privateSuspendedTemplate = <<<'NGINX'
# PMSS suspended private subdomain for ##user##.
server {
    listen 80;
    server_name ##host##;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name ##host##;
    root /var/www;

##ssl_block##
    location = /error-suspended.html {
        root /var/www;
    }
    location / {
        return 302 /error-suspended.html;
    }
}
NGINX;

foreach($users AS $thisUser) {
    $thisUser = trim($thisUser);
    if ($thisUser === '') {
        continue;
    }
    if (!pmssValidateUsername($thisUser)) {
        continue;
    }

    $homeDir = "/home/{$thisUser}";
    if (!is_dir($homeDir)) {
        continue;
    }

    $portFile = "/etc/seedbox/runtime/ports/lighttpd-{$thisUser}";
    $isSuspended = is_dir($homeDir.'/www-disabled');

    // When a user is suspended, nginx should serve a static suspended page
    // instead of proxying to their per-user lighttpd instance.
    if ($isSuspended) {
        if ($suspendedTemplate === false || $suspendedTemplate === '') {
            // No dedicated suspended template found; skip generating a per-user
            // config so nginx falls back to generic defaults.
            if ($singleUser) {
                @unlink("/etc/nginx/users/{$thisUser}");
            }
            continue;
        }
        if ($subdomainEnabled) {
            $publicHost = $thisUser.'.'.$subdomainBase;
            $publicConfig = str_replace(
                array('##host##', '##user##', '##ssl_block##'),
                array($publicHost, $thisUser, $nginxSslBlock),
                $publicSuspendedTemplate
            );
            file_put_contents($subdomainConfigDir.'/pmss-user-'.$thisUser.'.conf', $publicConfig);

            $billingId = pmssNginxUserBillingIdFromFile($homeDir.'/.billingId');
            if ($billingId !== null) {
                $hashHost = pmssNginxUserHashHostname($thisUser, $billingId, $subdomainBase);
                $hashConfig = str_replace(
                    array('##host##', '##user##', '##ssl_block##'),
                    array($hashHost, $thisUser, $nginxSslBlock),
                    $privateSuspendedTemplate
                );
                file_put_contents($subdomainConfigDir.'/pmss-user-'.$thisUser.'-hash.conf', $hashConfig);
            }
        }
        $userConfig = str_replace('##username', $thisUser, $suspendedTemplate);
        file_put_contents("/etc/nginx/users/{$thisUser}", $userConfig);
        if (function_exists('pmssUserLog')) {
            pmssUserLog($thisUser, 'nginx config regenerated (suspended template)');
        }
        continue;
    }

    if (!file_exists($homeDir.'/.rtorrent.rc')) {
        continue;
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
    $delugePort = (int) file_get_Contents($homeDir."/.delugePort");
    if ($serverPort < 1024 || $serverPort > 65535) {
        continue;
    }

    if ($subdomainEnabled) {
        $publicHost = $thisUser.'.'.$subdomainBase;
        $publicConfig = str_replace(
            array('##host##', '##user##', '##port##', '##ssl_block##'),
            array($publicHost, $thisUser, (string)$serverPort, $nginxSslBlock),
            $publicSubdomainTemplate
        );
        file_put_contents($subdomainConfigDir.'/pmss-user-'.$thisUser.'.conf', $publicConfig);

        $billingId = pmssNginxUserBillingIdFromFile($homeDir.'/.billingId');
        if ($billingId !== null) {
            $hashHost = pmssNginxUserHashHostname($thisUser, $billingId, $subdomainBase);
            $hashConfig = str_replace(
                array('##host##', '##user##', '##port##', '##ssl_block##'),
                array($hashHost, $thisUser, (string)$serverPort, $nginxSslBlock),
                $privateSubdomainTemplate
            );
            file_put_contents($subdomainConfigDir.'/pmss-user-'.$thisUser.'-hash.conf', $hashConfig);
        }
    }
    
    if ($userTemplate === false || $userTemplate === '') {
        continue;
    }

    $userConfig = str_replace(
        array("##username", "##serverPort", "##delugeWebPort"),
        array($thisUser, $serverPort, $delugePort + 1),
        $userTemplate
    );
    
    file_put_contents("/etc/nginx/users/{$thisUser}", $userConfig);
    if (function_exists('pmssUserLog')) {
        pmssUserLog($thisUser, 'nginx config regenerated');
    }
    
}

// Disallow config reading by anyone else
$newConfigs = glob('/etc/nginx/users/*');
if ($newConfigs !== false && count($newConfigs) > 0) {
    passthru('chmod 640 /etc/nginx/users/*');
}
if (is_dir($subdomainConfigDir)) {
    $subdomainConfigs = glob($subdomainConfigDir.'/pmss-user-*.conf');
    if ($subdomainConfigs !== false && count($subdomainConfigs) > 0) {
        passthru('chmod 640 '.$subdomainConfigDir.'/pmss-user-*.conf');
    }
}
passthru('chmod 640 /etc/nginx/*.conf');

if ($restartNginx) {
    passthru('systemctl restart nginx || /etc/init.d/nginx restart || true');
    echo "## Done! nginx restarted\n";
} else {
    echo "## Done! You should restart nginx:\nsystemctl restart nginx || /etc/init.d/nginx restart\n";
}
