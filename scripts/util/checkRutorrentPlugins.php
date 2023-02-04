#!/usr/bin/php
<?php
echo date('Y-m-d H:i:s') . ': Checking rTorrent instances' . "\n";

$accessIni = file_get_contents('/etc/seedbox/config/template.rutorrent.access');

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
    echo "\nChecking: {$thisUser}\n";

    $userPath = "/home/{$thisUser}/www/rutorrent/plugins/";
    //echo "Path: {$userPath}\n";
    
 /*   if (file_exists($userPath . 'cpuload')) {
        echo "Cpu load exists - deleting!\n";
        shell_exec("rm -rf {$userPath}cpuload");
    }
*/

    if (file_exists($userPath . 'diskspace')) {
        echo "Disk space exists - deleting!\n";
        shell_exec("rm -rf {$userPath}diskspace");
    }
    
    if (!file_exists($userPath . 'hddquota')) {
        echo "HDD Quota does not exist - adding!\n";
        shell_exec("cp -rp /etc/skel/www/rutorrent/plugins/hddquota {$userPath}");
        shell_exec("chown {$thisUser}.{$thisUser} {$userPath}hddquota");
        shell_exec("chmod -R 777 {$userPath}hddquota");
    }
    
    file_put_contents("/home/{$thisUser}/www/rutorrent/conf/access.ini", $accessIni);
}
