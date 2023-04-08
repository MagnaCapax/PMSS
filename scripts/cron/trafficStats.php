#!/usr/bin/php
<?php
/**
 * Gather per user traffic usage and calculate statistics
 *
 * @copyright (C) Magna Capax Finland Oy 2023
 * @author Aleksi
 **/

require_once '/scripts/lib/traffic.php';
$trafficStatistics = new trafficStatistics;

// Check runtime directory exists
if (!file_exists('/var/run/pmss') or !is_readable('/var/run/pmss')) shell_exec ('mkdir /var/run/pmss'); //mkdir('/var/run/pmss');

// Get user list
$users = trim( `ls /var/log/pmss/traffic/|grep -v 'error.log'` );       // Hack to parse for every file found so we can show even lately terminated users
$users = explode("\n", $users);
if (count($users) == 0) die("No users in this system!\n");



// Comparison times for stats collection
$compareTimeMonth = time() - (30 * 24 * 60 * 60);   // 60 secs * 60 mins * 24 hours * 30 days
$compareTimeWeek = time() - (7 * 24 * 60 * 60);
$compareTimeDay = time() - (24 * 60 * 60);
$compareTimeHour = time() - (60 * 60);
$compareTime15min = time() - (15 * 60);

$totalDataMonth = 0;    // Reset counter, avoid warning message

foreach($users AS $thisUser) {
    $parserErrors=0;
        // Get log data. Take the last lines for 35 days. A bit extra if the colletion cron was ran more frequently than the default is
    $thisUserTrafficData = $trafficStatistics->getData( $thisUser, (35*24*60) / 5);
    
        // No data has been collected for this user yet, so skip it
    if (empty($thisUserTrafficData)) { echo date('Y-m-d H:i:s') . ": No data for user {$thisUser}\n"; continue; }
    
        // Separate lines
    $trafficData = explode("\n", $thisUserTrafficData);
    if (count($trafficData) < 2) { echo date('Y-m-d H:i:s') . ": Too little data for {$thisUser}\n"; continue; }

    // Reset "buckets", so last user checked is not compounded, and also initialize the array so no PHP Warnings
    $data = array(
        'month' => 0,
        'week' => 0,
        'day' => 0,
        'hour' => 0,
        '15min' => 0
    );
	$dataDaily = array();
	$dataDailyFirst = '';
    
        // Loop through the lines to collect stats
    foreach($trafficData AS $thisLine) {

            // Parse the actual line to timestamp + megabytes
        $thisData = $trafficStatistics->parseLine( $thisLine );
        if ($thisData == false) {   // Erroneous log entry, skip it
            if ($parserErrors < 5) echo date('Y-m-d H:i:s') . ": Parsing line failed for {$thisUser}, line: {$thisLine}\n";
            ++$parserErrors;
            continue;
        }
        
            // Separate this logged data bytes into their corresponding "buckets". If the data is from 23hrs ago, it goes to buckets day, week and month
        if ($thisData['timestamp'] >= $compareTimeMonth) $data['month'] += $thisData['data'];
        if ($thisData['timestamp'] >= $compareTimeWeek) $data['week'] += $thisData['data'];
        if ($thisData['timestamp'] >= $compareTimeDay) $data['day'] += $thisData['data'];
        if ($thisData['timestamp'] >= $compareTimeHour) $data['hour'] += $thisData['data'];
        if ($thisData['timestamp'] >= $compareTime15min) $data['15min'] += $thisData['data'];

		// Save daily data for displaying charts to user. Omit first day as it's bount never to be complete
		if (empty($dataDailyFirst)) $dataDailyFirst = date('Y/m/d', $thisData['timestamp']);
		if (date('Y/m/d', $thisData['timestamp']) != $dataDailyFirst) @$dataDaily[ date('Y/m/d', $thisData['timestamp']) ] += $thisData['data'];
        
        
    
    }
    if ($parserErrors >= 5) echo date('Y-m-d H:i:s') . ": {$parserErrors} errors supressed.\n";

        // Format for display
    $dataDisplay = $data;
    foreach($dataDisplay AS $thisKey => $thisData) {
        if ( ($thisData / 1024 / 1024) > 1 )    $dataDisplay[$thisKey] = round( ($thisData / 1024 / 1024), 2) . 'TiB';
        elseif ( ($thisData / 1024) > 1 )       $dataDisplay[$thisKey] = round( ($thisData / 1024), 2) . 'GiB';
        else                                    $dataDisplay[$thisKey] = round($thisData, 2) . 'MiB';
    }
    
        // Save the parsed data for display and traffic limit utilization
    $trafficStatistics->saveUserTraffic( $thisUser, array("raw" => $data, "display" => $dataDisplay, 'daily' => $dataDaily) );
    echo date('Y-m-d H:i:s') . ": Traffic stats for {$thisUser} saved, month data consumption: {$data['month']}\n";
    $totalDataMonth += $data['month'];  // get the total over the past period
}
$totalDataMonth = number_format( ($totalDataMonth / 1024), 2);
echo date('Y-m-d H:i:s') . ": Traffic parsed, total monthly data consumption: {$totalDataMonth}GiB\n";

