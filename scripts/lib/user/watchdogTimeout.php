<?php
/** Bounded watchdog work and root-side retry state for one account service. */

require_once dirname(__DIR__).'/runtime.php';
require_once dirname(__DIR__).'/pathSafety.php';
require_once __DIR__.'/identity.php';

/** Tests shorten deadlines without changing production watchdog limits. */
function pmssUserWatchdogTimeoutSeconds(int $seconds): int
{
    $override = getenv('PMSS_WATCHDOG_TIMEOUT_TEST_SECONDS');
    return getenv('PMSS_TEST_MODE') === '1' && is_string($override)
        && ctype_digit($override) && (int) $override > 0 ? (int) $override : $seconds;
}

/** Keep state outside account homes; only simple service names become filenames. */
function pmssUserWatchdogBackoffPath(string $username, string $service): ?string
{
    if (!pmssValidateUsername($username) || preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $service) !== 1) return null;
    $dir = pmssRuntimeDir();
    $root = !function_exists('posix_geteuid') || posix_geteuid() === 0;
    // Root never keeps state in an account-controlled tree; a non-root caller writes with its own UID.
    if ($root && ($dir === '/home' || strpos($dir, '/home/') === 0)) return null;
    if (!pmssPathTargetIsSafe($dir, true) || (!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir)) return null;
    $owner = @fileowner($dir);
    $mode = @fileperms($dir);
    if ($owner !== (function_exists('posix_geteuid') ? posix_geteuid() : 0)
        || !is_int($mode) || ($mode & 0022) !== 0) return null;
    return rtrim($dir, '/').'/watchdog-timeout-'.$username.'-'.$service.'.ts';
}

/** The optional clock makes expiry deterministic in hermetic tests. */
function pmssUserWatchdogBackoffActive(string $username, string $service, ?int $now = null): bool
{
    $path = pmssUserWatchdogBackoffPath($username, $service);
    if ($path === null || !pmssPathTargetIsSafe($path, false, true)) return false;
    $raw = pmssReadRegularFileContentsVerified($path, null, 32);
    return is_string($raw) && ctype_digit($raw)
        && (int) $raw > ($now ?? time()) - 900;
}

/** Record only actual deadline fires and announce the new skip window once. */
function pmssUserWatchdogBackoffRecord(string $username, string $service, ?int $now = null): void
{
    $now = $now ?? time();
    $path = pmssUserWatchdogBackoffPath($username, $service);
    if ($path === null || !pmssPathTargetIsSafe($path, false, true)) return;
    $fresh = pmssUserWatchdogBackoffActive($username, $service, $now);
    $bytes = (string) $now;
    if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) return;
    @chmod($path, 0600);
    if (!$fresh) error_log('Watchdog '.$service.' for '.$username.' timed out; skipping starts and probes for 15 minutes');
}

/** Bound a child and its process group while retaining capped cron output. */
function pmssUserWatchdogCommandRun(string $command, int $seconds, string $username, string $service): array
{
    if (strpos($command, "\0") !== false) return pmssCommandProcessFailure(1, 'unsafe proc_open command');
    $seconds = pmssUserWatchdogTimeoutSeconds($seconds);
    $result = pmssCommandPipedCapture('/bin/bash -c '.escapeshellarg(pmssLockChildClosePrefix().$command),
        $command, $seconds, 1048576, true);
    if ($result['timed_out']) pmssUserWatchdogBackoffRecord($username, $service);
    return $result;
}
