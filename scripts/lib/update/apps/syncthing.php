<?php
/**
 * Update app installer: syncthing.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/remoteBinary.php';

$syncthingVersion = 'v2.0.13';
$syncthingArchive = 'syncthing-linux-amd64-'.$syncthingVersion.'.tar.gz';
$syncthingUrl = 'https://github.com/syncthing/syncthing/releases/download/'.$syncthingVersion.'/'.$syncthingArchive;
$syncthingSha256 = '55ffe8a5deefc373c95a760ab71c3cbe77da493bb9bf426525d33c2fe22ead88';
if (!pmssPinnedRemoteAmd64ArtifactsSupported()) {
    logmsg('[SKIP] Syncthing bootstrap skipped on unsupported architecture: '.php_uname('m'));
    return;
}

if (file_exists('/usr/bin/syncthing')
    && pmssAppVersionProbeMatch(['/usr/bin/syncthing version 2>/dev/null'], '/'.preg_quote($syncthingVersion, '/').'/') !== null) {
    return;
}

if (file_exists('/usr/bin/syncthing') || is_link('/usr/bin/syncthing')) {
    @unlink('/usr/bin/syncthing');
}
echo "*** Syncthing not present, downloading and adding!\n";

pmssRunPinnedRemoteArchiveStep('Syncthing '.$syncthingVersion, $syncthingUrl, $syncthingSha256, $syncthingArchive, 'syncthing-linux-amd64-'.$syncthingVersion, 'Installing Syncthing binary', [
    'install -m 0755 '.escapeshellarg('/root/compile/syncthing-linux-amd64-'.$syncthingVersion.'/syncthing').' /usr/bin/syncthing',
]);
