<?php
/**
 * Web stack configuration helpers for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/runtime/processes.php';

/**
 * Switch legacy lighttpd instances to nginx and refresh configs.
 */
function pmssConfigureWebStack(int $distroVersion): void
{
    // Stop nginx first so package upgrades and template refreshes never race against an active daemon.
    runStep('Stopping nginx prior to configuration refresh', 'systemctl stop nginx || /etc/init.d/nginx stop || true');
    runStep($distroVersion < 10 ? 'Stopping lighttpd (init.d)' : 'Stopping lighttpd (systemd)', '/etc/init.d/lighttpd stop');
    if ($distroVersion < 10) {
        runStep('Disabling lighttpd from sysvinit runlevels', 'update-rc.d lighttpd stop 2 3 4 5');
        runStep('Removing lighttpd sysvinit hooks', 'update-rc.d lighttpd remove');
    } else {
        pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');
    }
    killProcess('lighttpd', 'Terminating lingering lighttpd processes');
    killProcess('php-cgi', 'Terminating lingering php-cgi processes');
    if ($distroVersion < 10) {
        runStep('Ensuring nginx defaults set in sysvinit', 'update-rc.d nginx defaults');
    } else {
        pmssSystemdUnitActionIfPresent('nginx', 'Enabling nginx systemd service', 'enable');
    }

    // Per-user lighttpd configuration, htpasswd sync, and instance checks
    // are handled inside the consolidated per-user maintenance loop.
    // nginx config regeneration moved to pmssPostUpdateWebRefresh() - no need to
    // run twice. nginx stays stopped until final config refresh at end of update.
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

/**
 * Re-run global web service configuration after application installers finish.
 *
 * Per-user lighttpd refresh and auth sync now run inside the per-user
 * maintenance loop; keep this focused on the global nginx config regeneration.
 * createNginxConfig.php validates config with nginx -t before restart, refusing
 * to restart if config is broken (added 2026-01, issue #137).
 */
function pmssPostUpdateWebRefresh(): void
{
    runStep('Post-update nginx configuration refresh', '/scripts/util/createNginxConfig.php --restart');
}
