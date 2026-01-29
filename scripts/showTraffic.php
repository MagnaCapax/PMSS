#!/usr/bin/env php
<?php
/**
 * PMSS script: show Traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once '/scripts/lib/traffic.php';
$trafficStatistics = new trafficStatistics;
/* Display per user traffic */

$statsDir = '/var/run/pmss/trafficStats';
$users = loadTrafficUsers($statsDir);
if (count($users) === 0) {
    die("No users in this system!\n");
}
sort($users, SORT_NATURAL | SORT_FLAG_CASE);
//sort($users);

$dataMonthTotal = 0;
$dataMonthTotalLocal = 0;
$missingStats = [];

echo "Legend:\n\t USER: Traffic: Data Month / Week / Day              DATARATES: Rate Week / Rate Day / Rate Hour / Rate 15min\n";

foreach($users AS $thisUser) {
    //if (!file_exists("/home/{$thisUser}/.trafficData")) continue;
    $statsPath = "{$statsDir}/{$thisUser}";
    if (!is_file($statsPath)) {
        $missingStats[] = $thisUser;
        continue;
    }
    
    $data = unserialize( file_get_contents($statsPath) );
    if (!is_array($data)) {
        continue;
    }
    
    if (empty($data['raw']['month']) or
        $data['raw']['month'] == 0) continue;
    
    $dataMonthTotal += $data['raw']['month'];
    if (strpos($thisUser, '-localnet') !== false) $dataMonthTotalLocal += $data['raw']['month'];
    //var_dump($data);
    //die();
    $dataDisplay = $data['raw'];
    foreach($dataDisplay AS $thisKey => $thisData) {
        $dataDisplay[$thisKey] = formatTrafficAmount($thisData);
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
if (!empty($missingStats)) {
    echo "* Missing traffic stats for ".count($missingStats)." users (run trafficStats to rebuild).\n";
}

function loadTrafficUsers(string $statsDir): array {
    $lines = [];
    $rc = 0;
    exec('/scripts/listUsers.php', $lines, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "Error: listUsers.php failed; aborting.\n");
        exit(1);
    }
    $users = array_filter(array_map('trim', $lines), 'strlen');
    if (empty($users)) {
        return [];
    }
    $withLocalnet = [];
    foreach ($users as $user) {
        $withLocalnet[] = $user;
        if (is_file($statsDir.'/'.$user.'-localnet')) {
            $withLocalnet[] = $user.'-localnet';
        }
    }
    return $withLocalnet;
}

function formatTrafficAmount($value): string {
    if ( ($value / 1024 / 999) > 1 ) return round( ($value / 1024 / 1024), 2) . 'TiB';
    if ( ($value / 999) > 1 )        return round( ($value / 1024), 2) . 'GiB';
    return round($value, 2) . 'MiB';
}

function makeTextWidth($text, $width = 80, $addAfter = true) {
    $padType = $addAfter ? STR_PAD_RIGHT : STR_PAD_LEFT;
    return str_pad($text, $width, ' ', $padType);
}
