#!/usr/bin/php
<?php
# Setting user traffic limits

$usage = 'Usage: ./userTrafficLimit.php USERNAME TRAFFIC LIMIT';
if (empty($argv[1]) or
    empty($argv[2]) ) die('need user name. ' . $usage . "\n");

$user = array(
    'name'      => $argv[1],
    'trafficLimit'    => (int) $argv[2],
);

if (!file_exists("/home/{$user['name']}")) die("User does not exist\n");

$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $user['name']) === false) die("No such user\n");

$userTrafficFile = "/etc/seedbox/runtime/trafficLimits/{$user['name']}";
setTrafficLimitFile($userTrafficFile, $user['trafficLimit']);
setTrafficLimitFile("/home/{$user['name']}/.trafficLimit", $user['trafficLimit']);

if (file_exists($userTrafficFile)) chmod($userTrafficFile, 0600);
echo "Traffic limit set at {$user['trafficLimit']}\n";


function setTrafficLimitFile($userTrafficFile, $trafficLimit) {
    if ($trafficLimit == 0) unlink($userTrafficFile);
        elseif ($trafficLimit > 0) file_put_contents($userTrafficFile, (int) $trafficLimit); 
    
}