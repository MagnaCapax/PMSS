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
    // MaxBandwidth: the shipped default (100 on old vnStat, 1000 on 2.x) makes vnStat discard real
    // traffic as "impossible" on 10G+/bonded/virtio links whenever per-NIC speed detection fails or
    // is wrong — the cause of fleet-wide traffic-stat gaps. Set a fixed high ceiling (50 Gbit, above
    // any current NIC) and disable detection so no genuine sample is ever discarded, regardless of
    // interface. The value is matched by regex (not a literal "100") so it applies from any current
    // setting and is idempotent across updates. Counter-rollover errors (>>50 Gbit) are still caught.
    $vnstatConfig = preg_replace('/^MaxBandwidth\s+\d+/m', 'MaxBandwidth 50000', $vnstatConfig, -1, $mbCount);
    if ($mbCount === 0) { $vnstatConfig .= "\nMaxBandwidth 50000\n"; }
    $vnstatConfig = preg_replace('/^BandwidthDetection\s+\d+/m', 'BandwidthDetection 0', $vnstatConfig, -1, $bdCount);
    if ($bdCount === 0) { $vnstatConfig .= "\nBandwidthDetection 0\n"; }

    if (@file_put_contents('/etc/vnstat.conf', $vnstatConfig) === false) {
        echo "Warning: unable to write /etc/vnstat.conf; skipping vnStat restart.\n";
        return;
    }

    runStep('Restarting vnstat', pmssBuildCommand('/etc/init.d/vnstat', ['restart']));
}
