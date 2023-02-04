#!/usr/bin/php
<?php
die();
$logDir = '/var/log/pmss/cgroup/';
$rtorrentPids = trim( `pgrep -x "rtorrent main"` );
$rtorrentPids = explode("\n", $rtorrentPids);

if (!file_exists('/sys/fs/cgroup/rtorrent')) mkdir('/sys/fs/cgroup/rtorrent');
// For some bizarre reason this has to be done before we can assign tasks here!
`echo 0 > /sys/fs/cgroup/rtorrent/cpuset.mems`;
`echo 0 > /sys/fs/cgroup/rtorrent/cpuset.cpus`;

// Set the base weight for rTorrent processes. 1000 is default
// This is not really specified in documentation :(
`echo 900 > /sys/fs/cgroup/rtorrent/blkio.weight`;
// Slightly lower rTorrent CPU shares, default is 1024, so this gives something like 40% when all tries to use 100%
`echo 900 > /sys/fs/cgroup/rtorrent/cpu.shares`;
// We don't want rTorrent to *ever* use swap
`echo 0 > /sys/fs/cgroup/rtorrent/memory.swappiness`;

// First "clean up" the cgroup
$oldPids = trim( `cat /sys/fs/cgroup/rtorrent/tasks` );
$oldPids = explode("\n", $oldPids);

if (count($oldPids) != 0) {
    foreach($oldPids AS $thisPid) {
        `echo {$thisPid} > /sys/fs/cgroup/tasks`;
        logEntry($logDir . 'rtorrent.log', 'cleaned PID ' . $thisPid);
    }
}

if (count($rtorrentPids) != 0) {
    foreach($rtorrentPids AS $thisPid) {
        `echo {$thisPid} > /sys/fs/cgroup/rtorrent/tasks`;
        logEntry($logDir. 'rtorrent.log', "Added PID {$thisPid}");
        
    }
}

/*
// Per user rTorrent pids!
$users = trim( `listUsers.php` );
$users = explode("\n", $users);

$rtorrentReadMaxBps = 0;
$rtorrentWriteMaxBps = 0;

foreach($users AS $thisUser) {
    $userPid = (int) trim( `pgreg -x "rtorrent main" -u {$thisUser}` );
    if (empty($userPid) or $userPid < 1000) continue;
    $cgroup = '/sys/fs/cgroup/rtorrent/' . $thisUser;
    if (!file_exists($cgroup)) mkdir($cgroup);
    
    
}
*/

// Time for lighttpd!

// Let's get our device!
$device = trim(`df /home`);
$device = explode("\n", $device);
if (count($device) != 2) {
    logEntry($logDir. 'rtorrent.log', "Could not find homedevice: " . print_r($device, true));
    die('COULD NOT FIND HOME DEVICE');
}
$device = explode(' ', $device[1]);
$device = $device[0];
$deviceNumber = `ls {$device} -al`;
$deviceNumber = explode(' ', $deviceNumber);
if (count($deviceNumber) < 10) die("COULD NOT FIND DEVICE NUMBER MAJOR MINOR!\n" . print_r($deviceNumber, true) . "\n" . count($deviceNumber) );
$deviceNumber[4] = (int) $deviceNumber[4];
$deviceNumbering = $deviceNumber[4] . ':' . $deviceNumber[5];
//echo "Should be: {$deviceNumber[4]} - {$deviceNumber[5]}\n";


if (!file_exists('/sys/fs/cgroup/lighttpd')) mkdir('/sys/fs/cgroup/lighttpd');
// For some bizarre reason this has to be done before we can assign tasks here!
`echo 0 > /sys/fs/cgroup/lighttpd/cpuset.mems`;
`echo 0 > /sys/fs/cgroup/lighttpd/cpuset.cpus`;
`echo 1000 > /sys/fs/cgroup/lighttpd/blkio.weight`;
`echo 2048 > /sys/fs/cgroup/lighttpd/cpu.shares`;
`echo 80 > /sys/fs/cgroup/lighttpd/memory.swappiness`;
`echo "{$deviceNumbering} 20971520" > /sys/fs/cgroup/lighttpd/blkio.throttle.read_bps_device`;
`echo "{$deviceNumbering} 4194304" > /sys/fs/cgroup/lighttpd/blkio.throttle.write_bps_device`;
`echo "{$deviceNumbering} 50" > /sys/fs/cgroup/lighttpd/blkio.throttle.read_iops_device`;
`echo "{$deviceNumbering} 25" > /sys/fs/cgroup/lighttpd/blkio.throttle.write_iops_device`;


$lighttpdPids = trim( `pgrep -x "lighttpd"` );
$lighttpdPids = explode("\n", $lighttpdPids);
$phpPids = trim( `pgrep -x "php-cgi"` );
$phpPids = explode("\n", $phpPids);
$lighttpdPids = array_merge($lighttpdPids, $phpPids);

// First "clean up" the cgroup
$oldPids = trim( `cat /sys/fs/cgroup/lighttpd/tasks` );
$oldPids = explode("\n", $oldPids);

if (count($oldPids) != 0) {
    foreach($oldPids AS $thisPid) {
        `echo {$thisPid} > /sys/fs/cgroup/tasks`;
        logEntry($logDir . 'lighttpd.log', 'cleaned PID ' . $thisPid);
    }
}

if (count($lighttpdPids) != 0) {
    foreach($lighttpdPids AS $thisPid) {
        `echo {$thisPid} > /sys/fs/cgroup/lighttpd/tasks`;
        logEntry($logDir. 'lighttpd.log', "Added PID {$thisPid}");
        
    }
}




function logEntry($file, $message) {
    file_put_contents($file, date('Y-m-d H:i:s') . ": {$message}\n");
    
}