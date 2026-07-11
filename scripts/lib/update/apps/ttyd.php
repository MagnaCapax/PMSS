<?php
/**
 * Update app installer: ttyd (HTML5 browser console backend, GH #326).
 *
 * Deploys the pinned static ttyd binary that backs the per-user web console
 * (etc/skel/www/console.php + the /console/ reverse-proxy block in
 * template.lighttpd). ttyd is a single static C binary with zero runtime
 * dependencies; at spawn time it runs as the customer UID — the same
 * privilege the customer already holds over SSH. This installer only places
 * the binary system-wide; it starts no daemon.
 *
 * Auto-loaded by update-step2.php's apps/*.php glob (no manual registration).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/remoteBinary.php';

$ttydVersion = '1.7.7';
$ttydSha256  = '8a217c968aba172e0dbf3f34447218dc015bc4d5e59bf51db2f2cd12b7be4f55';
$ttydUrl     = 'https://github.com/tsl0922/ttyd/releases/download/'.$ttydVersion.'/ttyd.x86_64';

// Static amd64 artifact only; skip cleanly elsewhere.
if (!pmssPinnedRemoteAmd64ArtifactsSupported()) {
    logmsg('[SKIP] ttyd install skipped on unsupported architecture: '.php_uname('m'));
    return;
}

// Idempotent: nothing to do when the pinned version is already in place.
if (file_exists('/usr/bin/ttyd')
    && pmssAppVersionProbeMatch(['/usr/bin/ttyd --version 2>/dev/null'], '/'.preg_quote($ttydVersion, '/').'/') !== null) {
    return;
}

// Replace any stale/older copy before installing the pinned version.
if (file_exists('/usr/bin/ttyd') || is_link('/usr/bin/ttyd')) {
    @unlink('/usr/bin/ttyd');
}

$tmp = pmssFetchPinnedRemoteFile('ttyd '.$ttydVersion, $ttydUrl, $ttydSha256);
if ($tmp === null) {
    return; // download/checksum failure already logged; dry-run returns null too
}

echo "*** ttyd not present (or stale), installing {$ttydVersion}!\n";
runStep('Installing ttyd '.$ttydVersion.' binary',
    pmssBuildCommand('install', ['-m', '0755', '-o', 'root', '-g', 'root', $tmp, '/usr/bin/ttyd']));
@unlink($tmp);
