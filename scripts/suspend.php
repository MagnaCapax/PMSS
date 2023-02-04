#!/usr/bin/php
<?php
$usage = 'suspend.php USERNAME';
if (empty($argv[1])) die($usage . "\n");

if (file_exists("/home/{$argv[1]}/www-disabled")) die("User already suspended\n");

passthru("usermod -L {$argv[1]}");
passthru("usermod --expiredate 1 {$argv[1]}");
passthru("ps aux|grep {$argv[1]}");
passthru("killall -9 -u {$argv[1]}");

// This works as despite being able to login results in error message, and cannot access any features
#TODO Make suspended message instead of simple error page :)
passthru("mv /home/{$argv[1]}/www /home/{$argv[1]}/www-disabled");  
