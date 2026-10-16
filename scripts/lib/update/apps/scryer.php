<?php
/** Install pinned Scryer without executing the downloaded binary. */
require_once __DIR__.'/remoteBinary.php';

$scryerVersion = '0.21.16';
$scryerSha256 = '20b766916bf32aaf473728323a2c9c89718b749f0e4f60197ae1b9bc0dca665d';
$scryerRoot = '/opt/scryer';
if (!pmssPinnedRemoteAmd64OrSkip('Scryer')) return;
if (pmssPinnedRemoteVersionMatches($scryerRoot.'/scryer', $scryerRoot.'/pmss-version', $scryerVersion)) return;

$scryerUrl = 'https://github.com/scryer-media/scryer/releases/download/scryer-v'.$scryerVersion.'/scryer-linux-x86_64-portable.tar.gz';
pmssPinnedRemotePortableTarInstall('Scryer', $scryerUrl, $scryerSha256, $scryerRoot, 'scryer', $scryerVersion);
