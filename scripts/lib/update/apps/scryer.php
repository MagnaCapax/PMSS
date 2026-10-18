<?php
/** Install pinned Scryer without executing the downloaded binary. */
require_once __DIR__.'/remoteBinary.php';

$scryerVersion = '0.21.17';
$scryerSha256 = '97a3b93e27189b5c42458c25f6c5704d307e7bcc102ae8da44c303ef230de0f5';
$scryerRoot = '/opt/scryer';
if (!pmssPinnedRemoteAmd64OrSkip('Scryer')) return;
if (pmssPinnedRemoteVersionMatches($scryerRoot.'/scryer', $scryerRoot.'/pmss-version', $scryerVersion)) return;

$scryerUrl = 'https://github.com/scryer-media/scryer/releases/download/scryer-v'.$scryerVersion.'/scryer-linux-x86_64-portable.tar.gz';
pmssPinnedRemotePortableTarInstall('Scryer', $scryerUrl, $scryerSha256, $scryerRoot, 'scryer', $scryerVersion);
