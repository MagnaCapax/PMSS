<?php
/**
 * Permission hardening for generated nginx configs.
 *
 * @license GPL-3.0-only
 */

function pmssCreateNginxConfigApplyPermissions(string $subdomainConfigDir): void
{
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
}

