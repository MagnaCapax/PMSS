#!/usr/bin/php
<?php
/**
 * Nginx server config
 *
 * Creates nginx config for reverse proxying.
 *
 * @author Aleksi Ursin
 * @copyright NuCode 2015-2023 - All Rights reserved.
 * @since 31/03/2015
 * @version 1.1
 **/

$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
if (count($users) == 0) die("No users setup - nothing to do\n");

$template = file_get_contents("/etc/seedbox/config/template.nginx-user");
passthru("cp /etc/seedbox/config/template.nginx-conf /etc/nginx/nginx.conf");
passthru("cp /etc/seedbox/config/template.nginx-proxy_params /etc/nginx/proxy_params");

// Configure site default
//passthru("cp /etc/seedbox/config/template.nginx-site-default /etc/nginx/sites-available/default");
$serverHostname = trim(file_get_contents('/etc/hostname'));
$nginxConfigSiteDefault = file_get_contents('/etc/seedbox/config/template.nginx-site-default');
$nginxConfigSiteDefaultSsl = file_get_contents('/etc/seedbox/config/template.nginx-site-default-ssl');
$nginxConfigSiteDefaultSslLetsEncrypt = file_get_contents('/etc/seedbox/config/template.nginx-site-default-ssl-lets-encrypt');


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

// Create config and save it :)
$nginxConfigSiteDefault = str_replace('||SSL_SETTINGS_CONFIGURED_HERE||', $nginxConfigSiteDefaultSsl, $nginxConfigSiteDefault);
file_put_contents('/etc/nginx/sites-available/default', $nginxConfigSiteDefault);



// Create SSL config if required!
if (!file_exists("/etc/nginx/ssl")) {
    mkdir("/etc/nginx/ssl");
}

if (!file_exists("/etc/nginx/ssl/nginx.crt")) {
    $hostname = trim( file_get_contents("/etc/hostname") );
    $hostname = str_replace(array("\n", "\r"), '', $hostname);
    passthru('openssl req -x509 -nodes -days 365 -newkey rsa:2048 -subj "/C=FI/ST=none/L=none/O=PulsedMedia/CN=' . $hostname . '" -keyout /etc/nginx/ssl/nginx.key -out /etc/nginx/ssl/nginx.crt');
}

foreach($users AS $thisUser) {
    $portFile = "/etc/seedbox/runtime/ports/lighttpd-{$thisUser}";
    if (!file_exists("/home/{$thisUser}/.rtorrent.rc")) continue;
    
    if (!file_exists("/etc/nginx/users")) mkdir("/etc/nginx/users");
    	else passthru('rm /etc/nginx/users/*');	// Empty all previous configs to ensure no unnecessary ones there

    
    passthru("/scripts/util/configureLighttpd.php {$thisUser}");
    $serverPort = trim( file_get_contents($portFile) );
    $delugePort = (int) file_get_Contents("/home/{$thisUser}/.delugePort");
    if (empty($serverPort)) continue;
    
    $userConfig = str_replace(array("##username", "##serverPort", "##delugeWebPort"), array($thisUser, $serverPort, $delugePort + 1), $template);
    
    file_put_contents("/etc/nginx/users/{$thisUser}", $userConfig);
    
}

echo "## Done! You should restart nginx:\n/etc/init.d/nginx restart\n";
