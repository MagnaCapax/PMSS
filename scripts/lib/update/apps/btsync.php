<?php
/**
 * BTSync/Resilio bootstrap helper.
 *
 * - Ensures the legacy BTSync 1.4 and 2.2 binaries remain available
 *   under predictable paths and preserves any pre-existing binaries.
 * - Maintains the rslsync binary at the pinned version shipped by Pulsed Media.
 *
 * This workflow has been stable for years—avoid modifications unless the
 * service itself changes. Coordinate updates with the platform team first.
 *
 * @author  Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
// #TODO Migrate to dpkg baseline/repo-driven install. (GH #131)

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/remoteBinary.php';

$arch   = php_uname('m');

if ($arch !== 'x86_64' && $arch !== 'amd64') {
    if (function_exists('logmsg')) {
        logmsg("[SKIP] btsync/rslsync bootstrap skipped on unsupported architecture: {$arch}");
    } else {
        echo "*** btsync/rslsync bootstrap skipped on unsupported architecture: {$arch}\n";
    }
    return;
}

$legacyBinaries = [
    [
        'label'   => 'BTSync 1.4',
        'version' => '1.4',
        'url'     => 'https://pulsedmedia.com/remote/pkg/btsync',
        'sha256'  => '7f7cdd367b90c857427cf2ec849061cafc4933af5c4cf8b5a38412c755043332',
        'path'    => '/usr/bin/btsync1.4',
    ],
    [
        'label'   => 'BTSync 2.2',
        'version' => '2.2',
        'url'     => 'https://pulsedmedia.com/remote/pkg/btsync2.2',
        'sha256'  => '30b4b9d3d2b27a4c9800dcebc14df7db13b4979fcd64fc66c49aff0c8c1cb26d',
        'path'    => '/usr/bin/btsync2.2',
    ],
];
foreach ($legacyBinaries as $legacy) {
    if (!file_exists($legacy['path'])) {
        if (function_exists('logmsg')) {
            logmsg("*** {$legacy['label']} not present, downloading and adding!");
        } else {
            echo "*** {$legacy['label']} not present, downloading and adding!\n";
        }
        pmssInstallPinnedRemoteBinary($legacy['label'], $legacy['url'], $legacy['sha256'], $legacy['path'], false);
    }
}

$btsyncPath = '/usr/bin/btsync';
if (is_link($btsyncPath) && readlink($btsyncPath) !== '/usr/bin/btsync2.2') {
    // Suppress race-condition warnings if path disappears between checks
    @unlink($btsyncPath);
}

if (file_exists($btsyncPath) && !is_link($btsyncPath)) {
    $backup = $btsyncPath.'.legacy';
    if (@rename($btsyncPath, $backup)) {
        echo "Legacy btsync preserved at {$backup}\n";
    } else {
        echo "Warning: unable to back up existing btsync binary\n";
    }
}

if (!file_exists($btsyncPath)) {
    runStep('Linking btsync shim', pmssBuildCommand('ln', ['-s', '/usr/bin/btsync2.2', '/usr/bin/btsync']));
}


// Install Resilio Sync if required.
$rslsyncBinary   = '/usr/bin/rslsync';
$rslsyncUrl      = 'https://pulsedmedia.com/remote/pkg/rslsync';
$rslsyncSha256   = 'f8c71f6d447a2a9aec93bde7c316bbb7ac6be98d0bcb9dc645f4ca4e347bc333';

if (is_file($rslsyncBinary)) {
    $installedSha = @hash_file('sha256', $rslsyncBinary);
    if (is_string($installedSha) && strtolower($installedSha) === strtolower($rslsyncSha256)) {
        if (function_exists('logmsg')) {
            logmsg('*** Resilio Sync already matches pinned checksum; skipping download');
        } else {
            echo "*** Resilio Sync already matches pinned checksum; skipping download\n";
        }
        return;
    }
}

if (function_exists('logmsg')) {
    logmsg('*** Resilio Sync missing/out of date; refreshing rslsync binary');
} else {
    echo "*** Resilio Sync missing/out of date; refreshing rslsync binary\n";
}

pmssInstallPinnedRemoteBinary('Resilio Sync', $rslsyncUrl, $rslsyncSha256, $rslsyncBinary, true);
