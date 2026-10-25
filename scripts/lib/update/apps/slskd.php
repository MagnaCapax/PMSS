<?php
/** Install the pinned slskd release without executing the downloaded binary. */
require_once __DIR__.'/remoteBinary.php';

$slskdVersion = '0.26.1';
$slskdArchive = 'slskd-'.$slskdVersion.'-linux-x64.zip';
$slskdSha256 = '26c862173c593142db3fcfcd78f0784cfc29987de40a5bd9c2e662ed3b84cce6';
$slskdRoot = '/opt/slskd';

$slskdUrl = 'https://github.com/slskd/slskd/releases/download/'.$slskdVersion.'/'.$slskdArchive;
pmssPinnedRemotePortableInstall('slskd', $slskdUrl, $slskdSha256, $slskdRoot, 'slskd', $slskdVersion, true);
