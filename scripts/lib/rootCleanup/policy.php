<?php
/** Root cleanup path policy; every deletion retains its existing guard.
 * @license GPL-3.0-only
 */

/**
 * Absolute prefixes a deletable FILE must realpath-resolve under (the file-unlink gate).
 * NOTE: /var/tmp is deliberately NOT here — dist-upgrade scratch is handled by its own
 * dedicated prefix predicate (pmssSafeRootCleanupIsDistUpgradeTmp) + rm -rf, never this
 * per-file gate, so the gate cannot over-allow arbitrary /var/tmp files.
 */
function pmssSafeRootCleanupAllowedPrefixes(): array
{
    return [
        '/var/cache/apt/archives/',
        '/var/lib/apt/lists/partial/',
        '/var/log/',
    ];
}

/** Absolute prefixes that are NEVER touched (defense in depth; wins over allow). */
function pmssSafeRootCleanupDenyPrefixes(): array
{
    return [
        '/home/', '/var/www/', '/etc/', '/root/', '/boot/',
        '/dev/', '/proc/', '/sys/', '/var/lib/mysql/', '/var/spool/',
    ];
}

/** Live log basenames that must NEVER be deleted — only their ROTATED siblings. */
function pmssSafeRootCleanupLiveLogBasenames(): array
{
    return [
        'syslog', 'kern.log', 'messages', 'auth.log', 'daemon.log', 'user.log',
        'debug', 'mail.log', 'cron.log', 'dpkg.log', 'faillog', 'lastlog',
        'wtmp', 'btmp', 'alternatives.log', 'bootstrap.log',
    ];
}

/** A rotated/regenerable log? *.gz, *.N, *.N.gz, *.old — never a live basename. */
function pmssSafeRootCleanupIsRotatedLog(string $path): bool
{
    $base = basename($path);
    return !in_array($base, pmssSafeRootCleanupLiveLogBasenames(), true)
        && (bool) preg_match('/(?:\.\d+)(?:\.gz)?$|\.gz$|\.old$/', $base);
}

/**
 * THE safety boundary (PURE — no filesystem access, unit-tested directly).
 * Input MUST be an absolute, already-resolved path. deny-prefix → allow-prefix →
 * for /var/log require ROTATED (or the atop_* dated-log class). deny wins over allow.
 */
function pmssSafeRootCleanupPathIsWhitelisted(string $real): bool
{
    if ($real === '' || $real[0] !== '/') {
        return false;
    }
    foreach (pmssSafeRootCleanupDenyPrefixes() as $deny) {
        if (strncmp($real, $deny, strlen($deny)) === 0) {
            return false;
        }
    }
    foreach (pmssSafeRootCleanupAllowedPrefixes() as $allow) {
        if (strncmp($real, $allow, strlen($allow)) !== 0) {
            continue;
        }
        // Logs under /var/log must be ROTATED (never a live log), EXCEPT atop_* which is
        // a distinct dated-log class. Non-log allow prefixes need no log-name check.
        return strncmp($real, '/var/log/', 9) !== 0
            || strncmp($real, '/var/log/atop/atop_', 19) === 0
            || pmssSafeRootCleanupIsRotatedLog($real);
    }
    return false;
}

/**
 * May this exact path be unlinked as regenerable cruft? realpath (must exist + be a
 * regular file) → the pure whitelist predicate. This is the gate every unlink passes.
 */
function pmssSafeRootCleanupIsDeletableFile(string $path): bool
{
    $real = @realpath($path);
    return $real !== false && is_file($real) && pmssSafeRootCleanupPathIsWhitelisted($real);
}

/** A /var/tmp/mkinitramfs_* scratch dir/file (dist-upgrade leftover)? */
function pmssSafeRootCleanupIsDistUpgradeTmp(string $path): bool
{
    $real = @realpath($path);
    return $real !== false && strncmp($real, '/var/tmp/mkinitramfs_', 21) === 0;
}
