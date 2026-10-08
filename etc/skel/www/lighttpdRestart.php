<?php
require_once __DIR__.'/scriptsInc.php';
pmssFrontendPostActionRequired();

$action = pmssFrontendActionRequest();
if ($action !== 'confirm-restart') die();	// double check

pmssFrontendShellExec('killall -USR1 -u $(whoami) lighttpd');
