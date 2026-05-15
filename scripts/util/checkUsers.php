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

$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []));
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
    echo json_encode([
        'consistent'   => $consistent,
        'db_only'      => $dbOnly,
        'home_only'    => $homeOnly,
        'passwd_only'  => $passwdOnly,
        'db_users'     => $cacheUsers,
        'home_users'   => $homeUsers,
        'passwd_users' => $passwdUsers,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

echo "== User Dataset Comparison ==\n";

$sections = [
    ['prefix' => '', 'label' => 'Users present in DB + /home + /etc/passwd:', 'list' => $consistent],
    ['prefix' => "\n", 'label' => 'Users only in JSON database (likely stale):', 'list' => $dbOnly],
    ['prefix' => "\n", 'label' => 'Users only in /home (missing from DB):', 'list' => $homeOnly],
    ['prefix' => "\n", 'label' => 'Users only in /etc/passwd (no home directory/DB entry):', 'list' => $passwdOnly],
];

foreach ($sections as $section) {
    echo $section['prefix'].$section['label']."\n";
    echo empty($section['list']) ? "  (none)\n" : "  - ".implode("\n  - ", $section['list'])."\n";
}
