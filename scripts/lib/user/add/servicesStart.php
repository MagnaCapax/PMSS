<?php
/**
 * addUser: service startup steps.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Start per-user services and refresh shared runtime state.
 */
function pmssAddUserServicesStart(array $user): void
{
    runProvisionStep(
        'Start rTorrent',
        sprintf('/scripts/startRtorrent %s', escapeshellarg($user['name']))
    );
    runProvisionStep(
        'Start lighttpd',
        sprintf('/scripts/startLighttpd %s', escapeshellarg($user['name']))
    );
    runProvisionStep('Restart nginx', 'systemctl restart nginx || /etc/init.d/nginx restart || true');
    runProvisionStep('Refresh network rules', '/scripts/util/setupNetwork.php');
}

