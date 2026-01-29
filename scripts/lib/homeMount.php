<?php
/**
 * Home filesystem mount guard.
 *
 * PMSS requires /home to be a separately mounted filesystem. This guard
 * prevents destructive operations (user listing, nginx config regeneration,
 * user creation/termination) from running when /home is not mounted, which
 * would otherwise produce incorrect results or data loss.
 *
 * Background: When /home is unmounted (e.g., RAID array failure, mount timeout),
 * listUsers.php still returns users from /etc/passwd, but scripts operating on
 * /home find empty directories. createNginxConfig.php would wipe existing
 * per-user configs then produce zero new configs (because home dirs appear
 * missing), causing service downtime until manual intervention.
 *
 * This opinionated check assumes /home is always a separate mount — the
 * standard PMSS deployment model. Deployments using / as the main filesystem
 * (not officially supported) can use loopback mounts or set the environment
 * variable PMSS_SKIP_HOME_MOUNT_CHECK=1 at their own risk.
 *
 * Credit: Chris M. (Canada) for reporting the issue that led to this fix.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */

if (!function_exists('pmssIsHomeMounted')) {
    /**
     * Check whether /home is mounted as a separate filesystem.
     *
     * Reads /proc/mounts and looks for a line where the second field (mountpoint)
     * is exactly "/home". This reliably detects whether /home is its own mount
     * rather than part of the root filesystem.
     *
     * @return bool True if /home is a mounted filesystem, false otherwise.
     */
    function pmssIsHomeMounted(): bool
    {
        // Allow test harnesses to override mount detection.
        $override = getenv('PMSS_HOME_MOUNTED_OVERRIDE');
        if ($override === '1' || $override === 'true') {
            return true;
        }
        if ($override === '0' || $override === 'false') {
            return false;
        }

        $mountsPath = getenv('PMSS_PROC_MOUNTS_PATH');
        if (!is_string($mountsPath) || $mountsPath === '') {
            $mountsPath = '/proc/mounts';
        }

        $mounts = @file_get_contents($mountsPath);
        if ($mounts === false) {
            // If we cannot read /proc/mounts, assume not mounted to be safe.
            return false;
        }

        // /proc/mounts format: device mountpoint fstype options dump pass
        // We look for a line where mountpoint (field 2) is exactly "/home".
        return preg_match('/^\\s*\\S+\\s+\\/home\\s+/m', $mounts) === 1;
    }
}

if (!function_exists('pmssRequireHomeMounted')) {
    /**
     * Abort execution with a clear error if /home is not mounted.
     *
     * This guard should be called early in scripts that operate on /home to
     * prevent destructive actions (wiping configs, modifying user homes) when
     * the filesystem is unavailable. The check runs before any side effects,
     * preserving idempotency.
     *
     * The check can be bypassed by setting PMSS_SKIP_HOME_MOUNT_CHECK=1 in the
     * environment. This is intended for non-standard deployments where /home is
     * part of the root filesystem (loopback mounts, containers, etc.).
     *
     * @param string $context Optional context string for the error message,
     *                        typically the calling script name.
     *
     * @return void Exits with code 1 if /home is not mounted.
     */
    function pmssRequireHomeMounted(string $context = ''): void
    {
        // Allow operators to bypass the check for non-standard deployments.
        $skip = getenv('PMSS_SKIP_HOME_MOUNT_CHECK');
        if ($skip === '1' || strtolower((string)$skip) === 'true') {
            return;
        }

        if (pmssIsHomeMounted()) {
            return;
        }

        // Build a helpful error message.
        $prefix = $context !== '' ? "[{$context}] " : '';
        $message = "{$prefix}Error: /home is not mounted as a separate filesystem.\n";
        $message .= "PMSS requires /home to be mounted. Aborting to prevent data loss.\n";
        $message .= "If this is intentional (non-standard deployment), set PMSS_SKIP_HOME_MOUNT_CHECK=1.\n";

        fwrite(STDERR, $message);
        exit(1);
    }
}
