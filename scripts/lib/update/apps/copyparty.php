<?php
/** Install the pinned Copyparty single-file release without version probing. */
require_once __DIR__.'/remoteBinary.php';

$copypartyVersion = '1.20.26';
$copypartySha256 = '8f9a0def8a9c15252a06395781ec083372043b60caa80fb33c3d6dafa7f1b40a';
$copypartyBinary = '/usr/local/bin/copyparty-sfx.py';
$copypartyMarker = '/usr/local/share/pmss/copyparty-version';
$copypartyUrl = 'https://github.com/9001/copyparty/releases/download/v'.$copypartyVersion.'/copyparty-sfx.py';
if (!pmssPinnedRemoteAmd64OrSkip('Copyparty')) return;
if (pmssPinnedRemoteChecksum($copypartyBinary) !== $copypartySha256) {
    pmssInstallPinnedRemoteBinary('Copyparty '.$copypartyVersion, $copypartyUrl, $copypartySha256, $copypartyBinary, true);
}
if (pmssPinnedRemoteChecksum($copypartyBinary) === $copypartySha256) {
    if (runStep('Preparing Copyparty version marker directory', 'mkdir -p '.escapeshellarg(dirname($copypartyMarker))) === 0) {
        pmssPinnedRemoteVersionMarkerWrite($copypartyMarker, $copypartyVersion);
    }
}
