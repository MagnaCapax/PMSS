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
$syncthingSha256 = '144ff4e61dfdef37ebf6c7b2e2e8de8f0ee4d978614aea2f7dd943dce6adcd88';
if (!pmssPinnedRemoteAmd64ArtifactsSupported()) {
    logmsg('[SKIP] Syncthing bootstrap skipped on unsupported architecture: '.php_uname('m'));
    return;
}

if (file_exists('/usr/bin/syncthing')
    && strpos((string) @shell_exec('syncthing version 2>/dev/null'), $syncthingVersion) !== false) {
    return;
}

@unlink('/usr/bin/syncthing');
echo "*** Syncthing not present, downloading and adding!\n";

pmssRunPinnedRemoteArchiveStep('Syncthing '.$syncthingVersion, $syncthingUrl, $syncthingSha256, $syncthingArchive, 'syncthing-linux-amd64-'.$syncthingVersion, 'Installing Syncthing binary', [
    'install -m 0755 '.escapeshellarg('/root/compile/syncthing-linux-amd64-'.$syncthingVersion.'/syncthing').' /usr/bin/syncthing',
]);
