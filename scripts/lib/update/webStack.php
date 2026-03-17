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
    // nginx config regeneration runs once after app installers finish, so do
    // not duplicate it here. nginx stays stopped until that final refresh.
    runStep('Setting /home directory permissions', 'chmod 751 /home');
    // Quota state files reject chmod; prune them so the find commands stay noise-free.
    $prune = '\( -name "aquota.*" -o -name "lost+found" \)';
    foreach ([
        ['Hardening /home tenant directories', 'd', '700'],
        ['Hardening /home tenant files', 'f', '600'],
    ] as $hardeningStep) {
        runStep(
            $hardeningStep[0],
            sprintf('find /home -mindepth 1 -maxdepth 1 %s -prune -o -type %s -exec chmod %s {} +', $prune, $hardeningStep[1], $hardeningStep[2])
        );
    }
}
