#!/usr/bin/env php
<?php
/**
 * Compare PMSS user database entries against home directories and /etc/passwd.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/users.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
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

$result = [
    'consistent'   => $consistent,
    'db_only'      => $dbOnly,
    'home_only'    => $homeOnly,
    'passwd_only'  => $passwdOnly,
    'db_users'     => array_values($cacheUsers),
    'home_users'   => array_values($homeUsers),
    'passwd_users' => array_values($passwdUsers),
];

if (in_array('--json', $argv, true)) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
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
    if (empty($section['list'])) {
        echo "  (none)\n";
        continue;
    }
    foreach ($section['list'] as $name) {
        echo "  - {$name}\n";
    }
}
