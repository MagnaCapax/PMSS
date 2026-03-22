<?php
/**
 * Update app installer: firehol.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/remoteBinary.php';

$fireholVersion = '3.1.8';
$fireholArchive = 'firehol-'.$fireholVersion.'.tar.gz';
$fireholUrl = 'https://github.com/firehol/firehol/releases/download/v'.$fireholVersion.'/'.$fireholArchive;
$fireholSha256 = 'b882e999003dae86a447965986025a5af38ead250263c9232d02d06be07e0812';

if (file_exists('/usr/local/sbin/firehol')) {
    return;
}

logmsg('*** FireHOL missing; building from pinned source release');

$archivePath = pmssFetchPinnedRemoteFile('FireHOL '.$fireholVersion.' source', $fireholUrl, $fireholSha256);
if (!is_string($archivePath) || $archivePath === '') {
    return;
}

try {
    runStep('Building FireHOL from source', implode(' && ', [
        'set -e',
        'mkdir -p /root/compile',
        'cd /root/compile',
        'rm -rf '.escapeshellarg('firehol-'.$fireholVersion).' '.escapeshellarg($fireholArchive),
        'cp '.escapeshellarg($archivePath).' '.escapeshellarg($fireholArchive),
        'tar -xzf '.escapeshellarg($fireholArchive),
        'cd '.escapeshellarg('firehol-'.$fireholVersion),
        './configure',
        'make -j6',
        'make install',
    ]));
} finally {
    @unlink($archivePath);
}
