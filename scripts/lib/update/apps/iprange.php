<?php
/**
 * Compile iprange after the package phase completes.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../packageState.php';
require_once __DIR__.'/remoteBinary.php';

$iprangeVersion = '1.0.4';
$iprangeArchive = 'iprange-'.$iprangeVersion.'.tar.xz';
$iprangeUrl = 'https://github.com/firehol/iprange/releases/download/v'.$iprangeVersion.'/'.$iprangeArchive;
$iprangeSha256 = 'e138d18974fb65674e5ea53da1ffbdde19704208e5114cc6b32d01db3ce1d567';

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

pmssRunPinnedRemoteArchiveStep('iprange '.$iprangeVersion.' source', $iprangeUrl, $iprangeSha256, $iprangeArchive, 'iprange-'.$iprangeVersion, 'Building iprange from source', [
    'cd '.escapeshellarg('iprange-'.$iprangeVersion), './configure', 'make -j6', 'make install',
]);
