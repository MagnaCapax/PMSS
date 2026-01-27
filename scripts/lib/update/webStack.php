<?php
/**
 * Web stack configuration helpers for update-step2.
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/runtime/processes.php';
// #TODO Create services/systemd.php with helpers stopIfPresent()/restartIfPresent()
//       and refactor scattered stop/restart logic across modules to use it. (GH #128)

if (!function_exists('pmssConfigureWebStack')) {
    /**
     * Switch legacy lighttpd instances to nginx and refresh configs.
     */
    function pmssConfigureWebStack(int $distroVersion): void
    {
        // Stop nginx first so package upgrades and template refreshes never race against an active daemon.
        runStep('Stopping nginx prior to configuration refresh', 'systemctl stop nginx || /etc/init.d/nginx stop || true');
        if ($distroVersion < 10) {
            runStep('Stopping lighttpd (init.d)', '/etc/init.d/lighttpd stop');
            runStep('Disabling lighttpd from sysvinit runlevels', 'update-rc.d lighttpd stop 2 3 4 5');
            runStep('Removing lighttpd sysvinit hooks', 'update-rc.d lighttpd remove');
            killProcess('lighttpd', 'Terminating lingering lighttpd processes');
            killProcess('php-cgi', 'Terminating lingering php-cgi processes');
            runStep('Ensuring nginx defaults set in sysvinit', 'update-rc.d nginx defaults');
        } else {
            runStep('Stopping lighttpd (systemd)', '/etc/init.d/lighttpd stop');
            disableUnitIfPresent('lighttpd', 'Disabling lighttpd systemd service');
            killProcess('lighttpd', 'Terminating lingering lighttpd processes');
            killProcess('php-cgi', 'Terminating lingering php-cgi processes');
            enableUnitIfPresent('nginx', 'Enabling nginx systemd service');
        }

        runStep('Refreshing lighttpd configuration', '/scripts/util/userConfigLighttpd.php');
        // nginx config regeneration moved to pmssPostUpdateWebRefresh() - no need to
        // run twice. nginx stays stopped until final config refresh at end of update.
        runStep('Verifying user HTTP authentication files', '/scripts/util/checkUserHtpasswd.php');
        runStep('Checking lighttpd per-user instances', '/scripts/cron/checkLighttpdInstances.php');
        runStep('Setting /home directory permissions', 'chmod 751 /home');
        // Quota state files reject chmod; prune them so the find commands stay noise-free.
        $prune = '\( -name "aquota.*" -o -name "lost+found" \)';
        runStep(
            'Hardening /home tenant directories',
            'find /home -mindepth 1 -maxdepth 1 '.$prune.' -prune -o -type d -exec chmod 700 {} +'
        );
        runStep(
            'Hardening /home tenant files',
            'find /home -mindepth 1 -maxdepth 1 '.$prune.' -prune -o -type f -exec chmod 600 {} +'
        );
    }
}

if (!function_exists('pmssAdjustLighttpdSecurity')) {
    /**
     * Ensure lighttpd configuration files have tight ownership and ACLs.
     */
    function pmssAdjustLighttpdSecurity(): void
    {
        $configDir  = '/etc/lighttpd';
        $configFile = $configDir.'/lighttpd.conf';
        $htpasswd   = $configDir.'/.htpasswd';

        if (!is_dir($configDir)) {
            logmsg('[SKIP] /etc/lighttpd missing; skipping lighttpd hardening');
            return;
        }

        runStep('Restricting /etc/lighttpd directory permissions', 'chmod 750 /etc/lighttpd');

        if (is_file($configFile)) {
            runStep('Adjusting /etc/lighttpd/lighttpd.conf permissions', 'chmod 750 '.$configFile);
            runStep('Setting ownership on /etc/lighttpd/lighttpd.conf', 'chown root:root '.$configFile);
        } else {
            logmsg('[SKIP] lighttpd.conf missing; skipping lighttpd permission adjustments');
        }

        if (is_file($htpasswd)) {
            runStep('Setting ownership on /etc/lighttpd/.htpasswd', 'chown root:root '.$htpasswd);
            runStep('Adjusting /etc/lighttpd/.htpasswd permissions', 'chmod 640 '.$htpasswd);
        } else {
            logmsg('[SKIP] lighttpd .htpasswd missing; per-user instances manage authentication');
        }
    }
}

if (!function_exists('pmssPostUpdateWebRefresh')) {
    /**
     * Re-run web service configuration after application installers finish.
     *
     * This is the single authoritative nginx config regeneration point during updates.
     * createNginxConfig.php validates config with nginx -t before restart, refusing
     * to restart if config is broken (added 2026-01, issue #137).
     */
    function pmssPostUpdateWebRefresh(): void
    {
        runStep('Post-update lighttpd configuration refresh', '/scripts/util/userConfigLighttpd.php');
        runStep('Post-update nginx configuration refresh', '/scripts/util/createNginxConfig.php --restart');
        runStep('Post-update htpasswd verification', '/scripts/util/checkUserHtpasswd.php');
        runStep('Checking lighttpd instances after update', '/scripts/cron/checkLighttpdInstances.php');
    }
}
