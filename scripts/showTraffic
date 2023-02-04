#!/usr/bin/php
<?php
require_once '/scripts/lib/traffic.php';
$trafficStatistics = new trafficStatistics;
/* Display per user traffic */

//$users = trim( `/scripts/listUsers.php` );
$users = trim( `ls /var/log/pmss/traffic/|grep -v 'error.log'` );       // Hack to parse for every file found
$users = explode("\n", $users);
if (count($users) == 0) die("No users in this system!\n");
//sort($users);

$dataMonthTotal = 0;
$dataMonthTotalLocal = 0;

echo "Legend:\n\t USER: Traffic: Data Month / Week / Day              DATARATES: Rate Week / Rate Day / Rate Hour / Rate 15min\n";

foreach($users AS $thisUser) {
    //if (!file_exists("/home/{$thisUser}/.trafficData")) continue;
    if (!file_exists("/var/run/pmss/trafficStats/{$thisUser}")) {
        echo "\t**** Missing data file: {$thisUser}\n";
        continue;
    }
    
    $data = unserialize( file_get_contents("/var/run/pmss/trafficStats/{$thisUser}") );
    
    if (empty($data['raw']['month']) or
        $data['raw']['month'] == 0) continue;
    
    $dataMonthTotal += $data['raw']['month'];
    if (strpos($thisUser, '-localnet') !== false) $dataMonthTotalLocal += $data['raw']['month'];
    //var_dump($data);
    //die();
    $dataDisplay = $data['raw'];
    foreach($dataDisplay AS $thisKey => $thisData) {
        if ( ($thisData / 1024 / 999) > 1 )    $dataDisplay[$thisKey] = round( ($thisData / 1024 / 1024), 2) . 'TiB';
        elseif ( ($thisData / 999) > 1 )       $dataDisplay[$thisKey] = round( ($thisData / 1024), 2) . 'GiB';
        else                                    $dataDisplay[$thisKey] = round($thisData, 2) . 'MiB';
    }
       
    $dataRates = array(
        'week' => round( ( $data['raw']['week'] / (7 * 24 * 60 * 60) ), 2),
        'day' => round( ( $data['raw']['day'] / (24 * 60 * 60) ), 2),
        'hour' => round( ($data['raw']['hour'] / (60 * 60) ), 2),
        '15min' => round( ($data['raw']['15min'] / (15 * 60) ), 2)
    );
    
    $displayUser = str_replace('-localnet', ' (L)', $thisUser);
    $line = makeTextWidth("{$displayUser}:", 14);
        // Following should always result in same width
    $line .= makeTextWidth($dataDisplay['month'], 9, false) . ' / ' . makeTextWidth($dataDisplay['week'], 9, false) . ' / ' . makeTextWidth($dataDisplay['day'], 9, false);
    $line .= '            Datarates: ' . makeTextWidth($dataRates['week'], 5, false) . ' / ' . makeTextWidth($dataRates['day'], 5, false) . ' / ' .
        makeTextWidth($dataRates['hour'], 5, false) . ' / ' . makeTextWidth($dataRates['15min'], 5, false);
        
    echo $line . "\n";
    //echo "User: {$thisUser} \t Traffic: {$dataDisplay['week']}, day: {$dataDisplay['day']}, hour: {$dataDisplay['hour']}, 15min: {$dataDisplay['15min']}\n";
    //echo "\tData rates:\t Week: {$dataRates['week']}M/s   Day: {$dataRates['day']}M/s    Hour: {$dataRates['hour']}M/s    15min: {$dataRates['15min']}M/s\n\n";

}
$dataMonthTotal = number_format( ($dataMonthTotal / 1024 / 1024), 2);
$dataMonthTotalLocal = number_format( ($dataMonthTotalLocal / 1024 / 1024), 2);
echo "* Month Total: {$dataMonthTotal}TiB - Local Total: {$dataMonthTotalLocal}TiB\n";

function makeTextWidth($text, $width = 80, $addAfter = true) {
    if (strlen($text) < $width) {
        $add = $width - strlen($text);
        $addText = '';
        
        for($i=1; $i<=$add; ++$i)
            $addText .= ' ';
    
        if ($addAfter == true)
            return $text . $addText;
            else return $addText . $text;
    } else return $text;


}