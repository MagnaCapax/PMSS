#!/usr/bin/env php
<?php
// Gather iostat information from disks
$cpuStatLogFile = '/var/run/pmss/cpustat';

$stat1 = file('/proc/stat'); 
sleep(120); 
$stat2 = file('/proc/stat'); 

$stat1 = explode(" ", preg_replace("!cpu +!", "", $stat1[0])); 
$stat2 = explode(" ", preg_replace("!cpu +!", "", $stat2[0])); 

$diff['user'] = (int) $stat2[0] - (int) $stat1[0]; 
$diff['nice'] = (int) $stat2[1] - (int) $stat1[1]; 
$diff['sys'] = (int) $stat2[2] - (int) $stat1[2]; 
$diff['idle'] = (int) $stat2[3] - (int) $stat1[3]; 
$total = array_sum($diff); 
foreach($diff as $x=>$y) $cpu[$x] = round($y / $total * 100, 1);

// Save the data
file_put_contents($cpuStatLogFile, serialize($cpu));
file_put_contents($cpuStatLogFile . '-history', date('Y-m-d H:i:s') .' || ' . serialize($cpu) . "\n", FILE_APPEND);

// This way we can download it just via HTTP and easier view remotely
passthru("cp /var/run/pmss/cpustat /var/www/cpustat");
