#!/usr/bin/php
<?php
$debianVersion = file_get_contents('/etc/debian_version');
// Are we running debian 7/8 or Debian 10?
if ($debianVersion[0] == 1) $debianVersion = 10;
	else $debianVersion = 8;

// Gather iostat information from disks
$iostatLogFile = '/var/run/pmss/iostat';

$devices = `ls /sys/block/|grep sd|grep -v loop|grep -v md`;
$devices = explode("\n", trim($devices));
$deviceList = implode(' ', $devices);
if (count($devices) == 0) die("No block devices detected\n");

// Get the iostats for past 2 minutes for parsing
// Depends on debian version, mapping is below
// Sample code:
// iostat -xm 1 2 -g grp1 sda sdb sdc sdd | awk '/grp1/ { print $4,$5,$6,$7,$10,$13,$14}'
// For debian 10 took r_await as that's what we are more interested, both read and write await is now exposed #TODO eventually this
if ($debianVersion == 10) $iostat = `iostat -xm 120 2 -g grp1 {$deviceList} | awk '/grp1/ { print $2,$3,$4,$5,$10,$15,$16}'`;
	else $iostat = `iostat -xm 120 2 -g grp1 {$deviceList} | awk '/grp1/ { print $4,$5,$6,$7,$10,$13,$14}'`;

$iostatRaw = $iostat;

// Parse them! :)
$iostat = explode("\n", trim($iostat));
$iostat = $iostat[1];   // We are only interested in CURRENT load
$iostat = explode(' ', $iostat);    // empty space is the divider


$iostat = array(
    'iopsRead' => $iostat[0],
    'iopsWrite' => $iostat[1],
    'throughputRead' => $iostat[2],
    'throughputWrite' => $iostat[3],
    'diskAwait' => $iostat[4],
    'diskServiceTime' => $iostat[5],
    'diskUtil' => $iostat[6],
    'diskQuantity' => count($devices),
    'time' => time()
);

// Save the data
file_put_contents($iostatLogFile, serialize($iostat));
file_put_contents($iostatLogFile . '-history', date('Y-m-d H:i:s') .' || ' . serialize($iostat) . "\n", FILE_APPEND);
file_put_contents($iostatLogFile . '-history-raw', date('Y-m-d H:i:s') .' || ' . $iostatRaw . "\n---\n", FILE_APPEND);


// This way we can download it just via HTTP and easier view remotely
passthru("cp /var/run/pmss/iostat /var/www/iostat");
