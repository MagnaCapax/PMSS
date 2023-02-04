<?php
// Vnstat config + install
require_once '/scripts/lib/networkInfo.php';


#TODO This should be in the install script
#TODO Use an actual config template
if (!file_exists('/usr/bin/vnstat')) {
    passthru('apt-get install vnstat -y');
    passthru("vnstat -u -i {$link}");
}
if (file_exists('/etc/vnstat.conf')) {	// Fix some default configs! Especially on Deb6+7 this was an issue
    $vnstatConfig = file_get_contents('/etc/vnstat.conf');
    $vnstatConfig = str_replace('RateUnit 1', 'RateUnit 0', $vnstatConfig);
    $vnstatConfig = str_replace("MaxBandwidth 100\n", "MaxBandwidth {$linkSpeed}\n", $vnstatConfig);

    file_put_contents('/etc/vnstat.conf', $vnstatConfig);
    passthru('/etc/init.d/vnstat restart');
}


if ($debianVersion[0] == 8) {
    #Fix VNSTAT backup issue & not updating Deb8. The base install seems a bit broken
    `vnstat -u -i {$link}`;
    `chown -R vnstat:vnstat /var/lib/vnstat`;
    `/etc/init.d/vnstat restart`;

    passthru("chown vnstat.vnstat /var/lib/vnstat -R; chown vnstat.vnstat /var/lib/vnstat/* -R;");


}

