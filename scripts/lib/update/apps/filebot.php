<?php
/**
 * Update app installer: filebot.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Install FileBot from a pinned package when the expected version is missing.

require_once __DIR__.'/remoteBinary.php';
require_once __DIR__.'/../packageState.php';

$filebotPath = '/usr/bin/filebot';
// The package database knows the version, so a deb-installed FileBot is never run to ask (its
// /usr/bin symlink into /usr/share fails the probe trust checks by design).
$filebotState = pmssPinnedRemoteDebState(pmssPackageStatus('filebot'), pmssPackageVersion('filebot'), '4.9.4');
if ($filebotState === 'current') {
    return;
}
// Not installed through dpkg: keep the guarded binary probe and stale-binary removal.
if ($filebotState === 'legacy' && !pmssPinnedRemoteBinaryPrepareInstall($filebotPath, [escapeshellarg($filebotPath).' -version 2>/dev/null'], '/4\.9\.4 \(r8736\)/', 'FileBot')) {
    return;
}
// A deb installed at another version is upgraded in place by dpkg; no symlink is unlinked.
pmssInstallPinnedRemoteDebPackage(
    'FileBot 4.9.4',
    'https://pulsedmedia.com/remote/pkg/FileBot_4.9.4_amd64.deb',
    '30d1483a6ec3e24df6f518b6f82e4115140be010026e554c15be9a75b47783cc'
);
