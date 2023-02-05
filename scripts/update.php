#!/usr/bin/php
<?php
# Pulsed Media Seedbox Management Software "PMSS"
# update system

#TODO Check if we should even retain this, this is now outdated
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

# Update sources
# TODO Make this just a simple git command perhaps? Consider merits of releases vs. just getting daily commit. This seems a bit complicated way to get latest as well
# TODO This is duplicated in install.sh
passhthru(<<<EOF
cd /tmp;
rm -rf PMSS*;
wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tarball_url/ {print $(NF-1)}' | xargs wget -O PMSS.tar.gz;
mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1;
EOF;
);

# Following is now dynamic because it was just fetched and updated
# TODO soft.sh kinda outdated now ... Should remove it
passthru('bash /tmp/PMSS/soft.sh');


if (file_exists('/scripts/util/update-step2.php'))
    require '/scripts/util/update-step2.php';

