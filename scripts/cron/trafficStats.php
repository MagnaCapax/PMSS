!/usr/bin/php
<?php
/**
 * Gather per user traffic usage and calculate statistics
 *
 * @copyright (C) Magna Capax Finland Oy 2023
 * @author Aleksi
 */

require_once '/scripts/lib/traffic.php';
$trafficStatistics = new trafficStatistics;

/* Check runtime directory exists */
if (!file_exists('/var/run/pmss') or !is_readable('/var/run/pmss'))
    shell_exec('mkdir /var/run/pmss');

/* Comparison times for stats collection */
$compareTimeMonth = time() - (30 * 24 * 60 * 60);
$compareTimeWeek = time() - (7 * 24 * 60 * 60);
$compareTimeDay = time() - (24 * 60 * 60);
$compareTimeHour = time() - (60 * 60);
$compareTime15min = time() - (15 * 60);

$totalDataMonth = 0;    // Reset counter, avoid warning message

* Check if the script is running in user-specific processing mode */
if (isset($argv[1])) {
    $username = sanitizeUserInput($_SERVER['argv'][1]);
    if (validateUser($username)) {
        processUserTraffic($trafficStatistics, $username, array(
            'month' => $compareTimeMonth,
            'week' => $compareTimeWeek,
            'day' => $compareTimeDay,
            'hour' => $compareTimeHour,
            '15min' => $compareTime15min
        ));
    } else {
        echo "Invalid user specified: {$username}\n";
    }
    exit;
}

/* Get user list */
$users = array_filter(glob('/var/log/pmss/traffic/*'), 'is_file');
$users = array_map(function ($path) {
    return basename($path);
}, $users);
if (count($users) == 0)
    die("No users in this system!\n");

/* Control job mode: launch separate processes for each user */
$scriptPath = escapeshellarg($_SERVER['argv'][0]);
foreach ($users as $user) {
    $userArg = escapeshellarg($user);
    $command = "nohup {$scriptPath} {$userArg} >> /var/log/pmss/trafficStats.log 2>&1 &";
    passthru($command);
}

/**
 * Sanitize user input by removing any non-alphanumeric characters, except for hyphens and underscores
 *
 * @param string $input The input string to sanitize
 *
 * @return string The sanitized string
 */
function sanitizeUserInput($input){
    return preg_replace('/[^a-zA-Z0-9-_]/', '', $input);
}

/**
 * Validate the user by checking if the traffic data file, home directory, and /etc/passwd entry exists
 *
 * @param string $username The username to validate
 *
 * @return bool True if the user is valid, false otherwise
 */
function validateUser($username){
    $path = "/var/log/pmss/traffic/{$username}";
    $homePath = "/home/{$username}";
    return file_exists($path) && is_readable($path) && userExistsInPasswd($username) && file_exists($homePath) && is_dir($homePath);
}

/**
 * Process the traffic data for a given user and update the statistics
 *
 * @param object $trafficStatistics An instance of the trafficStatistics class
 * @param string $user The user whose traffic data needs to be processed
 * @param array $compareTimes An array containing the comparison times for different periods
 */
function processUserTraffic($trafficStatistics, $user, $compareTimes){
    if (!validateUser($user)) {
        echo date('Y-m-d H:i:s') . ": Invalid user {$user}\n";
        return;
    }

    $thisUserTrafficData = $trafficStatistics->getData($user, (35 * 24 * 60) / 5);

    if (empty($thisUserTrafficData)) {
        echo date('Y-m-d H:i:s') . ": No data for user {$user}\n";
        return;
    }

    $trafficData = explode("\n", $thisUserTrafficData);
    if (count($trafficData) < 2) {
        echo date('Y-m-d H:i:s') . ": Too little data for {$user}\n";
        return;
    }

    $data = array(
        'month' => 0,
        'week' => 0,
        'day' => 0,
        'hour' => 0,
        '15min' => 0
    );

    $dataDaily = array();
    $dataDailyFirst = '';

    foreach ($trafficData as $thisLine) {
        $thisData = $trafficStatistics->parseLine($thisLine);
        if ($thisData === false) {
            echo date('Y-m-d H:i:s') . ": Parsing line failed for {$user}, line: {$thisLine}\n";
            continue;
        }

        foreach ($compareTimes as $key => $compareTime) {
            if ($thisData['timestamp'] >= $compareTime) {
                $data[$key] += $thisData['data'];
            }
        }

        if (empty($dataDailyFirst)) {
            $dataDailyFirst = date('Y/m/d', $thisData['timestamp']);
        }

        if (date('Y/m/d', $thisData['timestamp']) != $dataDailyFirst) {
            @$dataDaily[date('Y/m/d', $thisData['timestamp'])] += $thisData['data'];
        }
    }

    $dataDisplay = formatDataDisplay($data);
    $trafficStatistics->saveUserTraffic($user, array("raw" => $data, "display" => $dataDisplay, 'daily' => $dataDaily));
    echo date('Y-m-d H:i:s') . ": Traffic stats for {$user} saved, month data consumption: {$data['month']}\n";
}


/**
 * Format the traffic data for display by converting bytes to the appropriate unit (MiB, GiB, TiB)
 *
 * @param array $data An array containing the raw traffic data in bytes
 *
 * @return array An array containing the formatted traffic data
 */
function formatDataDisplay($data){
    $dataDisplay = array();
    foreach ($data as $key => $value) {
        if (($value / 1024 / 1024) > 1) {
            $dataDisplay[$key] = round(($value / 1024 / 1024), 2) . 'TiB';
        } elseif (($value / 1024) > 1) {
            $dataDisplay[$key] = round(($value / 1024), 2) . 'GiB';
        } else {
            $dataDisplay[$key] = round($value, 2) . 'MiB';
        }
    }
    return $dataDisplay;
}




function userExistsInPasswd($username){
    $passwd = file_get_contents('/etc/passwd');
    return preg_match("/^{$username}:/m", $passwd) === 1;
}


/**
 * Retrieve the specified number of lines of traffic data for the given user using the tail command
 *
 * @param string $thisUser The user for whom the data should be retrieved
 * @param int $lines The number of lines to retrieve, starting from the end of the file
 *
 * @return string The requested lines of data, separated by newline characters
 */
function getData($thisUser, $lines)
{
    $filepath = escapeshellarg("/var/log/pmss/traffic/{$thisUser}");
    $linesToRetrieve = escapeshellarg("-n {$lines}");
    return shell_exec("tail {$linesToRetrieve} {$filepath}");
}

