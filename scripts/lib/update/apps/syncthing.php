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

if (!in_array(php_uname('m'), ['x86_64', 'amd64'], true)) {
    logmsg('[SKIP] Syncthing bootstrap skipped on unsupported architecture: '.php_uname('m'));
    return;
}

if (file_exists('/usr/bin/syncthing')
    && strpos((string) @shell_exec('syncthing version 2>/dev/null'), $syncthingVersion) !== false) {
    return;
}

@unlink('/usr/bin/syncthing');
echo "*** Syncthing not present, downloading and adding!\n";

$archivePath = pmssFetchPinnedRemoteFile('Syncthing '.$syncthingVersion, $syncthingUrl, $syncthingSha256);
if (!is_string($archivePath) || $archivePath === '') {
    return;
}

try {
    runStep('Installing Syncthing binary', implode(' && ', [
        'set -e',
        'mkdir -p /root/compile',
        'cd /root/compile',
        'rm -rf '.escapeshellarg('syncthing-linux-amd64-'.$syncthingVersion).' '.escapeshellarg($syncthingArchive),
        'cp '.escapeshellarg($archivePath).' '.escapeshellarg($syncthingArchive),
        'tar -xzf '.escapeshellarg($syncthingArchive),
        'install -m 0755 '.escapeshellarg('/root/compile/syncthing-linux-amd64-'.$syncthingVersion.'/syncthing').' /usr/bin/syncthing',
    ]));
} finally {
    @unlink($archivePath);
}
