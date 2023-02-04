#!/usr/bin/php
<?php
// Check that rTorrent instances are running, start if not
echo date('Y-m-d H:i:s') . ': Checking rTorrent instances' . "\n";

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));
$changedConfig = array();


foreach($users AS $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
    
        // if user is suspended, skip it
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www") ) {
            echo "User: {$thisUser} is suspended\n";
            passthru("killall -9 -u {$thisUser}");  // Ensure nothing for the user is running
            continue;  //Suspended
    }
    //echo "Checking: {$thisUser}\n";
    
    $rtorrentLock = null;
    $start = false;
    $pid = null;
    
    $instances = shell_exec('pgrep -u' . $thisUser . ' -f rtorrentExecute.php');
    //echo "Instances:\n{$instances}\n";

    // Let's check socket file
    if (!file_exists("/home/{$thisUser}/.rtorrent.socket")) {
        if (!empty($instances)) `killall -9 -u {$thisUser} 'rtorrent main'`;
        $instances = '';
    }

    if(empty($instances)) {    // No instances at all? Ok time to start rTorrent!
        start($thisUser);
        continue;
    }
   
/* DO WE REALLY need to check also pid file? removed for now -Aleksi 24/02/2021
 
        // User got processes, let's check via lock file and ensure it is actually running
    if (file_exists("/home/{$thisUser}/session/rtorrent.lock")) {
         $rtorrentLock = file_get_contents("/home/{$thisUser}/session/rtorrent.lock");
         //echo "rTorrentLock: {$rtorrentLock}\n";
         
         if (!empty($rtorrentLock)) {
                // The fileformat is actually simple  :)
             $pid = explode(':+', trim($rtorrentLock) );
             $pid = $pid[1];
             //echo("PID:" . $pid . "\nInstances: " . $instances . "\n");

             if (strpos($instances, $pid) === false) {    // No running instance found!
                 start($thisUser);
             }
         } else {
             echo "No certainty, killall!\n";
             passthru('killall -u ' . $thisUser);
             sleep(3); // We got to wait to make certain kill was success.
             
             start($thisUser);
             continue;
         }
    } else {    // Process is running, but no (valid or otherwise) lock file
        
        echo "No lock file found! Killall, restart.\n";
        passthru('killall -9 "rtorrent main" -u '. $thisUser);
        passthru('killall -9 /usr/local/bin/rtorrent -u '. $thisUser);
        sleep(3);
        start($thisUser);
    }

*/

    
    // Check .rtorrent.rc ownership
    if (file_exists("/home/{$thisUser}/.rtorrent.rc")) {
        $owner = posix_getpwuid( fileowner("/home/{$thisUser}/.rtorrent.rc") );
        if ($owner['name'] != 'root') $changedConfig[] = $thisUser . ' -> ' . $owner['name'];
    }

}

if (count($changedConfig) != 0) {
    file_put_contents('/root/changedConfigs', implode("\n", $changedConfig));
} elseif (file_exists('/root/changedConfigs')) unlink('/root/changedConfigs');

function start($user) {    // this actually calls the function to start rTorrent :)
    echo "Starting rTorrent for user: {$user}\n";
    passthru('/scripts/startRtorrent ' . $user);
    sleep(1);
}
