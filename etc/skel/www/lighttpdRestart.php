<?php

if (!isset($_REQUEST['action'])) die();
$action = $_REQUEST['action'];
if ($action !== 'confirm-restart') die();	// double check

shell_exec('killall -USR1 -u $(whoami) lighttpd');
