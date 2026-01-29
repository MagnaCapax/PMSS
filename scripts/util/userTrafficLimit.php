#!/usr/bin/env php
<?php
/**
 * Configure per-user traffic limits from the command line.
 *
 * Usage: ./userTrafficLimit.php --user=<username> --limit=<MiB>
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
# TODO Add per user max bandwidth limit (GH #127)
# TODO Comment steps better (GH #127)
# TODO Make common command variables parser which has more optional settings like --bandwidth 100M (GH #127)

require_once '/scripts/lib/cli/optionParser.php';
$userLifecycle = '/scripts/lib/userLifecycle.php';
if (is_file($userLifecycle)) {
    require_once $userLifecycle;
}

$usage = 'Usage: ./userTrafficLimit.php --user=<username> --limit=<MiB>'; 
$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []));

if (pmssCliOption($parsed, 'help', 'h')) {
    echo $usage."\n";
    exit(0);
}

$userName = (string)pmssCliOption($parsed, 'user', 'u', $parsed['arguments'][0] ?? '');
$limitRaw = pmssCliOption($parsed, 'limit', 'l', $parsed['arguments'][1] ?? null);

if ($userName === '' || $limitRaw === null || $limitRaw === true) {
    die('need user name. '.$usage."\n");
}

$userName = function_exists('pmssNormalizeUsername')
    ? pmssNormalizeUsername($userName)
    : strtolower(trim($userName));
$trafficLimit = (int) $limitRaw;

// Check if user exists
$userList = file_get_contents('/etc/passwd');
if (strpos($userList, $userName) === false || !is_dir("/home/{$userName}")) {
    die("No such user\n");
}

//Save the configured limit
$userTrafficFile = "/etc/seedbox/runtime/trafficLimits/{$userName}";
$targets = [
    $userTrafficFile,
    "/home/{$userName}/.trafficLimit",
];
if ($trafficLimit === 0) {
    foreach ($targets as $target) {
        if (file_exists($target)) {
            unlink($target);
        }
    }
} elseif ($trafficLimit > 0) {
    foreach ($targets as $target) {
        file_put_contents($target, $trafficLimit);
    }
}

if (file_exists($userTrafficFile)) {
    chmod($userTrafficFile, 0600);
}
echo "Traffic limit for {$userName} set at {$trafficLimit}\n";
