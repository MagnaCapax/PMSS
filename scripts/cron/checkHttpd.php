#!/usr/bin/php
<?php
#TODO Check PID file and actual process
die();
#TODO Rather use catch clause to get the error
$status = file_get_contents('http://127.0.0.1/status.php');

if ($status != 'Ok.') {
    echo date('Y-m-d H:i:s') . ' - Restarting lighttpd, status reported: ' . $status . "\n";
    passthru('killall -9 lighttpd;');
    sleep(3); // Allow time for lighttpd to die
    passthru('/etc/init.d/lighttpd restart');
}
