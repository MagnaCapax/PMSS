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
# TODO This is duplicated in install.sh
# If no argument, update from git main, if argument "release" update from latest release, if anything else treat it as a branch to update from.

$sourceVersion = file_get_contents('/etc/seedbox/config/version');
if (! empty($argv[1]))
    $sourceVersion = $argv[1];

$source = explode(' ', $sourceVersion);
$source = $source[0];

switch (true) {
    case (substr($source,0,7) == "release"):
	echo "Using releases as the source!\n";
	$newVersion = trim(shell_exec(<<<EOF
	    wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tag_name/ {print $(NF-1)}'
	EOF));
	$source = "release";
	$script = <<<EOF
	    cd /tmp;
	    rm -rf PMSS*;
	    wget "https://api.github.com/repos/MagnaCapax/PMSS/tarball/{$newVersion}" -O PMSS.tar.gz;
	    mkdir PMSS && tar -xzf PMSS.tar.gz -C PMSS --strip-components 1;
	    echo "{$source} {$newVersion}" > /etc/seedbox/config/version;
	EOF;
	passthru($script);
	break;

    case (substr($source, 0, 3) == "git"):
	echo "Using GitHub branch as the source!\n";
	$branch = substr($source, 4); // Get branch string
	$date = date("D M j G:i:s T Y");
	$script = <<<EOF
	    cd /tmp;
	    rm -rf PMSS*;
	    git clone https://github.com/MagnaCapax/PMSS;
	    cd PMSS;
	    git checkout {$branch};
	    echo "{$source} {$date}" > /etc/seedbox/config/version;
	EOF;
	passthru($script);
	break;
}

# Following is now dynamic because it was just fetched and updated
# TODO soft.sh kinda outdated now ... Should remove it
passthru('bash /tmp/PMSS/soft.sh');

if (file_exists('/scripts/util/update-step2.php'))
    require '/scripts/util/update-step2.php';

