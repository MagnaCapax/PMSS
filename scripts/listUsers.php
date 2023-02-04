#!/usr/bin/php
<?php
die( passthru('/scripts/systemUsers.php') );    // Temp fix until user db is in wide enough use to support it and apis etc. done
if (!file_exists('/etc/seedbox/runtime/users')) die( passthru('/scripts/systemUsers.php') );

#TODO Comparison of system users if it's too much of an difference
// We use serialized array because it's easiest to write from PHP :)
$users = unserialize( file_get_contents('/etc/seedbox/runtime/users') );
if (!is_array($users)) die("User DB Corrupted.\n");

#TODO Check these exist, resort to systemUsers if not.
foreach ($users AS $thisUser => $userData)
    echo "{$thisUser}\n";
