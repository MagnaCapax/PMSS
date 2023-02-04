#!/usr/bin/php
<?php
// Local key is to receive commands
if (!file_exists('/etc/seedbox/config/api.localKey')) {
 echo "# Creating API Local Key";
 $hostname = file_get_contents('/etc/hostname');
 
 $keyString = "zaltedLocalKey" . date('Y-m-d H:i:s') . $hostname . round(rand(0, 5000000000000));
 
 for($i=0; $i<13; ++$i)
    $keyString .= round(rand(1, 100));
    
 $keyString .= time(); 
 $key = sha1($keyString);
 
 $disks = shell_exec('df -h');
 $blkid = shell_exec('blkid');
 $key = sha1($key . $disks . $blkid . time() . rand(1000,20000) .'z2');
 
 echo "Key for node " . sha1($hostname) . " is:\n\t{$key}\n";

 file_put_contents('/etc/seedbox/config/api.localKey', $key);
 chmod('/etc/seedbox/config/api.localKey', '600');
}

// Remote key is to reporting back to central server
if (!file_exists('/etc/seedbox/config/api.remoteKey')) {
 echo "# Creating API Remote Key";
 $hostname = file_get_contents('/etc/hostname');
 
 $keyString = "r3m073k37" . date('Y-m-d H:i:s') . $hostname . round(rand(1000, 5000000000000));
 
 for($i=0; $i<15; ++$i)
    $keyString .= round(rand(1, 113));
    
 $keyString .= microtime();
 
 $key = sha1($keyString . gethostbyname('pulsedmedia.com') );
 
 echo "Key for node " . sha1($hostname) . " is:\n\t{$key}\n";

 file_put_contents('/etc/seedbox/config/api.remoteKey', $key);
 chmod('/etc/seedbox/config/api.remoteKey', '600');
}
    

