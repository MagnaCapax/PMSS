<?php
/**
 * PMSS (Pulsed Media Seedbox Software) Update Script for Watchdog
 * 
 * This script installs and configures the Watchdog package on a Debian-based system.
 * Watchdog is a daemon that checks the system state and environment according 
 * to the parameters defined in its configuration file. When the system fails these checks, 
 * Watchdog will perform a system reboot to recover the system.
 * 
 */


# Install
if (!file_exists('/etc/watchdog.conf')) passthru('apt install watchdog -y');

// Define the watchdog configuration parameters
$watchdogConfig = [
    'watchdog-device' => '/dev/watchdog',    // Watchdog device path
    'watchdog-timeout' => '300',             // Watchdog timeout in seconds
    'interval' => '1',                       // Watchdog check interval
    'logtick' => '60',                       // Watchdog log tick interval
    'ping' => ['185.148.0.2', '8.8.8.8'],    // Array of IPs to ping
    'ping-timeout' => '1800',                // Ping timeout in seconds
    'max-load-1' => '100',                   // Maximum 1-minute load average
    'max-load-15' => '60',                   // Maximum 15-minutes load average
    // Uncomment following line to enable min-memory check
    // 'min-memory' => '500',                // Minimum free memory in pages
];

// Check if the watchdog device exists
if (!file_exists($watchdogConfig['watchdog-device'])) {
    // If not, remove the watchdog related parameters
    unset($watchdogConfig['watchdog-device']);
    unset($watchdogConfig['watchdog-timeout']);
}

$configContent = "";  // Initialize the configuration content string

// Construct the configuration content string
foreach ($watchdogConfig as $key => $value) {
    if($key == 'ping') {
        // Add each IP address to ping in a separate line
        foreach($value as $address) {
            $configContent .= "ping = $address\n";
        }
    } else {
        // Add other parameters to the configuration content
        $configContent .= "$key = $value\n";
    }
}

// Write the configuration content to the watchdog configuration file
file_put_contents("/etc/watchdog.conf", $configContent);



# Enable and start
passthru('systemctl enable watchdog; systemctl start watchdog;');

echo "*** Watchdog successfully installed + configured\n";
