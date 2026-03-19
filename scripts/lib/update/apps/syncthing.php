<?php
/**
 * Update app installer: syncthing.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
$syncthingVersion = 'v1.18.2 "Fermium Flea"';
if (file_exists('/usr/bin/syncthing')
    && strpos((string) @shell_exec('syncthing -version 2>/dev/null'), $syncthingVersion) !== false) {
    return;
}

@unlink('/usr/bin/syncthing');
echo "*** Syncthing not present, downloading and adding!\n";
passthru("wget http://pulsedmedia.com/remote/pkg/syncthing -O /usr/bin/syncthing; chmod 755 /usr/bin/syncthing");
