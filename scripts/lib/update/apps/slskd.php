<?php
/** Install the pinned slskd release without executing the downloaded binary. */
require_once __DIR__.'/remoteBinary.php';

$slskdVersion = '0.26.1';
$slskdArchive = 'slskd-'.$slskdVersion.'-linux-x64.zip';
$slskdSha256 = '26c862173c593142db3fcfcd78f0784cfc29987de40a5bd9c2e662ed3b84cce6';
$slskdRoot = '/opt/slskd';
$slskdMarker = $slskdRoot.'/pmss-version';
if (!pmssPinnedRemoteAmd64OrSkip('slskd')) return;
if (is_file($slskdRoot.'/slskd') && !is_link($slskdMarker)
    && trim((string) @file_get_contents($slskdMarker)) === $slskdVersion) return;

$slskdUrl = 'https://github.com/slskd/slskd/releases/download/'.$slskdVersion.'/'.$slskdArchive;
pmssPinnedRemoteArtifactTempFileUse('slskd '.$slskdVersion, $slskdUrl, $slskdSha256, static function (string $archivePath) use ($slskdRoot, $slskdMarker, $slskdVersion): bool {
    if (!pmssPathTargetIsSafe($slskdRoot, true) || !pmssPathTargetIsSafe($slskdMarker, false)) return false;
    $command = 'mkdir -p '.escapeshellarg($slskdRoot)
        .' && unzip -oq '.escapeshellarg($archivePath).' -d '.escapeshellarg($slskdRoot)
        .' && chmod 0755 '.escapeshellarg($slskdRoot.'/slskd');
    if (runStep('Installing slskd '.$slskdVersion, $command) !== 0) return false;
    return pmssPathTargetIsSafe($slskdMarker, false, true)
        && @file_put_contents($slskdMarker, $slskdVersion."\n") === strlen($slskdVersion) + 1;
});
