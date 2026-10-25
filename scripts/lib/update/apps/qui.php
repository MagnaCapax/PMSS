<?php
/** Install pinned qui without executing the downloaded binary. */
require_once __DIR__.'/remoteBinary.php';

$quiVersion = '1.32.0';
$quiSha256 = 'acead415955af1387e8939392cca3978799c01022904858a2156d8c3f7fcbf93';
$quiRoot = '/opt/qui';

$quiUrl = 'https://github.com/autobrr/qui/releases/download/v'.$quiVersion.'/qui_'.$quiVersion.'_linux_x86_64.tar.gz';
pmssPinnedRemotePortableInstall('qui', $quiUrl, $quiSha256, $quiRoot, 'qui', $quiVersion);
