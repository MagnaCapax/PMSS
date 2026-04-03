<?php
require_once __DIR__.'/../.scriptsInc.php';

$action = pmssFrontendActionRequest();
if ($action !== 'confirm-restart') die();	// double check

shell_exec('killall -USR1 -u $(whoami) lighttpd');
