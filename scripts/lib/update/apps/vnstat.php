<?php
/**
 * Update app installer: vnstat.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Vnstat config + install
require_once '/scripts/lib/networkInfo.php';
require_once __DIR__.'/vnstatConfig.php';

$link = $link ?? '';
$link = networkInterfaceNameNormalized((string) $link);

#TODO This should be in the install script
#TODO Use an actual config template
if (!file_exists('/usr/bin/vnstat')) {
    runStep('Installing vnstat', aptCmd('install -y vnstat'));
    if ($link !== '') {
        runStep('Updating vnstat interface database', pmssBuildCommand('vnstat', ['-u', '-i', $link]));
    }
}
if (file_exists('/etc/vnstat.conf')) {	// Fix some default configs! Especially on Deb6+7 this was an issue
    if (!pmssVnstatConfigRefresh('/etc/vnstat.conf', static function (string $warning): void { echo $warning."\n"; })) return;

    runStep('Restarting vnstat', pmssBuildCommand('/etc/init.d/vnstat', ['restart']));
}
