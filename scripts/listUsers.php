#!/usr/bin/php
<?php
require_once __DIR__.'/lib/users.php';

$db = new users();
$db->getUsers(); // prime cache and prune stale records

$usernames = users::listHomeUsers();
sort($usernames, SORT_NATURAL | SORT_FLAG_CASE);

foreach ($usernames as $name) {
    echo $name . "\n";
}
