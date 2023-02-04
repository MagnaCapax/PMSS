<?php
/*  Get info on interfaces */
# Determine what is our primary link #TODO: Check based on public address? If this ever bugs, then yes
# if bonding interface found, otherwise first eth interface - This works for us, but may not work in all cases
# if this does not work you can output static info too

#TODO uh oh so much refactoring ahead!

$networkConfig = include '/etc/seedbox/config/network';
$link = $networkConfig['interface'];
$linkSpeed = $networkConfig['speed'];

// None below actually work reliably

/*
$link = trim( shell_exec("/sbin/ifconfig|grep Ethernet|grep -v '\-ifb'|cut -d' ' -f1") );
//$links = explode("\n", $link);
if (strpos($link, 'bond') !== false) $link = 'bond0';
	else $link = 'eth0';

//echo $link . "\n";

$linkSpeed = shell_exec("/sbin/ethtool {$link}|grep Speed");
//echo $linkSpeed;

#TODO Yucky code below, could do with a refactor
if (empty($linkSpeed) or
    (
        strpos($linkSpeed, '10Mb') === false &&
        strpos($linkSpeed, '100Mb') === false &&
        strpos($linkSpeed, '1000Mb') === false &&
        strpos($linkSpeed, '10000Mb') === false &&
        strpos($linkSpeed, '20000Mb') === false

    )
    ) {
        echo "Couldn't determine link speed, string: {$linkSpeed} -- Defaulting to 1000Mbps\n";
        $linkSpeed = 1000;
    }
    elseif (strpos($linkSpeed, '20000Mb') !== false) $linkSpeed = 20000;
    elseif (strpos($linkSpeed, '10000Mb') !== false) $linkSpeed = 10000;
    elseif (strpos($linkSpeed, '1000Mb') !== false) $linkSpeed = 1000;
    elseif (strpos($linkSpeed, '100Mb') !== false) $linkSpeed = 100;
    else $linkSpeed = 10;

//echo "Determined link speed: {$linkSpeed}\n";
//echo "{$link}||{$linkSpeed}\n";

*/
