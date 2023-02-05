#!/usr/bin/php
<?php
# PMSS: Setting user traffic limits
# Copyright (C) Magna Capax Finland Oy 2010-2023
# TODO Add per user max bandwidth limit
# TODO Comment steps better
# TODO Make common command variables parser which has more optional settings like --bandwidth 100M

$usage = 'Usage: ./userTrafficLimit.php USERNAME TRAFFIC LIMIT';
if (empty($argv[1]) or
    empty($argv[2]) ) die('need user name. ' . $usage . "\n");

$user = array(
    'name'      => $argv[1],
    'trafficLimit'    => (int) $argv[2],
);

// Check if user exists
$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $user['name']) === false
    or !file_exists("/home/{$user['name']}")
    or !is_dir("/home{$user['name']}") ) die("No such user\n");

//Save the configured limit
$userTrafficFile = "/etc/seedbox/runtime/trafficLimits/{$user['name']}";
setTrafficLimitFile($userTrafficFile, $user['trafficLimit']);
setTrafficLimitFile("/home/{$user['name']}/.trafficLimit", $user['trafficLimit']);

if (file_exists($userTrafficFile)) chmod($userTrafficFile, 0600);
echo "Traffic limit for {$user['name']} set at {$user['trafficLimit']}\n";


function setTrafficLimitFile($userTrafficFile, $trafficLimit) {
    if ($trafficLimit == 0) unlink($userTrafficFile);
        elseif ($trafficLimit > 0) file_put_contents($userTrafficFile, (int) $trafficLimit); 
    
}