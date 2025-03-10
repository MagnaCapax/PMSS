#!/usr/bin/php
<?php
/**
 * Pulsed Media Seedbox Management Software "PMSS" Update Script
 *
 * This script performs system updates for PMSS and is the "static" portion, this
 * is executed before scripts are updated so will update only for the next update.
 *
 * The script accepts string with the source for the update: "git/branch" or "release". If empty, it uses
 * source specified in /etc/seedbox/config/version.
 * A bit broken so specify date with : in the end sometimes, ie. git/main:2025-02-18
 *
 */
#TODO Refactor the branch selection completely


#Parse version string
$default_repository = "https://github.com/MagnaCapax/PMSS";

$versionStrIndex=0;
$scriptonly=false;

function parse_version_string($input_string, &$type, &$repository, &$branch, &$date) {
    global $default_repository;

    if (preg_match('/(^git|^release)\/(.*)[:]?([0-9]{4}-[0-9]{2}-[0-9]{2}([ ]?[0-9]{2}[:][0-9]{2})?)$/', $input_string, $matches)) {
        $type = $matches[1];
		$url = $matches[2];
		$date = $matches[3];
        echo "Type: $type\n";
		echo "URL: $url\n";
        echo "Date: $date\n";
		if(preg_match('/(.*[^:])[:](.*[^:])[:]?$/', $url, $more_matches)){
			echo "url matches regex\n";	
			$repository = $more_matches[1];
			$branch = $more_matches[2];
			echo "Repository: $repository\n";
            echo "Branch: $branch\n";
		} elseif(preg_match('/(^main)[:]$/', $url, $more_matches)){
			echo "url matches main\n";
			$repository=$default_repository;
            $branch=$more_matches[1];
            echo "Repository: $repository\n";
            echo "Branch: $branch\n";
		} else {
			echo "url doesn't match\n";
		}
    } else {
        echo "Invalid input format.\n";
    }
}

# Function to parse command line options
function parse_args($args){
    $i=0;
    foreach($args as $arg){
        if($arg == "--scriptonly"){
            global $scriptonly;
            $scriptonly=true;
        }
        if(!strncmp($arg, "git/", 4) || !strncmp($arg, "release/", 8)){
            global $versionStrIndex ;
            $versionStrIndex = $i;
        }
        $i++;
    }
}

$versionstring="";
$repository="";
$branch="";
$date="";
$type="";

# Parse commandline options
parse_args($argv);

# Fetch current source and version for this server
$versionFile = '/etc/seedbox/config/version';
if (file_exists($versionFile) && filesize($versionFile) > 0) {		// If empty ignore it.
    $sourceVersion = file_get_contents($versionFile);
} else {
    $sourceVersion = 'release:2025-05-11';
}

if ($versionStrIndex != 0)
    $sourceVersion = $argv[$versionStrIndex];

parse_version_string($sourceVersion, $type, $repository, $branch, $date);
$source = ($type == "release" ? "$type/$date" : "$type/$repository:$branch");
$path = sha1( microtime() . $source . rand(500,9999999999999999) . shell_exec('cat /etc/seedbox/config/version') ); // Pseudo random path, unpredictable enough
switch (true) {
    case ($type == "release"):
	echo "Using releases as the source!\n";
	$newVersion = trim(shell_exec(<<<EOF
	    wget https://api.github.com/repos/MagnaCapax/PMSS/releases/latest -O - | awk -F \" -v RS="," '/tag_name/ {print $(NF-1)}'
EOF
	));
	$source = "release";
	$script = <<<EOF
	    cd /tmp;
	    rm -rf PMSS*;
	    wget "https://api.github.com/repos/MagnaCapax/PMSS/tarball/{$newVersion}" -O PMSS{$path}.tar.gz;
	    mkdir PMSS{$path} && tar -xzf PMSS{$path}.tar.gz -C PMSS{$path} --strip-components 1;
	    mv PMSS{$path}/* .; rm -rf PMSS{$path}
	    echo "{$source}:{$newVersion}" > /etc/seedbox/config/version;
EOF;
	passthru($script);
	break;

    case ($type == "git"):
	echo "Using GitHub branch as the source!\n";
	echo "repository: $repository branch: $branch \n";
		
	$date = date("Y-m-d H:i");
	$script = <<<EOF
	    cd /tmp;
	    rm -rf PMSS*;
	    mkdir PMSS{$path}; cd PMSS{$path};
		git clone $repository PMSS;
		cd PMSS;
		git checkout $branch;
		cd ..;
		mv PMSS/* .; rm -rf PMSS
	    echo "{$source}:{$date}" > /etc/seedbox/config/version;
EOF;
	passthru($script);
	break;
}


passthru("rm -rf /etc/skel/*; rm -rf /scripts/*; cd /tmp/PMSS{$path}; cp -rp scripts /; cp -rpu etc /; cp -rp var /;");  // Update scripts etc.
passthru('chmod o-rwx -R /scripts; chmod o-rwx -R /root; chmod o-rwx -R /etc/skel; chmod o-rwx -R /etc/seedbox;'); // Kind of deprecated but better safe than sorry


# Following is now dynamic because it was just fetched and updated
if (file_exists('/scripts/util/update-step2.php') && $scriptonly != true)
    require '/scripts/util/update-step2.php';
