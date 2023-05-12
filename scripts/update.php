#!/usr/bin/php
<?php
# Pulsed Media Seedbox Management Software "PMSS"
# update system

# The script accepts string with the source for the update: "git/branch" or "release". If empty, it uses
# source specified in /etc/seedbox/config/version.

# Fetch current source and version from the box
$versionFile = '/etc/seedbox/config/version';
if (file_exists($versionFile)) $sourceVersion = file_get_contents($versionFile);
	else $sourceVersion = 'release:2025-05-11';

if (! empty($argv[1]))
    $sourceVersion = $argv[1];

$source = explode(' ', $sourceVersion);
$source = $source[0];
$path = sha1( microtime() . $source . rand(500,9999999999999999) . shell_exec('cat /etc/seedbox/config/version') ); // Pseudo random path, unpredictable enough
switch (true) {
    case (substr($source,0,7) == "release"):
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
	    echo "{$source}:{$newVersion}" > /etc/seedbox/config/version;
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
		mkdir PMSS{$path}; cd PMSS{$path};
	    git clone https://github.com/MagnaCapax/PMSS;
	    mv PMSS/* .; rm -rf PMSS/.git; rmdir PMSS
	    echo "{$source}:{$date}" > /etc/seedbox/config/version;
EOF;
	passthru($script);
	break;
}


passthru("rm -rf /etc/skel/*; rm -rf /scripts/*; cd /tmp/PMSS{$path}; cp -rp scripts /; cp -rpu etc /; cp -rp var /;");  // Update scripts etc.
passthru('chmod o-rwx -R /scripts; chmod o-rwx -R /root; chmod o-rwx -R /etc/skel; chmod o-rwx -R /etc/seedbox;'); // Kind of deprecated but better safe than sorry


# Following is now dynamic because it was just fetched and updated
if (file_exists('/scripts/util/update-step2.php'))
    require '/scripts/util/update-step2.php';
