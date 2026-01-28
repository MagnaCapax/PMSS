#!/usr/bin/env php
<?php
/**
 * Traffic limits enforcement cron.
 *
 * Monitors per-user traffic usage and applies/removes throttling based on
 * configured limits. Throttling is applied when usage exceeds limit and
 * removed after a cooldown period (3 days by default).
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */
require_once '/scripts/lib/rtorrentConfig.php';
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}
if (!file_exists('/var/run/pmss/trafficLimits')) `mkdir -p /var/run/pmss/trafficLimits`;

$trafficLimitPeriod = 3 * 24 * 60 * 60;     // 3 days limiting period

$users = trim( `/scripts/listUsers.php` );
$users = array_filter(explode("\n", $users));
if (count($users) == 0) die("No users in this system!\n");

//$networkConfig = include '/etc/seedbox/config/network';

$trafficData = array();
foreach($users AS $thisUser) {
    $userTrafficLimitFile = "/etc/seedbox/runtime/trafficLimits/{$thisUser}";
    $trafficDataFile = "/home/{$thisUser}/.trafficData";
    if (!file_exists($trafficDataFile) or
        !file_exists($userTrafficLimitFile) ) continue;

    $data = pmssReadTrafficData($trafficDataFile, $thisUser);
    if ($data === null) {
        echo date('Y-m-d H:i:s') . ": Skipping {$thisUser}, invalid traffic data file\n";
        continue;
    }
    $trafficLimit = file_get_contents($userTrafficLimitFile);
    if (empty($trafficLimit) or
        $trafficLimit == 0) continue;
//    var_dump($data);
    $trafficData[$thisUser]['traffic'] = ($data['raw']['month'] / 1024);   // Set to GiB
    $trafficData[$thisUser]['trafficLimit'] = $trafficLimit;
    
}

/**
 * Safely read traffic data snapshot for a user, enforcing ownership and structure.
 */
function pmssReadTrafficData(string $path, string $username): ?array
{
    if (is_link($path)) {
        return null;
    }

    $stats = @stat($path);
    if ($stats === false) {
        return null;
    }

    if ($stats['uid'] !== 0) {
        return null;
    }

    $mode = $stats['mode'] & 0777;
    if (($mode & 0022) !== 0) { // group/other writable
        return null;
    }

    $group = @posix_getgrgid($stats['gid']);
    if ($group !== false) {
        $groupName = $group['name'];
        if ($groupName !== $username && $groupName !== 'root') {
            return null;
        }
    }

    $blob = @file_get_contents($path);
    if ($blob === false || $blob === '') {
        return null;
    }

    $data = @unserialize($blob, ['allowed_classes' => false]);
    if (!is_array($data) || !isset($data['raw']) || !is_array($data['raw'])) {
        return null;
    }

    if (!isset($data['raw']['month']) || !is_numeric($data['raw']['month'])) {
        return null;
    }

    return $data;
}


// Enforce traffic throttling: when usage exceeds the configured limit touch the
// `.enabled` marker (keeping it fresh so sustained overages remain throttled).
// Once usage drops below the threshold for the configured cooldown window,
// remove the marker and lift the rate limit. The double disable call guards
// against occasional router desyncs.
foreach ($trafficData AS $thisUser => $thisData) {
    $trafficLimitEnabledTime = 0;
    $userTrafficLimitEnabledFile = "/var/run/pmss/trafficLimits/{$thisUser}.enabled";
    
    // Needs to stay within the limit for X period of time, hence we can always touch & update the limit file
    if ($thisData['traffic'] > $thisData['trafficLimit']) { // Should be limited

        
        
        touch( $userTrafficLimitEnabledFile );

        chmod( $userTrafficLimitEnabledFile, 0600);
        setRatelimit($thisUser, $thisData['trafficLimit']);    // Apply rate limiting
        if (function_exists('pmssUserLog')) {
            pmssUserLog(
                $thisUser,
                sprintf(
                    'traffic throttle enabled (limit=%.2f GiB usage=%.2f GiB)',
                    $thisData['trafficLimit'],
                    $thisData['traffic']
                )
            );
        }
        
    } else if (file_exists($userTrafficLimitEnabledFile)) {     // Now let's see if it's time to remove it?
        
        $trafficLimitEnabledTime = filemtime($userTrafficLimitEnabledFile);
        $trafficLimitEnabledTime = time() - $trafficLimitEnabledTime;
        
        if ($trafficLimitEnabledTime > $trafficLimitPeriod) {   // Time to remove the limit
            unlink( $userTrafficLimitEnabledFile );
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'traffic throttle removed after cooldown');
            }
            setRateLimit($thisUser, $thisData['trafficLimit'], false);
			// Do it second time as removal does not always work for some reason
			sleep(1);
			setRateLimit($thisUser, $thisData['trafficLimit'], false);
        }
        
    }

}

function setRateLimit($user, $trafficLimit, $enable=true) {
    if ($enable == false) { @unlink("/home/{$user}/.throttle"); return; }

    file_put_contents("/home/{$user}/.throttle", $trafficLimit);
}
