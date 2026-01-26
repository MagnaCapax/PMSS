<?php
// Vnstat config + install
require_once '/scripts/lib/networkInfo.php';

$link = isset($link) ? (string) $link : '';
$linkSpeed = isset($linkSpeed) && is_numeric($linkSpeed) ? (int) $linkSpeed : 0;
$debianVersion = isset($debianVersion) ? $debianVersion : array('0');
if (is_string($debianVersion)) {
    $debianVersion = explode('.', $debianVersion);
}
if (!is_array($debianVersion) || !isset($debianVersion[0])) {
    $debianVersion = array('0');
}
$debianMajor = is_numeric($debianVersion[0]) ? (int) $debianVersion[0] : 0;

#TODO This should be in the install script
#TODO Use an actual config template
if (!file_exists('/usr/bin/vnstat')) {
    passthru('apt-get install vnstat -y');
    if ($link !== '') {
        passthru("vnstat -u -i {$link}");
    }
}
if (file_exists('/etc/vnstat.conf')) {	// Fix some default configs! Especially on Deb6+7 this was an issue
    $vnstatConfig = file_get_contents('/etc/vnstat.conf');
    $vnstatConfig = str_replace('RateUnit 1', 'RateUnit 0', $vnstatConfig);
    if ($linkSpeed > 0) {
        $vnstatConfig = str_replace("MaxBandwidth 100\n", "MaxBandwidth {$linkSpeed}\n", $vnstatConfig);
    }

    file_put_contents('/etc/vnstat.conf', $vnstatConfig);
    passthru('/etc/init.d/vnstat restart');
}


if ($debianMajor === 8 && $link !== '') {
    // Fix VNSTAT backup issue & not updating on Deb8 where base install seems broken.
    `vnstat -u -i {$link}`;
    `chown -R vnstat:vnstat /var/lib/vnstat`;
    `/etc/init.d/vnstat restart`;
}
