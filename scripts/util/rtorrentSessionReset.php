#!/usr/bin/env php
<?php
/**
 * Quarantine one account's rTorrent session through the shared recovery helper.
 *
 * @license GPL-3.0-only
 */

// Load trusted dependencies before dropping access to the operator-only tree.
require_once __DIR__.'/../lib/user/identity.php';
require_once __DIR__.'/../lib/rtorrent/recovery.php';

/** Reset only the named account's session, with all mutations under its UID. */
function pmssRtorrentSessionResetCli(array $argv): int
{
    if (count($argv) !== 2 || !is_string($argv[1]) || !pmssValidateUsername($argv[1])) {
        return pmssCliReturnWithStderr("Usage: rtorrentSessionReset.php USERNAME\n");
    }
    foreach (['posix_geteuid', 'posix_getpwnam', 'posix_initgroups', 'posix_setgid', 'posix_setuid'] as $function) {
        if (!function_exists($function)) {
            return pmssCliReturnWithStderr("Session reset requires the POSIX extension\n");
        }
    }
    if (posix_geteuid() !== 0) {
        return pmssCliReturnWithStderr("Session reset must be invoked as root\n");
    }

    $user = $argv[1];
    $home = '/home/'.$user;
    $account = posix_getpwnam($user);
    $uid = pmssPasswdEntryPositiveUid($account);
    if ($uid === null || !isset($account['gid']) || ($account['dir'] ?? '') !== $home) {
        return pmssCliReturnWithStderr("Missing or unsafe account identity\n");
    }

    // ADR 0121: never rename or create account-controlled paths as root.
    if (!posix_initgroups($user, (int) $account['gid'])
        || !posix_setgid((int) $account['gid'])
        || !posix_setuid($uid)
        || posix_geteuid() !== $uid) {
        return pmssCliReturnWithStderr("Unable to assume account identity\n");
    }

    $log = static function (string $message, bool $force): void {
        fwrite(STDERR, $message."\n");
    };
    if (!rtorrentProcessResetSessionDirectory($home, $user, $log)) {
        return pmssCliReturnWithStderr("Session reset failed\n");
    }
    fwrite(STDOUT, "Session reset complete\n");
    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssRtorrentSessionResetCli');
