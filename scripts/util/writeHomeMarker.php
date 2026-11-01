#!/usr/bin/env php
<?php
/**
 * Record one per-user value: first in the root store, then as a display copy in the home.
 *
 * Root jobs read the value only from the root store (/etc/seedbox/config/users/<user>.json,
 * ADR 0213). The file in the home is written afterwards so the account's panel and tools can
 * show it, through the managed-file primitive (atomic replace, root owner, user group).
 * If the store write fails, nothing is written to the home and the exit code is non-zero.
 *
 * Usage: writeHomeMarker.php <user> <marker> <intValue>
 *   <marker> is a bare filename from homeMarkerRegistry.php. <intValue> is a non-negative integer.
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
require_once __DIR__.'/../lib/user/userConfigStore.php';
require_once __DIR__.'/../lib/user/log.php';

/** Parse a marker value by its registry entry; null when invalid. */
function pmssWriteHomeMarkerValueParse(array $entry, string $raw): ?int
{
    $value = pmssHomeMarkerValueParse($raw);
    return ($value !== null && $value <= ($entry['max'] ?? PHP_INT_MAX)) ? $value : null;
}

function pmssWriteHomeMarkerCli(array $argv, ?UserConfigStore $store = null): int
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
    $entry = pmssHomeMarkerRegistry()[$marker];

    $value = pmssWriteHomeMarkerValueParse($entry, (string) $argv[3]);
    if ($value === null) {
        return pmssCliReturnWithStderr("Error: invalid value for {$marker}.\n", 4);
    }

    // The home must be a real directory whose path cannot redirect the root write.
    $home = '/home/'.$user;
    if (!is_dir($home) || is_link($home) || @realpath($home) !== $home) {
        return pmssCliReturnWithStderr("Error: home for '{$user}' is missing or unsafe.\n", 5);
    }

    if ($entry['store'] !== null && !($store ?? new UserConfigStore())->setValue($user, $entry['store'], $value)) {
        pmssUserLog($user, sprintf('writeHomeMarker: store write for %s failed; display copy not written', $marker));
        return pmssCliReturnWithStderr("Error: failed to record {$entry['store']} in the user store.\n", 7);
    }

    $path = $home.'/'.$marker;
    if (!pmssWriteManagedFile($path, (string) $value, 'root', $user, (int) $entry['mode'])) {
        return pmssCliReturnWithStderr("Error: failed to write {$path}.\n", 7);
    }

    pmssUserLog($user, sprintf('writeHomeMarker: %s set to %d (root:%s %04o)', $marker, $value, $user, $entry['mode']));
    return 0;
}

if (!defined('PMSS_TEST_MODE') || !PMSS_TEST_MODE) exit(pmssWriteHomeMarkerCli($argv));
