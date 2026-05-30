<?php
/**
 * Update app installer: vnstat.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Vnstat config + install
require_once '/scripts/lib/networkInfo.php';

$link = isset($link) ? networkInterfaceNameNormalized((string) $link) : '';
$linkSpeed = isset($linkSpeed) && is_numeric($linkSpeed) ? (int) $linkSpeed : 0;

#TODO This should be in the install script
#TODO Use an actual config template
if (!file_exists('/usr/bin/vnstat')) {
    runStep('Installing vnstat', aptCmd('install -y vnstat'));
    if ($link !== '') {
        runStep('Updating vnstat interface database', pmssBuildCommand('vnstat', ['-u', '-i', $link]));
    }
}
if (file_exists('/etc/vnstat.conf')) {	// Fix some default configs! Especially on Deb6+7 this was an issue
    $vnstatConfig = @file_get_contents('/etc/vnstat.conf');
    if ($vnstatConfig === false) {
        echo "Warning: unable to read /etc/vnstat.conf; skipping vnStat config refresh.\n";
        return;
    }

    $vnstatConfig = str_replace('RateUnit 1', 'RateUnit 0', $vnstatConfig);
    if ($linkSpeed > 0) {
        $vnstatConfig = str_replace("MaxBandwidth 100\n", "MaxBandwidth {$linkSpeed}\n", $vnstatConfig);
    }

    if (@file_put_contents('/etc/vnstat.conf', $vnstatConfig) === false) {
        echo "Warning: unable to write /etc/vnstat.conf; skipping vnStat restart.\n";
        return;
    }

    runStep('Restarting vnstat', pmssBuildCommand('/etc/init.d/vnstat', ['restart']));
}
