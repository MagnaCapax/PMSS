<?php
/**
 * Update app installer: filebot.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Install FileBot from a pinned package when the expected version is missing.

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/remoteBinary.php';

$filebotVersion = '4.9.4 (r8736)';
if (file_exists('/usr/bin/filebot')) {
    $out = @shell_exec('filebot -version 2>/dev/null');
    if ($out === null || strpos((string)$out, $filebotVersion) === false) {
        @unlink('/usr/bin/filebot');
    }
}

if (!file_exists('/usr/bin/filebot')) {
    $filebotUrl = 'https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb';
    $filebotSha256 = '30d1483a6ec3e24df6f518b6f82e4115140be010026e554c15be9a75b47783cc';
    $filebotLabel = 'FileBot 4.9.4';

    pmssInstallPinnedRemoteDebPackage($filebotLabel, $filebotUrl, $filebotSha256);
}
