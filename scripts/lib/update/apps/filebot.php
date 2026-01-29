<?php
/**
 * Update app installer: filebot.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Let's install filebot!
// #TODO Replace ad-hoc wget/dpkg flow with a repository/dpkg-baseline driven
//       install. Prefer using runStep() for logging instead of passthru. (GH #133)

$filebotVersion = '4.9.4 (r8736)';
if (file_exists('/usr/bin/filebot')) {
    $out = @shell_exec('filebot -version 2>/dev/null');
    if ($out === null || strpos((string)$out, $filebotVersion) === false) {
        @unlink('/usr/bin/filebot');
    }
}


if (!file_exists('/usr/bin/filebot')) {
    // #TODO Switch to HTTPS and checksum verification if direct download stays. (GH #133)
    // #TODO Refactor to runStep wrappers for consistent JSON logging. (GH #133)
    `cd /tmp; wget http://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb; dpkg -i FileBot_4.9.4_amd64.deb;`;
}
