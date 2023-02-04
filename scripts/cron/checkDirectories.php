#!/usr/bin/php
<?php
// Checks and creates required temp directories, along with sets permissions correctly

// Create log + var directories if they don't exist
$requiredDirectories = array(
    '/var/log/pmss',
    '/var/log/pmss/traffic',
    '/var/log/pmss/cgroup',
    '/var/log/pmss/trafficStats',
    '/var/run/pmss',
    '/var/run/pmss/api',
    '/var/run/pmss/trafficLimits',
);

foreach($requiredDirectories AS $thisDir) {
    if (!file_exists($thisDir)) {
        mkdir($thisDir);
    }
    // Set permissions
    chown($thisDir, 'root');
    chmod($thisDir, 0600);
}


