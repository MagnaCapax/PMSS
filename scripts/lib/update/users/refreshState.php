<?php
/** Same-version resume markers; loaded by the userMaintenance.php facade. */

/**
 * Signature a user is refreshed against for same-version resume skips.
 */
function pmssUserRefreshSignature(string $rutorrentIndexSha, string $projectionPath = '/var/lib/pmss/public/torrentClients.json'): string
{
    $version = trim((string) @file_get_contents('/etc/seedbox/config/version'));
    // A same-version run that installs or removes torrent client builds must still publish each account's links.
    $projection = is_file($projectionPath) && !is_link($projectionPath) && (int) @filesize($projectionPath) <= 65536
        ? sha1((string) @file_get_contents($projectionPath)) : '';
    return sha1($version.'|'.$rutorrentIndexSha.'|'.$projection);
}

/** Marker path for a username when the boundary inputs are safe. */
function pmssUserRefreshMarkerPath(string $user): string
{
    $dir = pmssResolvePathFromEnv('PMSS_USER_REFRESH_STATE_DIR', '/var/lib/pmss/user-refresh');
    if (!pmssValidateUsername($user) || !pmssPathTargetIsSafe($dir, true, false, false)) {
        return '';
    }

    // Check the marker leaf too: a symlink must neither authorize a resume
    // skip nor redirect the completion write outside the state directory.
    $path = $dir.'/'.$user;
    return pmssPathTargetIsSafe($path, false, false, false) ? $path : '';
}

/** True when the user was already fully refreshed against this signature. */
function pmssUserRefreshAlreadyDone(string $user, string $signature): bool
{
    $path = pmssUserRefreshMarkerPath($user);
    return $path !== '' && is_file($path) && trim((string) @file_get_contents($path)) === $signature;
}

/** Record that the user is fully refreshed against this signature. */
function pmssUserRefreshMarkDone(string $user, string $signature, ?callable $writer = null): void
{
    $path = pmssUserRefreshMarkerPath($user);
    if ($path === '') {
        logMessage('[WARN] Refusing to write unsafe user refresh marker for '.pmssLogSafeLabel($user));
        return;
    }

    $dir = dirname($path);
    if (!pmssDirEnsureExists($dir, 0755)) {
        logMessage('[WARN] Unable to create user refresh state directory: '.$dir);
        return;
    }
    $payload = $signature."\n";
    if (!pmssFileWriteComplete($path, $payload, 0, $writer)) {
        logMessage('[WARN] Unable to write user refresh marker: '.$path);
    }
}
