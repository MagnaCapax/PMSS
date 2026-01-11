#!/usr/bin/env php
<?php
/* Check traffic limits */
require_once '/scripts/lib/rtorrentXmlrpc.php';
require_once '/scripts/lib/rtorrentConfig.php';
if (!file_exists('/var/run/pmss/trafficLimits')) `mkdir -p /var/run/pmss/trafficLimits`;

$trafficLimitPeriod = 3 * 24 * 60 * 60;     // 3 days limiting period

$users = trim( `/scripts/listUsers.php` );
$users = explode("\n", $users);
if (count($users) == 0) die("No users in this system!\n");

//$networkConfig = include '/etc/seedbox/config/network';

$trafficData = array();
foreach($users AS $thisUser) {
    #TODO(user-logs): log per-user throttling enable/disable actions to /var/log/pmss/user-<username>.log
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
        
    } else if (file_exists($userTrafficLimitEnabledFile)) {     // Now let's see if it's time to remove it?
        
        $trafficLimitEnabledTime = filemtime($userTrafficLimitEnabledFile);
        $trafficLimitEnabledTime = time() - $trafficLimitEnabledTime;
        
        if ($trafficLimitEnabledTime > $trafficLimitPeriod) {   // Time to remove the limit
            unlink( $userTrafficLimitEnabledFile );
            #TODO(user-logs): record throttle removal in per-user log
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


// Old function which set only for rtorrent
function setRatelimitOld($user, $trafficLimit, $enable=true) {
    $rtorrentConfig = new rtorrentConfig;
    $userConfig = $rtorrentConfig->readUserConfig( $user );
    if (isset($userConfig['scgi_port'])) $scgiAddress = $userConfig['scgi_port'];
		else $scgiAddress = str_replace('~', '/home/' . $user, 'unix://' . $userConfig['scgi_local']);
    
    // Let's determine what it's normally
    @$normalLimit = (int) $userConfig['upload_rate'];
    @$normalDownloadLimit = (int) $userConfig['download_rate'];
    $shapeLimit = 1024;
    $shapeDownload = 0;
    
    if (empty($normalLimit)) $normalLimit = 0;
	if (empty($normalDownloadLimit)) $normalDownloadLimit = 0;

    // If it's a Super50/Value Starter limited to 50Mbps standard -> limit to 5Mbps instead of 10Mbps
    if ($normalLimit > 0 &&
        $normalLimit = 6250 ) $shapeLimit=512;
        
    // If trafficLimit is less than 500GB then drop speed to 256Kbps
    if ($trafficLimit < 500) {
        $shapeLimit = 32;
        $shapeDownload = 2048;
    }

    if (empty($scgiAddress)) {
        echo "\t***** FATAL ERROR: Could not parse scgi_port for setting traffic limit!\n";
        return;
    }
    
    if (!strpos($scgiAddress, 'unix://') == 0) {
		$scgiAddress = explode(':', $scgiAddress);
		if (count($scgiAddress) > 2 or
		    //!is_int($scgiAddress[1]) or
		    $scgiAddress[0] != '127.0.0.1') {
		    echo "\t***** FATAL ERROR: scgi address is malformed!\n";
		    //var_dump($scgiAddress);
		    return;
		}
	}

    if (!file_exists("/home/{$user}/.rtorrent.socket")) $userScgi = new rtorrentXmlrpc('127.0.0.1', (int) $scgiAddress[1]);
		else $userScgi = new rtorrentXmlrpc($scgiAddress, 0);
    if ($enable == true) {
        $userScgi->setUploadRate($shapeLimit . 'k');
        if ($shapeDownload > 0) $userScgi->setDownloadRate($shapeDownload . 'k');
    } else {
        $userScgi->setUploadRate( $normalLimit );  // Removal of traffic limit hence normal rate
        $userScgi->setDownloadRate( $normalDownloadLimit );
    }

}
