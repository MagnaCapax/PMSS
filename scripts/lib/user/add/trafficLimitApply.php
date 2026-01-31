<?php
/**
 * addUser: traffic limit persistence.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Persist optional traffic limits to runtime and the user's home directory.
 */
function pmssAddUserTrafficLimitApply(array $user): void
{
    if (empty($user['trafficLimit']) || $user['trafficLimit'] <= 0) {
        return;
    }

    $runtimeDir = '/etc/seedbox/runtime/trafficLimits';
    require_once __DIR__.'/../directories.php';
    if (function_exists('pmssEnsureDir')) {
        pmssEnsureDir($runtimeDir, 0700, 'root', 'root');
    } elseif (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0755, true);
        @chmod($runtimeDir, 0700);
    }

    @file_put_contents($runtimeDir."/{$user['name']}", (string) $user['trafficLimit'], LOCK_EX);
    @chmod($runtimeDir."/{$user['name']}", 0600);
    @file_put_contents("/home/{$user['name']}/.trafficLimit", (string) $user['trafficLimit'], LOCK_EX);
    @chmod("/home/{$user['name']}/.trafficLimit", 0664);
    logProvisionMessage('Traffic limit set: ' . $user['trafficLimit']);
}
