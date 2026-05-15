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
pmssRunPinnedRemoteArchiveStep('FireHOL '.$fireholVersion.' source', $fireholUrl, $fireholSha256, $fireholArchive, 'firehol-'.$fireholVersion, 'Building FireHOL from source', [
    'cd '.escapeshellarg('firehol-'.$fireholVersion), './configure', 'make -j6', 'make install',
]);
