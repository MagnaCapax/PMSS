<?php
// Install syncthing

$syncthingVersion = 'v1.18.2 "Fermium Flea"';
if (file_exists('/usr/bin/syncthing') &&
    strpos(`syncthing -version`, $syncthingVersion) == false ) unlink('/usr/bin/syncthing');

if (!file_exists('/usr/bin/syncthing')) {
    echo "*** Syncthing not present, downloading and adding!\n";
    passthru("wget http://pulsedmedia.com/remote/pkg/syncthing -O /usr/bin/syncthing; chmod 755 /usr/bin/syncthing");
}
