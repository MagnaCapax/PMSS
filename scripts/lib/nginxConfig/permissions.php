<?php
/**
 * Permission hardening for generated nginx configs.
 *
 * @license GPL-3.0-only
 */

function pmssCreateNginxConfigApplyPermissions(string $subdomainConfigDir): void
{
    // Disallow config reading by anyone else
    if (glob('/etc/nginx/users/*')) {
        passthru('chmod 640 /etc/nginx/users/*');
    }
    if (is_dir($subdomainConfigDir)) {
        if (glob($subdomainConfigDir.'/pmss-user-*.conf')) {
            passthru('chmod 640 '.$subdomainConfigDir.'/pmss-user-*.conf');
        }
    }
    passthru('chmod 640 /etc/nginx/*.conf');
}
