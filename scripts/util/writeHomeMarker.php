#!/usr/bin/env php
<?php
/**
 * Write a per-user home marker file safely, as root, without following symlinks.
 *
 * Provisioning/enforcement callers (including the off-repo hallinta backend) must
 * NOT write markers into a tenant home with raw shell (`echo > ~/.x; chown u.u; chmod`):
 * `chown`, the `>` redirect, and `chmod` all dereference symlinks by default, so a
 * tenant (whose home is 0770 user-owned) can plant `~/.marker -> /etc/shadow` and have
 * root chown/write the target — a local-root escalation. This CLI routes the write
 * through the symlink-safe managed-file primitive (tempnam-in-dir + atomic rename that
 * replaces a planted symlink, is_link guards, root-only owner/mode).
 *
 * Usage: writeHomeMarker.php <user> <marker> <intValue>
 *   <marker> is a bare filename from the fixed allowlist below (no path, no leading dot
 *   choice — the allowlist carries the exact name). <intValue> is a non-negative integer;
 *   these markers only ever hold a single scalar integer.
 *
 * Owner/mode per marker follows ADR-0046 (Provisioned in-home file ownership classes):
 * enforcement/authoritative markers are root-owned, group=user for the required read
 * channel, no user/other write. Until the ADR-0046 registry lands, the table lives here.
 *
 * Exit codes: 0 ok; 1 usage; 2 bad user; 3 unknown marker; 4 bad value; 5 unsafe home;
 *             6 not root; 7 write failed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/user/identity.php';
require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';
require_once __DIR__.'/../lib/user/homeMarkerRegistry.php';
require_once __DIR__.'/../lib/runtime/cli.php';

function pmssWriteHomeMarkerCli(array $argv): int
{
    $usage = "Usage: writeHomeMarker.php <user> <marker> <intValue>\n";
    if (count($argv) !== 4) {
        return pmssCliReturnWithStderr($usage, 1);
    }

    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        return pmssCliReturnWithStderr("Error: writeHomeMarker.php must run as root.\n", 6);
    }

    $user = pmssUsernameNormalizeIfValid((string) $argv[1]);
    if ($user === null) {
        return pmssCliReturnWithStderr("Error: invalid username.\n", 2);
    }

    $marker = (string) $argv[2];
    if (!pmssHomeMarkerIsKnown($marker)) {
        return pmssCliReturnWithStderr("Error: unknown marker '{$marker}'.\n", 3);
    }
    $mode = pmssHomeMarkerMode($marker);

    $value = pmssHomeMarkerValueParse((string) $argv[3]);
    if ($value === null) {
        return pmssCliReturnWithStderr("Error: value must be a non-negative integer.\n", 4);
    }

    // The home must be a real directory whose path cannot redirect the root write.
    $home = '/home/'.$user;
    if (!is_dir($home) || is_link($home) || @realpath($home) !== $home) {
        return pmssCliReturnWithStderr("Error: home for '{$user}' is missing or unsafe.\n", 5);
    }

    // Symlink-safe atomic write with root:user ownership and the ADR-0046 mode.
    $path = $home.'/'.$marker;
    if (!pmssWriteManagedFile($path, (string) $value, 'root', $user, $mode)) {
        return pmssCliReturnWithStderr("Error: failed to write {$path}.\n", 7);
    }

    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, sprintf('writeHomeMarker: %s set to %d (root:%s %04o)', $marker, $value, $user, $mode));
    }
    return 0;
}

exit(pmssWriteHomeMarkerCli($argv));
