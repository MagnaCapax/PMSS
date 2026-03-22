<?php
/**
 * Compile iprange after the package phase completes.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/packages/helpers.php';
require_once __DIR__.'/remoteBinary.php';

$iprangeVersion = '1.0.4';
$iprangeArchive = 'iprange-'.$iprangeVersion.'.tar.xz';
$iprangeUrl = 'https://github.com/firehol/iprange/releases/download/v'.$iprangeVersion.'/'.$iprangeArchive;
$iprangeSha256 = 'f21aed906da1bd8e4586f981421f79c406a7c8c2efbcf15f268f7d9176148be6';

if (empty($GLOBALS['PMSS_PACKAGES_READY'])) {
    logmsg('[WARN] Skipping iprange build: package phase not complete');
    return;
}

if (file_exists('/usr/local/bin/iprange')) {
    return;
}

$missing = array_filter(['build-essential', 'gcc', 'make', 'gawk'], static function (string $pkg): bool {
    return pmssPackageStatus($pkg) !== 'install ok installed';
});

if (!empty($missing)) {
    logmsg('[WARN] Skipping iprange build: missing toolchain packages '.implode(', ', $missing));
    return;
}

$archivePath = pmssFetchPinnedRemoteFile('iprange '.$iprangeVersion.' source', $iprangeUrl, $iprangeSha256);
if (!is_string($archivePath) || $archivePath === '') {
    return;
}

try {
    runStep('Building iprange from source', implode(' && ', [
        'set -e',
        'mkdir -p /root/compile',
        'cd /root/compile',
        'rm -rf '.escapeshellarg('iprange-'.$iprangeVersion).' '.escapeshellarg($iprangeArchive),
        'cp '.escapeshellarg($archivePath).' '.escapeshellarg($iprangeArchive),
        'tar -xJf '.escapeshellarg($iprangeArchive),
        'cd '.escapeshellarg('iprange-'.$iprangeVersion),
        './configure',
        'make -j6',
        'make install'
    ]));
} finally {
    @unlink($archivePath);
}
