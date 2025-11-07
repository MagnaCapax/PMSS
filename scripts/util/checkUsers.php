#!/usr/bin/php
<?php
/**
 * Compare PMSS user database entries against home directories and /etc/passwd.
 */

require_once __DIR__.'/../lib/users.php';

$options = array_slice($argv, 1);
if (in_array('--help', $options, true) || in_array('-h', $options, true)) {
    echo "Usage: checkUsers.php [--json]\n";
    exit(0);
}

$jsonOutput = in_array('--json', $options, true);

$db = new users();
$cacheUsers = array_keys($db->getUsers());
$homeUsers = users::listHomeDirectories();
$passwdUsers = users::listPasswdUsers();

$set = static function (array $list): array {
    $set = [];
    foreach ($list as $item) {
        $set[$item] = true;
    }
    return $set;
};

$dbSet = $set($cacheUsers);
$homeSet = $set($homeUsers);
$passwdSet = $set($passwdUsers);

$dbOnly = array_values(array_diff(array_keys($dbSet), array_keys($homeSet + $passwdSet)));
$homeOnly = array_values(array_diff(array_keys($homeSet), array_keys($dbSet)));
$passwdOnly = array_values(array_diff(array_keys($passwdSet), array_keys($dbSet + $homeSet)));
$consistent = array_values(array_intersect(array_keys($dbSet), array_keys($homeSet), array_keys($passwdSet)));

$result = [
    'consistent'   => $consistent,
    'db_only'      => $dbOnly,
    'home_only'    => $homeOnly,
    'passwd_only'  => $passwdOnly,
    'db_users'     => array_values($cacheUsers),
    'home_users'   => array_values($homeUsers),
    'passwd_users' => array_values($passwdUsers),
];

if ($jsonOutput) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

echo "== User Dataset Comparison ==\n";

echo "Users present in DB + /home + /etc/passwd:\n";
if (empty($consistent)) {
    echo "  (none)\n";
} else {
    foreach ($consistent as $name) {
        echo "  - {$name}\n";
    }
}

echo "\nUsers only in JSON database (likely stale):\n";
if (empty($dbOnly)) {
    echo "  (none)\n";
} else {
    foreach ($dbOnly as $name) {
        echo "  - {$name}\n";
    }
}

echo "\nUsers only in /home (missing from DB):\n";
if (empty($homeOnly)) {
    echo "  (none)\n";
} else {
    foreach ($homeOnly as $name) {
        echo "  - {$name}\n";
    }
}

echo "\nUsers only in /etc/passwd (no home directory/DB entry):\n";
if (empty($passwdOnly)) {
    echo "  (none)\n";
} else {
    foreach ($passwdOnly as $name) {
        echo "  - {$name}\n";
    }
}
