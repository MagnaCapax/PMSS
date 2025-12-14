<?php
/**
 * Shared utilities for user update helpers.
 */

require_once __DIR__.'/../../runtime.php';

function pmssUserSkelBase(): string
{
    return pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel');
}

function pmssUserSkelPath(string $relative): string
{
    return pmssUserSkelBase().'/'.$relative;
}
