#!/usr/bin/php
<?php
$usage = 'unsuspend.php USERNAME';
if (empty($argv[1])) die($usage . "\n");

if (file_exists("/home/{$argv[1]}/www")) die("User is not suspended\n");

passthru("usermod -U {$argv[1]}");
passthru("usermod --expiredate " . date('Y-m-d', (time() + (60 * 60 * 24 * 365 * 10)) ) . " {$argv[1]}");
passthru("mv /home/{$argv[1]}/www-disabled /home/{$argv[1]}/www");
passthru("/scripts/startRtorrent {$argv[1]}");
