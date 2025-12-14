<?php
/**
 * Miscellaneous security tweaks applied post-update.
 */

require_once __DIR__.'/../runtime/commands.php';

if (!function_exists('pmssRemoveAutodlConfig')) {
    /**
     * Drop obsolete global autodl configuration file if it exists.
     */
    function pmssRemoveAutodlConfig(): void
    {
        if (file_exists('/etc/autodl.cfg')) {
            unlink('/etc/autodl.cfg');
        }
    }
}

if (!function_exists('pmssEnsureTestfile')) {
    /**
     * Ensure the standard download speed test file exists.
     * #TODO(testfile): consider templating/host-wide CDN instead of local 100MiB dd.
     */
    function pmssEnsureTestfile(): void
    {
        $path = '/var/www/testfile';
        if (file_exists($path) && filesize($path) === 104857600) {
            return;
        }
        $command = 'dd if=/dev/urandom of='.$path.' bs=1M count=100 status=none';
        runStep('Generating /var/www/testfile sample', $command);
    }
}

if (!function_exists('pmssRestrictAtopBinary')) {
    /**
     * Restrict atop execution permissions to privileged users.
     * #TODO(atop): evaluate whether this belongs in package templating instead.
     */
    function pmssRestrictAtopBinary(): void
    {
        runStep('Restricting atop binary permissions', 'chmod 750 /usr/bin/atop');
    }
}

if (!function_exists('pmssApplySecurityHardening')) {
    /**
     * Apply quick hardening tweaks for logs and network utilities.
     */
    function pmssApplySecurityHardening(): void
    {
        runStep('Hardening access to session and network binaries', 'chmod o-r /var/log/wtmp /var/run/utmp /usr/bin/netstat /usr/bin/who /usr/bin/w');
    }
}
