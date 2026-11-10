#!/usr/bin/env php
<?php
/**
 * Remove orphan-owned cron spools, including those predating addUser preflight.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/cronSpool.php';

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    exit(pmssCliReturnWithStderr("cronSpoolCleanup requires root.\n"));
}
$lock = pmssCronLockAcquire('cronSpoolCleanup');
$log = static function (array $record): void {
    $record = array_merge(array('timestamp' => date('c'), 'event' => 'cron_spool_cleanup'), $record);
    echo pmssJsonEncodeSafe($record)."\n";
};

// Missing identity infrastructure is not evidence that every spool is orphaned.
$passwd = pmssReadRegularFileContents('/etc/passwd');
if ($passwd === null || preg_match('/(?:^|\n)root:[^:\n]*:0:/', $passwd) !== 1
    || !function_exists('posix_getpwuid') || posix_getpwuid(0) === false) {
    $log(array('status' => 'identity_unavailable'));
    exit(1);
}
$dryRun = in_array('--dry-run', $argv, true) || getenv('PMSS_DRY_RUN') === '1';
$uidLookup = static function (int $uid) use ($passwd) {
    // Preserve local accounts even when NSS cannot resolve a non-root UID.
    return preg_match('/(?:^|\n)[^:\n]+:[^:\n]*:'.$uid.':/', $passwd) === 1
        ? array('uid' => $uid) : posix_getpwuid($uid);
};
exit(pmssUserCronSpoolSweep('/var/spool/cron/crontabs', $log, $uidLookup, $dryRun) ? 0 : 1);
