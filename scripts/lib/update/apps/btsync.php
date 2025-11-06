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
 */
// #TODO Migrate to dpkg baseline/repo-driven install; verify downloads and
//      refactor to runStep wrappers for consistent JSON logging.

if (!file_exists('/usr/bin/btsync1.4')) {
    // #TODO Switch to HTTPS and checksum validation.
    echo "*** BTSync 1.4 not present, downloading and adding!\n";
    passthru("wget http://pulsedmedia.com/remote/pkg/btsync -O /usr/bin/btsync1.4; chmod 755 /usr/bin/btsync1.4");
}

if (!file_exists('/usr/bin/btsync2.2')) {
    // #TODO Switch to HTTPS and checksum validation.
    echo "*** BTSync 2.2 not present, downloading and adding!\n";
    passthru("wget http://pulsedmedia.com/remote/pkg/btsync2.2 -O /usr/bin/btsync2.2; chmod 755 /usr/bin/btsync2.2");
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
    passthru('ln -s /usr/bin/btsync2.2 /usr/bin/btsync');
}


// Install Resilio Sync if required.
$rslsyncBinary   = '/usr/bin/rslsync';
$rslsyncExpected = 'Resilio Sync 2.7.3 (1381)';
$rslsyncOutput   = trim((string) @shell_exec($rslsyncBinary.' --help 2>/dev/null'));

if ($rslsyncOutput === '' || strpos($rslsyncOutput, $rslsyncExpected) === false) {
    if (file_exists($rslsyncBinary)) {
        echo "*** Resilio Sync binary out of date; refreshing {$rslsyncBinary}\n";
    } else {
        echo "*** Resilio Sync not present, downloading package\n";
    }
    // #TODO Switch to HTTPS and checksum validation; prefer managed packaging.
    passthru("wget http://pulsedmedia.com/remote/pkg/rslsync -O {$rslsyncBinary}; chmod 755 {$rslsyncBinary}");
} else {
    echo "*** Resilio Sync already at target version ({$rslsyncExpected}); skipping download\n";
}
