<?php
/** Reconcile the panel mount and retire obsolete port-C routes without running account code. */

require_once __DIR__.'/../runtime/filesystem.php';

function pmssHermesUiConverge(string $user, string $home): void
{
    if (!pmssValidateUsername($user) || basename($home) !== $user) return;
    $port = pmssReadRegularFileNetworkPort('/etc/seedbox/runtime/ports/media-stack-hermes-'.$user);
    $ready = $port !== null && $port >= 1024;
    $lighttpdPath = $home.'/.lighttpd.conf';
    $nginxPath = '/etc/nginx/conf.d/pmss-user-'.$user.'.conf';
    // Account-writable home: verified root-owned regular inode, non-blocking (no link, hard link or FIFO stall).
    $lighttpd = (string) pmssReadRegularFileContentsVerified($lighttpdPath, 0, 1048576);
    $nginx = is_file($nginxPath) && !is_link($nginxPath)
        ? (string) @file_get_contents($nginxPath, false, null, 0, 1048576) : '';
    $lighttpdHasRoute = strpos($lighttpd, '^/user-'.$user.'/hermes/') !== false;
    if ($ready !== $lighttpdHasRoute) {
        if (runStep('Reconcile Hermes UI lighttpd route for '.$user,
            pmssBuildCommand('/scripts/util/userConfigLighttpd.php', [$user])) !== 0) return;
    }
    $panel = (string) pmssReadRegularFileContentsVerified('/etc/nginx/users/'.$user, 0, 1048576);
    if (strpos($panel, '/user-'.$user.'/hermes/') !== false && strpos($nginx, 'listen 846 ssl;') === false) return;
    if (runStep('Reconcile Hermes UI nginx route for '.$user,
        pmssBuildCommand('/scripts/util/createNginxConfig.php', ['--user', $user])) === 0) {
        runStep('Reload nginx for Hermes UI', 'systemctl reload nginx');
    }
}
