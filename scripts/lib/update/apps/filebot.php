<?php
/**
 * Update app installer: filebot.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Install FileBot from a pinned package when the expected version is missing.

require_once __DIR__.'/remoteBinary.php';

$filebotPath = '/usr/bin/filebot';
if (file_exists($filebotPath)
    && pmssAppVersionProbeMatch([escapeshellarg($filebotPath).' -version 2>/dev/null'], '/4\.9\.4 \(r8736\)/') !== null) {
    return;
}

@unlink($filebotPath);
pmssInstallPinnedRemoteDebPackage(
    'FileBot 4.9.4',
    'https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb',
    '30d1483a6ec3e24df6f518b6f82e4115140be010026e554c15be9a75b47783cc'
);
