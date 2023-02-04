#!/usr/bin/php
<?php
// Update & check user quota information
echo date('Y-m-d H:i:s') . ': Updating quota information' . "\n";
//require_once '/scripts/lib/serverApi.php';
//$serverApi = new remoteServerApi();

//$installedTime = filemtime('/scripts'); // To take the minute to send these details :)

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
$changedConfig = array();

foreach($users AS $thisUser) {
#TODO Check that quota is working
    $command = "rm -rf /home/{$thisUser}/.quota; quota -u {$thisUser} -s >> /home/{$thisUser}/.quota; chmod o+r /home/{$thisUser}/.quota";
    passthru($command);
    
    /* We do not use that API anymore
    if (date('i') == date('i', $installedTime) ) {  // Once per hour
        $serverApi->makeCall(
            'userQuota',
            array('username' => $thisUser, 'quota' => trim( shell_exec("quota -u {$thisUser}") ) )
        );
    }
    */
}

/*if (date('i') == date('i', $installedTime) ) {  // Once per hour
    $serverapi->makeCall(
        'serverDiskFree',
        array('df' => trim( shell_exec("df") ) )
    );
}*/