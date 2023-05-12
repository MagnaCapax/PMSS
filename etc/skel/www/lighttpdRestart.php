<?php

if (!isset($_REQUEST['action'])) die();
$action = $_REQUEST['action'];
if ($action !== 'confirm-restart') die();	// double check

//shell_exec('killall -9 -u $(whoami) lighttpd; killall -9 -u $(whoami) php-cgi');
shell_exec('killall -9 -u $(whoami) lighttpd');
