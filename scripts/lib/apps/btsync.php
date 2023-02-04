<?php
//Btsync 1.4, 2.2 + Rslsync installer

if (!file_exists('/usr/bin/btsync1.4')) {
    echo "*** BTSync 1.4 not present, downloading and adding!\n";
    passthru("wget http://pulsedmedia.com/remote/pkg/btsync -O /usr/bin/btsync1.4; chmod 755 /usr/bin/btsync1.4");
}

if (!file_exists('/usr/bin/btsync2.2')) {
    echo "*** BTSync 2.2 not present, downloading and adding!\n";
    passthru("wget http://pulsedmedia.com/remote/pkg/btsync2.2 -O /usr/bin/btsync2.2; chmod 755 /usr/bin/btsync2.2");
}

unlink('/usr/bin/btsync');	#TODO Temporary only for transition period. Remove this after 04/2020
if (!file_exists('/usr/bin/btsync')) {
    passthru('ln -s /usr/bin/btsync2.2 /usr/bin/btsync');
}


// Install resilio sync
if (!file_exists('/usr/bin/rslsync')) {
    echo "*** Resilio sync not present, downloading and adding!\n";
    passthru("wget http://pulsedmedia.com/remote/pkg/rslsync -O /usr/bin/rslsync; chmod 755 /usr/bin/rslsync");
}
