#!/usr/bin/php
<?php
// Check user's GUI index.php
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
	if (file_exists("/home/{$thisUser}/www-disabled")) continue;	// User suspended

    $file = "/home/{$thisUser}/www/index.php";

    if (!file_exists($file)
		or filesize($file) == 0) {
	        `cp /etc/skel/www/index.php {$file}`;
    }

	#TODO Check responsiveness etc. other common stuff as well.

}


