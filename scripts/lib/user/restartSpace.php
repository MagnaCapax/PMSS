<?php
/**
 * Protect running account services when their filesystem cannot admit a new start.
 *
 * @license GPL-3.0-only
 */

const PMSS_ACCOUNT_RESTART_MIN_FREE_BYTES = 67108864;

/** A failed space probe is unsafe for a destructive, optional restart. */
function pmssAccountRestartSpaceAvailable(string $home, ?callable $measure = null): bool
{
    $bytes = $measure === null ? @disk_free_space($home) : $measure($home);
    return is_numeric($bytes) && (float) $bytes >= PMSS_ACCOUNT_RESTART_MIN_FREE_BYTES;
}

/** Log once at the decision point, before any running service is stopped. */
function pmssAccountRestartSpaceAllows(string $user, string $home, string $reason, callable $warn, ?callable $measure = null): bool
{
    if (pmssAccountRestartSpaceAvailable($home, $measure)) return true;
    $warn('[WARN] Account '.$user.': keeping running service; '.$reason.' deferred because user-available home space is below 64 MiB or unknown');
    return false;
}
