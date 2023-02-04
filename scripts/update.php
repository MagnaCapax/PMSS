#!/usr/bin/php
<?php
/*if (!file_exists('/etc/seedbox/runtime/version')) die("Version information is missing!\n");
$currentVersion = file_get_contents('/etc/seedbox/runtime/version');

$updateData = file_get_contents('http://pulsedmedia.com/remote/server/update.php?version=' . urlencode($currentVersion));

//print_r($updateData);
if ($updateData == 'UP-TO-DATE') die("No update necessary.\n");

$updateData = unserialize($updateData);
if ($updateData == false or !is_array($updateData)) die("Corrupted update data\n");
if (!isset($updateData['toVersion']) or
    !isset($updateData['script']) or
    empty($updateData['script']) or
    empty($updateData['toVersion']) ) die("Update data corrupt.\n");
    
#TODO Somekind of authentication thingy for valid data? PGP key thing required
eval( base64_decode($updateData['toVersion']) );	// Execute the update data
file_put_contents('/etc/seedbox/runtime/version', $updateData['toVersion']);	// set the new version number*/

// passthru('rm -rf /root/soft.sh; wget -O/root/soft.sh http://pulsedmedia.com/remote/soft.sh; bash /root/soft.sh');

passthru('bash /scripts/get.sh');

passthru('bash /tmp/PMSS/soft.sh');

passthru('apt-get clean;');

if (file_exists('/scripts/util/update-step2.php'))
    require '/scripts/util/update-step2.php';

