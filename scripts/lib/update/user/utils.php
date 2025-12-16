<?php
/**
 * Shared utilities for user update helpers.
 */

require_once __DIR__.'/../../runtime.php';

function pmssUserSkelBase(): string { return pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel'); }

function pmssUserSkelPath(string $relative): string { return pmssUserSkelBase().'/'.$relative; }

/**
 * Return a shell-ready argument for a skel path.
 *
 * Keep legacy command strings stable: when PMSS_SKEL_DIR is the default
 * `/etc/skel`, older scripts historically passed the path unquoted in the
 * generated `cp` command. When overridden, we must escape the custom path.
 */
function pmssUserSkelCommandArg(string $relative): string
{
    $path = pmssUserSkelPath($relative);
    return $path === '/etc/skel/'.$relative ? $path : escapeshellarg($path);
}
