#!/usr/bin/php
<?php
/**
 * PMSS Distribution Upgrade Helper
 * Supports Debian 10->11 and 11->12 upgrades.
 * Run via /scripts/update.php --updatedistro
 */
require_once '/scripts/lib/update.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: {$argv[0]}\n";
    echo "Upgrades Debian 10→11 or 11→12 automatically.\n";
    exit(0);
}

requireRoot();

$distro = getDistroName();
$version = getDistroVersion();

if ($distro !== 'debian') {
    echo "Unsupported distro: $distro\n";
    exit(1);
}

switch ($version) {
    case '10':
        echo "Upgrading Debian 10 -> 11\n";
        runCommand("export DEBIAN_FRONTEND=noninteractive; " .
            "sed -i 's/\\<buster\\>/bullseye/g' /etc/apt/sources.list; " .
            "sed -i 's#bullseye/updates#bullseye-security#g' /etc/apt/sources.list; " .
            "sed -i 's/\\<buster\\>/bullseye/g' /etc/apt/sources.list.d/*.list; " .
            "sed -i 's#bullseye/updates#bullseye-security#g' /etc/apt/sources.list.d/*.list; " .
            "apt-get update; " .
            "apt-get upgrade -y -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\"; " .
            "apt-get full-upgrade -y -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\"; " .
            "apt-get autoremove -y", true);
        break;
    case '11':
        echo "Upgrading Debian 11 -> 12\n";
        runCommand("export DEBIAN_FRONTEND=noninteractive; " .
            "sed -i 's/\\<bullseye\\>/bookworm/g' /etc/apt/sources.list; " .
            "sed -i 's#bookworm/updates#bookworm-security#g' /etc/apt/sources.list; " .
            "sed -i 's/\\<bullseye\\>/bookworm/g' /etc/apt/sources.list.d/*.list; " .
            "sed -i 's#bookworm/updates#bookworm-security#g' /etc/apt/sources.list.d/*.list; " .
            "apt-get update; " .
            "apt-get upgrade -y -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\"; " .
            "apt-get full-upgrade -y -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\"; " .
            "apt-get autoremove -y", true);
        break;
    default:
        echo "No upgrade routine for Debian $version\n";
        break;
}
