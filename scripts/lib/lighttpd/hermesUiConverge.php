<?php
/** Reconcile generated port-C routes after an account-side install completes. */

require_once __DIR__.'/../runtime/filesystem.php';

function pmssHermesUiConverge(string $user, string $home): void
{
    if (!pmssValidateUsername($user) || basename($home) !== $user) return;
    $status = $home.'/.hermes/pmss-install.status';
    $ready = is_file($status) && !is_link($status)
        && @filesize($status) <= 32 && trim((string) @file_get_contents($status)) === 'ready'
        && pmssReadRegularFileNetworkPort('/etc/seedbox/runtime/ports/media-stack-hermes-'.$user) !== null;
    $lighttpdPath = $home.'/.lighttpd.conf';
    $nginxPath = '/etc/nginx/conf.d/pmss-user-'.$user.'.conf';
    // Account-writable home: verified root-owned regular inode, non-blocking (no link, hard link or FIFO stall).
    $lighttpd = (string) pmssReadRegularFileContentsVerified($lighttpdPath, 0, 1048576);
    $nginx = is_file($nginxPath) && !is_link($nginxPath)
        ? (string) @file_get_contents($nginxPath, false, null, 0, 1048576) : '';
    $lighttpdHasRoute = strpos($lighttpd, '^/__pmss-ui-c/') !== false;
    if ($ready !== $lighttpdHasRoute) {
        if (runStep('Reconcile Hermes UI lighttpd route for '.$user,
            pmssBuildCommand('/scripts/util/userConfigLighttpd.php', [$user])) !== 0) return;
    }
    $host = strtolower(trim((string) @shell_exec('/bin/hostname -f')));
    $cert = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $host) === 1
        && is_file('/etc/letsencrypt/live/'.$user.'.'.$host.'/fullchain.pem');
    $nginxShouldRoute = $ready && $cert;
    if ($nginxShouldRoute === (strpos($nginx, 'listen 846 ssl;') !== false)) return;
    if (runStep('Reconcile Hermes UI nginx route for '.$user,
        pmssBuildCommand('/scripts/util/createNginxConfig.php', ['--user', $user])) === 0) {
        runStep('Reload nginx for Hermes UI', 'systemctl reload nginx');
    }
}
