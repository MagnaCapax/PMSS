#!/usr/bin/env php
<?php
/**
 * Compare PMSS user database entries against home directories and /etc/passwd.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/users.php';
require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/log.php';

$parsed = pmssParseCliTokens(pmssCliArgv($argv ?? null));
if (pmssCliOptionPresent($parsed, 'help', 'h')) {
    echo "Usage: checkUsers.php [--json]\n";
    exit(0);
}

$db = new users();
$cacheUsers = array_keys($db->getUsers());
$homeUsers = users::listHomeDirectories();
$passwdUsers = users::listPasswdUsers();

$dbOnly = array_values(array_diff($cacheUsers, $homeUsers, $passwdUsers));
$homeOnly = array_values(array_diff($homeUsers, $cacheUsers));
$passwdOnly = array_values(array_diff($passwdUsers, $cacheUsers, $homeUsers));
$consistent = array_values(array_intersect($cacheUsers, $homeUsers, $passwdUsers));

if (pmssCliOptionPresent($parsed, 'json')) {
    exit(pmssJsonEmitPayload([
        'consistent'   => $consistent,
        'db_only'      => $dbOnly,
        'home_only'    => $homeOnly,
        'passwd_only'  => $passwdOnly,
        'db_users'     => $cacheUsers,
        'home_users'   => $homeUsers,
        'passwd_users' => $passwdUsers,
    ], 'Failed to encode user consistency JSON.', JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo "== User Dataset Comparison ==\n";

$sections = [
    ['', 'Users present in DB + /home + /etc/passwd:', $consistent],
    ["\n", 'Users only in JSON database (likely stale):', $dbOnly],
    ["\n", 'Users only in /home (missing from DB):', $homeOnly],
    ["\n", 'Users only in /etc/passwd (no home directory/DB entry):', $passwdOnly],
];

foreach ($sections as [$prefix, $label, $list]) {
    echo $prefix.$label."\n";
    echo empty($list) ? "  (none)\n" : "  - ".implode("\n  - ", $list)."\n";
}
