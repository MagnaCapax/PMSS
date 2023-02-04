<?php
// Let's install filebot!

$filebotVersion = '4.9.4 (r8736)';
if (file_exists('/usr/bin/filebot') &&
    strpos(`filebot -version`, $filebotVersion) == false ) unlink('/usr/bin/filebot');


if (!file_exists('/usr/bin/filebot')) {
	`cd /tmp; wget http://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb; dpkg -i FileBot_4.9.4_amd64.deb;`;
}

