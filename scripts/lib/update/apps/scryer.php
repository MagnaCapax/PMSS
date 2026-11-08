<?php
/** Install pinned Scryer without executing the downloaded binary. */
require_once __DIR__.'/remoteBinary.php';

// The panel route has no panel gate (ADR 0111, 2026-11-08): before bumping, re-run the
// first-run journey through /user-<user>/scryer/; from 0.21.22 a fresh instance asks for an auth choice (#1237).
$scryerVersion = '0.21.17';
$scryerSha256 = '97a3b93e27189b5c42458c25f6c5704d307e7bcc102ae8da44c303ef230de0f5';
$scryerRoot = '/opt/scryer';

$scryerUrl = 'https://github.com/scryer-media/scryer/releases/download/scryer-v'.$scryerVersion.'/scryer-linux-x86_64-portable.tar.gz';
pmssPinnedRemotePortableInstall('Scryer', $scryerUrl, $scryerSha256, $scryerRoot, 'scryer', $scryerVersion);
