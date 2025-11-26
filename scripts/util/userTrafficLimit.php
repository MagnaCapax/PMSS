#!/usr/bin/php
<?php
/**
 * Configure per-user traffic limits from the command line.
 *
 * Usage: ./userTrafficLimit.php --user=<username> --limit=<MiB>
 */
# TODO Add per user max bandwidth limit
# TODO Comment steps better
# TODO Make common command variables parser which has more optional settings like --bandwidth 100M

require_once '/scripts/lib/cli/optionParser.php';

$usage = 'Usage: ./userTrafficLimit.php --user=<username> --limit=<MiB>'; 
$parsed = pmssParseCliTokens($argv);

if (pmssCliOption($parsed, 'help', 'h')) {
    echo $usage."\n";
    exit(0);
}

$userName = (string)pmssCliOption($parsed, 'user', 'u', $parsed['arguments'][0] ?? '');
$limitRaw = pmssCliOption($parsed, 'limit', 'l', $parsed['arguments'][1] ?? null);

if ($userName === '' || $limitRaw === null || $limitRaw === true) {
    die('need user name. '.$usage."\n");
}

$user = [
    'name' => $userName,
    'trafficLimit' => (int)$limitRaw,
];

// Check if user exists
$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $user['name']) === false
    || !file_exists("/home/{$user['name']}")
    || !is_dir("/home/{$user['name']}") ) {
    die("No such user\n");
}

//Save the configured limit
$userTrafficFile = "/etc/seedbox/runtime/trafficLimits/{$user['name']}";
setTrafficLimitFile($userTrafficFile, $user['trafficLimit']);
setTrafficLimitFile("/home/{$user['name']}/.trafficLimit", $user['trafficLimit']);

if (file_exists($userTrafficFile)) {
    chmod($userTrafficFile, 0600);
}
echo "Traffic limit for {$user['name']} set at {$user['trafficLimit']}\n";


function setTrafficLimitFile($userTrafficFile, $trafficLimit) {
    if ($trafficLimit == 0) {
        if (file_exists($userTrafficFile)) {
            unlink($userTrafficFile);
        }
    } elseif ($trafficLimit > 0) {
        file_put_contents($userTrafficFile, (int)$trafficLimit);
    }
}
