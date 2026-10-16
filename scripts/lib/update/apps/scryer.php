<?php
/** Install pinned Scryer without executing the downloaded binary. */
require_once __DIR__.'/remoteBinary.php';

$scryerVersion = '0.21.16';
$scryerSha256 = '20b766916bf32aaf473728323a2c9c89718b749f0e4f60197ae1b9bc0dca665d';
$scryerRoot = '/opt/scryer';
$scryerMarker = $scryerRoot.'/pmss-version';
if (!pmssPinnedRemoteAmd64ArtifactsSupported()) {
    logmsg('[SKIP] Scryer bootstrap skipped on unsupported architecture: '.php_uname('m'));
    return;
}
if (is_file($scryerRoot.'/scryer') && !is_link($scryerMarker)
    && trim((string) @file_get_contents($scryerMarker)) === $scryerVersion) return;

$scryerUrl = 'https://github.com/scryer-media/scryer/releases/download/scryer-v'.$scryerVersion.'/scryer-linux-x86_64-portable.tar.gz';
pmssPinnedRemoteArtifactTempFileUse('Scryer '.$scryerVersion, $scryerUrl, $scryerSha256, static function (string $archivePath) use ($scryerRoot, $scryerMarker, $scryerVersion): bool {
    if (!pmssPathTargetIsSafe($scryerRoot, true) || !pmssPathTargetIsSafe($scryerRoot.'/scryer', false)
        || !pmssPathTargetIsSafe($scryerRoot.'/scryer.tmp', false)
        || !pmssPathTargetIsSafe($scryerMarker, false)) return false;
    $command = 'mkdir -p '.escapeshellarg($scryerRoot)
        .' && tar -xzOf '.escapeshellarg($archivePath).' scryer > '.escapeshellarg($scryerRoot.'/scryer.tmp')
        .' && install -o root -g root -m 0755 '.escapeshellarg($scryerRoot.'/scryer.tmp').' '.escapeshellarg($scryerRoot.'/scryer')
        .' && rm -f '.escapeshellarg($scryerRoot.'/scryer.tmp');
    if (runStep('Installing Scryer '.$scryerVersion, $command) !== 0) return false;
    return pmssPathTargetIsSafe($scryerMarker, false, true)
        && @file_put_contents($scryerMarker, $scryerVersion."\n") === strlen($scryerVersion) + 1;
});
