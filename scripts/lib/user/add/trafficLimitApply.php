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

    if (!file_exists("/etc/seedbox/runtime/trafficLimits")) {
        mkdir("/etc/seedbox/runtime/trafficLimits");
    }
    file_put_contents("/etc/seedbox/runtime/trafficLimits/{$user['name']}", $user['trafficLimit']);
    chmod("/etc/seedbox/runtime/trafficLimits/{$user['name']}", 0600);
    file_put_contents("/home/{$user['name']}/.trafficLimit", $user['trafficLimit']);
    chmod("/home/{$user['name']}/.trafficLimit", 0664);
    logProvisionMessage('Traffic limit set: ' . $user['trafficLimit']);
}

