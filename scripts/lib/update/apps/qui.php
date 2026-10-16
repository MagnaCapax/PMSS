<?php
/** Install pinned qui without executing the downloaded binary. */
require_once __DIR__.'/remoteBinary.php';

$quiVersion = '1.32.0';
$quiSha256 = 'acead415955af1387e8939392cca3978799c01022904858a2156d8c3f7fcbf93';
$quiRoot = '/opt/qui';
$quiMarker = $quiRoot.'/pmss-version';
if (!pmssPinnedRemoteAmd64ArtifactsSupported()) {
    logmsg('[SKIP] qui bootstrap skipped on unsupported architecture: '.php_uname('m'));
    return;
}
if (is_file($quiRoot.'/qui') && !is_link($quiMarker)
    && trim((string) @file_get_contents($quiMarker)) === $quiVersion) return;

$quiUrl = 'https://github.com/autobrr/qui/releases/download/v'.$quiVersion.'/qui_'.$quiVersion.'_linux_x86_64.tar.gz';
pmssPinnedRemoteArtifactTempFileUse('qui '.$quiVersion, $quiUrl, $quiSha256, static function (string $archivePath) use ($quiRoot, $quiMarker, $quiVersion): bool {
    if (!pmssPathTargetIsSafe($quiRoot, true) || !pmssPathTargetIsSafe($quiRoot.'/qui', false)
        || !pmssPathTargetIsSafe($quiRoot.'/qui.tmp', false)
        || !pmssPathTargetIsSafe($quiMarker, false)) return false;
    $command = 'mkdir -p '.escapeshellarg($quiRoot)
        .' && tar -xzOf '.escapeshellarg($archivePath).' qui > '.escapeshellarg($quiRoot.'/qui.tmp')
        .' && install -o root -g root -m 0755 '.escapeshellarg($quiRoot.'/qui.tmp').' '.escapeshellarg($quiRoot.'/qui')
        .' && rm -f '.escapeshellarg($quiRoot.'/qui.tmp');
    if (runStep('Installing qui '.$quiVersion, $command) !== 0) return false;
    return pmssPathTargetIsSafe($quiMarker, false, true)
        && @file_put_contents($quiMarker, $quiVersion."\n") === strlen($quiVersion) + 1;
});
